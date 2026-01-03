<?php
namespace Dev\Controller\Api;

use Core\Controller\ControllerAbstract;
use Core\Controller\ControllerResponseInterface;
use Core\Security\AuthAttribute;
use Infrastructure\Service\DevModeManager;
use RuntimeException;

class CacheApiController extends ControllerAbstract
{
    public function __construct()
    {
        if (!DevModeManager::isAccessAllowed()) {
            $this->redirect('/error_403');
        }
    }

    #[AuthAttribute(table: 'employees')]
    public function apiClearCache(): ControllerResponseInterface
    {
        try {
            $cacheDir = YADRO_PHP__ROOT_DIR . '/var/cache/dev';
            $this->clearDirectory($cacheDir);

            return $this->initJsonResponse([
                'success' => true,
                'message' => 'Кэш успешно очищен'
            ]);
        } catch (RuntimeException $e) {
            return $this->initJsonResponse([
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function clearDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            throw new RuntimeException("Директория {$dir} не существует");
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->clearDirectory($path);
                rmdir($path);
            } else {
                if (!unlink($path)) {
                    throw new RuntimeException("Не удалось удалить файл {$path}");
                }
            }
        }
    }

    protected function redirect(string $path): void
    {
        header('Location: ' . $path);
        exit;
    }
}