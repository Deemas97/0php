<?php
namespace Core\Pipeline;

use Core\MessageBus\MessageBusInterface;
use Core\Middleware\MiddlewareInterface;

class CombinedPipeline implements PipelineInterface
{
    private array $pipelines = [];
    
    /**
     * Добавить pipeline в комбинированный конвейер
     */
    public function addPipeline(PipelineInterface $pipeline): self
    {
        $this->pipelines[] = $pipeline;
        return $this;
    }
    
    /**
     * Удалить pipeline по индексу
     */
    public function removePipeline(int $index): self
    {
        if (isset($this->pipelines[$index])) {
            unset($this->pipelines[$index]);
            $this->pipelines = array_values($this->pipelines);
        }
        return $this;
    }
    
    /**
     * Получить все pipelines
     */
    public function getPipelines(): array
    {
        return $this->pipelines;
    }
    
    /**
     * Очистить все pipelines
     */
    public function clearPipelines(): self
    {
        $this->pipelines = [];
        return $this;
    }
    
    /**
     * Основной метод обработки
     */
    public function process(MessageBusInterface $messageBus): MessageBusInterface
    {
        foreach ($this->pipelines as $pipeline) {
            $messageBus = $pipeline->process($messageBus);
            
            if ($messageBus->has('_pipeline_stopped') && 
                $messageBus->get('_pipeline_stopped') === true) {
                break;
            }
        }
        return $messageBus;
    }
    
    /**
     * Реализация PipelineInterface
     */
    public function pipe(MiddlewareInterface $middleware): self
    {
        if (!empty($this->pipelines)) {
            end($this->pipelines)->pipe($middleware);
        }
        return $this;
    }
    
    public function prepend(MiddlewareInterface $middleware): self
    {
        if (!empty($this->pipelines)) {
            end($this->pipelines)->prepend($middleware);
        }
        return $this;
    }
    
    public function clear(): self
    {
        foreach ($this->pipelines as $pipeline) {
            $pipeline->clear();
        }
        return $this;
    }
    
    public function has(string $middlewareClass): bool
    {
        foreach ($this->pipelines as $pipeline) {
            if ($pipeline->has($middlewareClass)) {
                return true;
            }
        }
        return false;
    }
    
    public function remove(string $middlewareClass): self
    {
        foreach ($this->pipelines as $pipeline) {
            $pipeline->remove($middlewareClass);
        }
        return $this;
    }
    
    public function count(): int
    {
        $total = 0;
        foreach ($this->pipelines as $pipeline) {
            $total += $pipeline->count();
        }
        return $total;
    }
    
    public function getMiddleware(): array
    {
        $middleware = [];
        foreach ($this->pipelines as $pipeline) {
            $middleware = array_merge($middleware, $pipeline->getMiddleware());
        }
        return $middleware;
    }
    
    /**
     * Получить статистику по всем pipelines
     */
    public function getStatistics(): array
    {
        $statistics = [
            'pipeline_count' => count($this->pipelines),
            'total_middleware' => $this->count(),
            'pipelines' => []
        ];
        
        foreach ($this->pipelines as $index => $pipeline) {
            $className = get_class($pipeline);
            $statistics['pipelines'][] = [
                'index' => $index,
                'class' => $className,
                'middleware_count' => $pipeline->count(),
                'middleware_list' => array_map('get_class', $pipeline->getMiddleware()),
            ];
        }
        
        return $statistics;
    }
}