<?php
namespace Core\Middleware;

use Core\MessageBus\MessageBusInterface;
use Core\MessageBus\Actions;
use ReflectionClass;
use ReflectionException;
use RuntimeException;

final class RequestMiddleware implements CoreMiddlewareInterface
{
    public function process(MessageBusInterface $messageBus): MessageBusInterface
    {
        $actions = new Actions();

        $actions->set('request_time', $messageBus->get('request_time') ?? null);
        $actions->set('request_id',   $messageBus->get('request_id') ?? null);

        if ((!$controllerName = $messageBus->get('route_controller_name')) && !class_exists($controllerName)) {
            throw new RuntimeException("Controller {$controllerName} not found");
        }
        

        if ((!$methodName = $messageBus->get('route_method_name')) && !method_exists($controllerName, $methodName)) {
            throw new RuntimeException("Method {$methodName} not found in {$controllerName}");
        }

        $headers = $this->validateHeaders(getallheaders());
        $actions->set('headers', $headers);

        $routeParameters = $messageBus->get('route_parameters');
        $queryParameters = $this->parseQueryString($messageBus->get('route_query_string'));

        try {
            $controllerReflection = new ReflectionClass($controllerName);
            $controllerMethod  = $controllerReflection->getMethod($methodName);
            $controllerMethodParams = [];

            if ($controllerMethod !== null) {
                $parameters = $controllerMethod->getParameters();

                foreach ($parameters as $param) {
                    $paramName = $param->getName();
                    $paramType = $param->getType();
                    $isOptional = $param->isOptional();
                    $hasDefault = $param->isDefaultValueAvailable();
                    $defaultValue = $hasDefault ? $param->getDefaultValue() : null;

                    $paramValue = $routeParameters[$paramName] ?? null;

                    if ($paramValue === null && isset($queryParameters[$paramName])) {
                        $paramValue = $queryParameters[$paramName];
                    }

                    if ($paramValue === null && !$isOptional && !$hasDefault) {
                        throw new RuntimeException(
                            "Missing required method parameter '{$paramName}' for method {$controllerName}::{$methodName}()"
                        );
                    }

                    if ($paramValue === null && $hasDefault) {
                        $paramValue = $defaultValue;
                    }

                    if ($paramType !== null && !$paramType->isBuiltin()) {
                        $typeName = $paramType->getName();

                        if (!class_exists($typeName) && !interface_exists($typeName)) {
                            throw new RuntimeException(
                                "Cannot resolve method parameter '{$paramName}' of type '{$typeName}' for {$controllerName}::{$methodName}()"
                            );
                        }

                        $paramValue = $typeName;
                    } elseif ($paramType !== null && $paramValue !== null) {
                        $typeName = $paramType->getName();
                        $isValid = false;

                        switch ($typeName) {
                            case 'string':
                                $isValid = is_string($paramValue) || is_numeric($paramValue);
                                if ($isValid) {
                                    $paramValue = (string)$paramValue;
                                }
                                break;
                            case 'int':
                                $isValid = is_numeric($paramValue);
                                if ($isValid) {
                                    $paramValue = (int)$paramValue;
                                }
                                break;
                            case 'float':
                                $isValid = is_numeric($paramValue);
                                if ($isValid) {
                                    $paramValue = (float)$paramValue;
                                }
                                break;
                            case 'bool':
                                $isValid = is_bool($paramValue) || is_numeric($paramValue) || in_array(strtolower($paramValue), ['true', 'false', 'yes', 'no', 'on', 'off', '1', '0'], true);
                                if ($isValid) {
                                    $paramValue = filter_var($paramValue, FILTER_VALIDATE_BOOLEAN);
                                }
                                break;
                            case 'array':
                                $isValid = is_array($paramValue);
                                break;
                            default:
                                $isValid = true;
                        }

                        if (!$isValid) {
                            throw new RuntimeException(
                                "Invalid type for method parameter '{$paramName}'. Expected '{$typeName}', got " . gettype($paramValue)
                            );
                        }
                    }

                    $controllerMethodParams[$paramName] = $paramValue;
                }
            }

            $actions->set('controllerMethodParams', $controllerMethodParams);
        } catch (ReflectionException $e) {
            throw new RuntimeException(
                "Failed to reflect controller {$controllerName}: " . $e->getMessage(),
                0,
                $e
            );
        }

        $actions->set('controllerName',   $controllerName);
        $actions->set('methodName',       $methodName);
        $actions->set('reflectionMethod', $controllerMethod);
        $actions->set('routeParameters',  $routeParameters);
        $actions->set('queryParameters',  $queryParameters);

        return $actions;
    }

    private function validateHeaders(array $headers): array
    {
        $allowedHeaders = [
            'accept', 'accept-language', 'accept-encoding', 
            'user-agent', 'content-type', 'cache-control',
            'connection', 'upgrade-insecure-requests'
        ];
        
        $validated = [];
        foreach ($headers as $key => $value) {
            $normalizedKey = strtolower($key);
            
            if (in_array($normalizedKey, $allowedHeaders)) {
                $cleanValue = is_string($value) 
                    ? preg_replace('/[^\x20-\x7E]/', '', substr($value, 0, 1024))
                    : '';
                
                $validated[$key] = $cleanValue;
            }
        }
        
        return $validated;
    }

    private function parseQueryString(?string $queryString): array
    {
        if (empty($queryString)) {
            return [];
        }

        $result = [];
        $pairs = explode('&', $queryString);

        foreach ($pairs as $pair) {
            if (empty($pair)) {
                continue;
            }

            $keyValue = explode('=', $pair, 2);
            $key = urldecode($keyValue[0]);
            $value = isset($keyValue[1]) ? urldecode($keyValue[1]) : '';

            if (strpos($key, '[') !== false && strpos($key, ']') !== false) {
                $result[$key] = $value;
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}