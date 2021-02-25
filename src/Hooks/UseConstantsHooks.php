<?php declare(strict_types=1);

namespace Orklah\UseConstants\Hooks;

use PhpParser\Node\Scalar\String_;
use Psalm\FileManipulation;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Plugin\EventHandler\AfterClassLikeVisitInterface;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeVisitEvent;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use function count;
use function in_array;
use function strlen;


class UseConstantsHooks implements AfterExpressionAnalysisInterface, AfterClassLikeVisitInterface
{
    private static $list_values = [];
    private static $forbidden_list = [];

    //placeholder for config. PR Psalm to add a Json in plugin tag in xml file?
    private static $exception_list = [
        'class' => [],
        'constant' => [],
        'fqn' => [],
        'literal_value' => [],
    ];

    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        global $argv; // please look away but I need this to ensure this plugin was called without cache

        if (!in_array('--no-cache', $argv, true)) {
            die('Please run orklah/psalm-use-constants with --no-cache to ensure correct collect of constants');
        }

        if (!$event->getCodebase()->alter_code) {
            return true;
        }

        $original_expr = $event->getExpr();
        if (!$original_expr instanceof String_) {
            return true;
        }

        $literal_value = $original_expr->value;

        if (!isset(self::$list_values[$literal_value])) {
            return true;
        }

        [$classlike_name, $constant_name, $file_name, $constant_line] = self::$list_values[$original_expr->value];
        if($original_expr->getLine() === $constant_line && $event->getStatementsSource()->getFileName() === $file_name){
            //if the literal is on the same file and same line, don't replace
            return true;
        }

        $fqn = $classlike_name . '::' . $constant_name;

        if (in_array($classlike_name, self::$exception_list['class'], true) ||
            in_array($constant_name, self::$exception_list['constant'], true) ||
            in_array($fqn, self::$exception_list['fqn'], true) ||
            in_array($literal_value, self::$exception_list['literal_value'], true)
        ) {
            return true;
        }

        $startPos = $original_expr->getStartFilePos();
        $endPos = $original_expr->getEndFilePos() + 1;
        $file_manipulation = new FileManipulation($startPos, $endPos, $classlike_name . '::' . $constant_name);
        $event->setFileReplacements([$file_manipulation]);

        return true;
    }

    public static function afterClassLikeVisit(AfterClassLikeVisitEvent $event)
    {
        $classlike_storage = $event->getStorage();
        foreach ($classlike_storage->constants as $constant_name => $constant) {
            if ($constant->visibility !== ClassLikeAnalyzer::VISIBILITY_PUBLIC) {
                //TODO: this may be refined to promote use of private const
                continue;
            }
            if ($constant->type === null) {
                //when a constant has no type?
                continue;
            }
            if ($constant->type->hasLiteralString() && count($constant->type->getLiteralStrings()) === 1) {
                $literal_values = $constant->type->getLiteralStrings();
                $literal_value = array_shift($literal_values)->value;
                if (strlen($literal_value) < 3 || in_array($literal_value, self::$forbidden_list, true)) {
                    //will lead to too much false positives
                    continue;
                }
                if (isset(self::$list_values[$literal_value])) {
                    //if we already had a constant for this value, we'll unset it and add this value to a forbidden list
                    //this will reduce ambinguities and false positives
                    unset(self::$list_values[$literal_value]);
                    self::$forbidden_list[] = $literal_value;
                    continue;
                }

                self::$list_values[$literal_value] = [$classlike_storage->name, $constant_name, $event->getStatementsSource()->getFileName(), $constant->location->getLineNumber()];
            }
        }
    }
}
