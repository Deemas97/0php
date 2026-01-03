<?php
namespace Core\Pipeline;

use Core\MessageBus\MessageBusInterface;
use Core\Middleware\MiddlewareInterface;
use Core\Middleware\SpecificMiddlewareInterface;
use RuntimeException;

class SpecificMiddlewarePipeline implements PipelineInterface
{
    private array $middleware = [];
    private bool $isSorted = false;
    private array $middlewarePriorities = [];

    public function __construct(array $middleware = [])
    {
        foreach ($middleware as $item) {
            $this->pipe($item);
        }
    }

    public function process(MessageBusInterface $messageBus): MessageBusInterface
    {
        $this->sortMiddlewareByPriority();
        
        foreach ($this->middleware as $middleware) {
            if (!$middleware instanceof SpecificMiddlewareInterface) {
                throw new RuntimeException(
                    sprintf('Middleware %s должен реализовывать CoreMiddlewareInterface', 
                    get_class($middleware))
                );
            }
            
            $messageBus = $middleware->process($messageBus);
            
            if ($messageBus->has('_pipeline_stopped') && $messageBus->get('_pipeline_stopped') === true) {
                break;
            }
        }
        
        return $messageBus;
    }

    public function pipe(MiddlewareInterface $middleware): self
    {
        $this->middleware[] = $middleware;
        $this->isSorted = false;
        return $this;
    }

    public function prepend(MiddlewareInterface $middleware): self
    {
        array_unshift($this->middleware, $middleware);
        $this->isSorted = false;
        return $this;
    }

    public function setPriority(string $middlewareClass, int $priority): self
    {
        $this->middlewarePriorities[$middlewareClass] = $priority;
        $this->isSorted = false;
        return $this;
    }

    private function sortMiddlewareByPriority(): void
    {
        if ($this->isSorted || empty($this->middlewarePriorities)) {
            $this->isSorted = true;
            return;
        }

        usort($this->middleware, function ($a, $b) {
            $priorityA = $this->middlewarePriorities[get_class($a)] ?? 0;
            $priorityB = $this->middlewarePriorities[get_class($b)] ?? 0;
            
            return $priorityB <=> $priorityA;
        });
        
        $this->isSorted = true;
    }

    public function clear(): self
    {
        $this->middleware = [];
        $this->middlewarePriorities = [];
        $this->isSorted = true;
        return $this;
    }

    public function has(string $middlewareClass): bool
    {
        foreach ($this->middleware as $middleware) {
            if (get_class($middleware) === $middlewareClass) {
                return true;
            }
        }
        return false;
    }

    public function remove(string $middlewareClass): self
    {
        $this->middleware = array_filter($this->middleware, function ($middleware) use ($middlewareClass) {
            return get_class($middleware) !== $middlewareClass;
        });
        
        $this->middleware = array_values($this->middleware);
        unset($this->middlewarePriorities[$middlewareClass]);
        
        return $this;
    }

    public function count(): int
    {
        return count($this->middleware);
    }

    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    public function pipeGroup(array $middlewares): self
    {
        foreach ($middlewares as $middleware) {
            $this->pipe($middleware);
        }
        return $this;
    }

    public function clone(): self
    {
        $clone = new self();
        $clone->middleware = $this->middleware;
        $clone->middlewarePriorities = $this->middlewarePriorities;
        return $clone;
    }
}