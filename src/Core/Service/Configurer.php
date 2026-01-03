<?php
namespace Core\Service;

use Bootstrap\Config\ProjectMode;
use Core\Service\Configurer\ModeStateDev;
use Core\Service\Configurer\ModeStateProduction;
use Core\Service\Configurer\ModeStateTest;

class Configurer implements CoreServiceInterface
{
    public function init(): void
    {
        $projectMode = ProjectMode::getCurrentMode();

        switch ($projectMode) {
            case 'dev':
                $state = new ModeStateDev();
                break;
            case 'test':
                $state = new ModeStateTest();
                break;
            case 'production':
            default:
                $state = new ModeStateProduction();
        }

        $state->init();
    }
}