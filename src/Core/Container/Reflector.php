<?php
namespace Core\Container;

use Core\Container\ReflectionContainerInterface;
use Exception;
use ReflectionClass;
use ReflectionException;
use ReflectionParameter;

class Reflector
{
    protected ReflectionContainerInterface $container;
    protected static array $constructorCache = [];

    public function __construct(ReflectionContainerInterface $container)
    {
        $this->container = $container;
    }

    public function build(string $id, bool $forceNew = false)
    {
        if (!$forceNew && isset(self::$constructorCache[$id])) {
            $dependencies = $this->resolveCachedDependencies($id);
            return new $id(...$dependencies);
        }

        try {
            $classReflector = new ReflectionClass($id);
            
            if (!$classReflector->isInstantiable()) {
                throw new Exception("Class $id is not instantiable");
            }

            $constructor = $classReflector->getConstructor();
            
            if (empty($constructor)) {
                self::$constructorCache[$id] = [];
                return new $id;
            }

            $parameters = $constructor->getParameters();
            
            if (empty($parameters)) {
                self::$constructorCache[$id] = [];
                return new $id;
            }

            $dependencies = $this->resolveParameters($parameters);
            
            self::$constructorCache[$id] = $this->getParameterInfo($parameters);
            
            return new $id(...$dependencies);
            
        } catch (ReflectionException $e) {
            throw new Exception("Class $id not found: " . $e->getMessage(), 0, $e);
        }
    }

    public function buildWith(string $id, array $customParameters)
    {
        try {
            $classReflector = new ReflectionClass($id);
            $constructor = $classReflector->getConstructor();
            
            if (empty($constructor)) {
                return new $id;
            }

            $parameters = $constructor->getParameters();
            $dependencies = [];
            
            foreach ($parameters as $parameter) {
                $name = $parameter->getName();
                $type = $parameter->getType();
                
                if (array_key_exists($name, $customParameters)) {
                    $dependencies[] = $customParameters[$name];
                } elseif (isset($customParameters[$parameter->getPosition()])) {
                    $dependencies[] = $customParameters[$parameter->getPosition()];
                } elseif ($type && !$type->isBuiltin()) {
                    $dependencies[] = $this->container->get($type->getName());
                } elseif ($parameter->isDefaultValueAvailable()) {
                    $dependencies[] = $parameter->getDefaultValue();
                } else {
                    throw new Exception("Cannot resolve parameter $name of $id");
                }
            }
            
            return new $id(...$dependencies);
            
        } catch (ReflectionException $e) {
            throw new Exception("Class $id not found", 0, $e);
        }
    }

    protected function resolveParameters(array $parameters): array
    {
        $dependencies = [];
        
        foreach ($parameters as $parameter) {
            $dependencies[] = $this->resolveParameter($parameter);
        }
        
        return $dependencies;
    }

    protected function resolveParameter(ReflectionParameter $parameter)
    {
        $type = $parameter->getType();
        
        if ($type && !$type->isBuiltin()) {
            $typeName = $type->getName();
            
            if (interface_exists($typeName) || class_exists($typeName)) {
                try {
                    return $this->container->get($typeName);
                } catch (Exception $e) {
                    if ($parameter->isDefaultValueAvailable()) {
                        return $parameter->getDefaultValue();
                    }
                    throw new Exception(
                        "Cannot resolve dependency {$parameter->getName()} of type $typeName"
                    );
                }
            }
        }
        
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }
        
        if ($type && $type->isBuiltin() && $type->allowsNull()) {
            return null;
        }
        
        throw new Exception(
            "Cannot resolve parameter {$parameter->getName()} of {$parameter->getDeclaringClass()->getName()}"
        );
    }

    protected function getParameterInfo(array $parameters): array
    {
        $info = [];
        
        foreach ($parameters as $parameter) {
            $type = $parameter->getType();
            $info[] = [
                'name' => $parameter->getName(),
                'type' => $type ? $type->getName() : null,
                'builtin' => $type ? $type->isBuiltin() : true,
                'optional' => $parameter->isOptional(),
                'default' => $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null,
            ];
        }
        
        return $info;
    }

    protected function resolveCachedDependencies(string $id)
    {
        $dependencies = [];
        
        foreach (self::$constructorCache[$id] as $paramInfo) {
            if (!$paramInfo['builtin'] && $paramInfo['type']) {
                $dependencies[] = $this->container->get($paramInfo['type']);
            } elseif ($paramInfo['optional']) {
                $dependencies[] = $paramInfo['default'];
            } else {
                return $this->build($id, true);
            }
        }
        
        return $dependencies;
    }
}