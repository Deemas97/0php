<?php
namespace Core\Container;

use ReflectionClass;
use ReflectionException;
use RuntimeException;

class GlobalContainer implements ReflectionContainerInterface
{
    protected array $components = [];

    public function get(string $id)
    {
        if ($this->has($id)) {
            return is_object($this->components[$id]) ? $this->components[$id] : $this->initComponent($this->components[$id]);
        }

        return $this->initComponent($id);
    }

    public function has(string $id): bool
    {
        return isset($this->components[$id]);
    }

    public function set(string $id, $item): void
    {
        $this->components[$id] = $item;
    }

    protected function initComponent(string $id): ?object
    {
        $component = $this->prepareComponent($id);

        if (is_object($component)) {
            $this->set($id, $component);
        }

        return $component;
    }

    protected function prepareComponent(string $id): ?object
    {
        try {
            $classReflector = new ReflectionClass($id);
        } catch (ReflectionException $e) {
            error_log("Controller class not found: " . $id);
            throw new RuntimeException("Controller {$id} not found", 0, $e);
        }

        $constructReflector = $classReflector->getConstructor();
        if (!$constructReflector) {
            return new $id;
        }
        
        $parameters = [];
        foreach ($constructReflector->getParameters() as $param) {
            $type = $param->getType();
            if (!$type || $type->isBuiltin()) {
                throw new RuntimeException("Cannot resolve parameter {$param->getName()} for {$id}");
            }
            
            $dependency = $this->get($type->getName());
            if (!$dependency) {
                throw new RuntimeException("Dependency {$type->getName()} not found for {$id}");
            }
            
            $parameters[$param->getName()] = $dependency;
        }
        
        return new $id(...$parameters);
    }
}