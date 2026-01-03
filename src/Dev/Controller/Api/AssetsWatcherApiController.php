<?php
namespace Dev\Controller\Api;

use Core\Controller\ControllerAbstract;
use Core\Controller\ControllerResponseInterface;
use Dev\AssetsWatcher;
use Infrastructure\Service\DevModeManager;

class AssetsWatcherApiController extends ControllerAbstract
{
    public function __construct()
    {
        if (!DevModeManager::isAccessAllowed()) {
            $this->redirect('/error_403');
        }
    }

    public function check(): ControllerResponseInterface
    {
        $lastCheck = (int)($_GET['last'] ?? 0);
        $currentTime = time();
        
        $hasChanges = AssetsWatcher::hasChanges();
        $changedFiles = AssetsWatcher::getChangedFiles();
        
        return $this->initJsonResponse([
            'changed' => $hasChanges,
            'files' => $changedFiles,
            'timestamp' => $currentTime,
            'last_check' => $lastCheck,
            'reload_required' => $hasChanges && count($changedFiles) > 0
        ]);
    }
    
    public function forceCheck(): ControllerResponseInterface
    {
        AssetsWatcher::clearHashes();
        AssetsWatcher::init();
        
        return $this->initJsonResponse([
            'success' => true,
            'message' => 'Cache cleared, next check will detect all changes'
        ]);
    }

    public function webSocket(): ControllerResponseInterface
    {
        AssetsWatcher::clearHashes();
        AssetsWatcher::init();
        
        return $this->initJsonResponse([
            'success' => true,
            'reload' => true
        ]);
    }

    protected function redirect(string $path): void
    {
        header('Location: ' . $path);
        exit;
    }
}