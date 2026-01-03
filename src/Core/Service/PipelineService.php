<?php
namespace Core\Service;

use Core\MessageBus\MessageBusInterface;
use Core\Service\CoreServiceInterface;

class PipelineService implements CoreServiceInterface
{
    /**
     * Остановить выполнение конвейера
     */
    public static function stopPipeline(MessageBusInterface $messageBus): MessageBusInterface
    {
        $messageBus->set('_pipeline_stopped', true);

        return $messageBus;
    }

    /**
     * Проверить, остановлен ли конвейер
     */
    public static function isPipelineStopped(MessageBusInterface $messageBus): bool
    {
        return $messageBus->has('_pipeline_stopped') && $messageBus->get('_pipeline_stopped') === true;
    }

    /**
     * Получить имя текущего middleware
     */
    public static function getCurrentMiddlewareName(MessageBusInterface $messageBus): ?string
    {
        return $messageBus->get('_current_middleware');
    }
}