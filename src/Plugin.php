<?php declare(strict_types=1);
namespace Orklah\UseConstants;

use Orklah\UseConstants\Hooks\UseConstantsHooks;
use SimpleXMLElement;
use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\Plugin\RegistrationInterface;

class Plugin implements PluginEntryPointInterface
{
    public function __invoke(RegistrationInterface $registration, ?SimpleXMLElement $config = null): void
    {
        if(class_exists(UseConstantsHooks::class)){
            $registration->registerHooksFromClass(UseConstantsHooks::class);
        }
    }
}
