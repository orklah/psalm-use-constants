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

    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        global $argv; // please look away but I need this to ensure this plugin was called without cache

        if(!in_array('--no-cache', $argv, true)){
            die('Please run orklah/psalm-use-constants with --no-cache to ensure correct collect of constants');
        }

        if (!$event->getCodebase()->alter_code) {
            //return true;
        }

        $original_expr = $event->getExpr();
        if (!$original_expr instanceof String_) {
            return true;
        }

        if (strlen($original_expr->value) < 2) {
            //will lead to too much false positives
            return true;
        }

        if (!isset(self::$list_values[$original_expr->value])) {
            return true;
        }

        [$classlike_name, $constant_name] = self::$list_values[$original_expr->value];

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
                return true;
            }
            if ($constant->type->hasLiteralString() && count($constant->type->getLiteralStrings()) === 1) {
                $literal_values = $constant->type->getLiteralStrings();
                $literal_value = array_shift($literal_values)->value;
                if (strlen($literal_value) < 2 || in_array($literal_value, self::$forbidden_list, true)) {
                    //will lead to too much false positives
                    return true;
                }
                if (isset(self::$list_values[$literal_value])) {
                    //if we already had a constant for this value, we'll unset it and add this value to a forbiddent list
                    //this will reduce ambinguities and false positives
                    unset(self::$list_values[$literal_value]);
                    self::$forbidden_list[] = $literal_value;
                    return true;
                }
                self::$list_values[$literal_value] = [$classlike_storage->name, $constant_name];
            }
        }
    }
}
