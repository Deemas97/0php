<?php
namespace Dev;

use ReflectionClass;
use ReflectionMethod;
use Bootstrap\Config\DotEnv;
use ReflectionException;

class ApiDocGenerator
{
    private static bool $enabled = false;
    private static array $controllers = [];
    private static string $docsDir;
    
    public static function init(): void
    {
        self::$enabled = DotEnv::getDataItem('DEV_API_DOCS', '0') === '1';
        self::$docsDir = YADRO_PHP__ROOT_DIR . '/docs/';
        
        if (self::$enabled && !is_dir(self::$docsDir)) {
            mkdir(self::$docsDir, 0755, true);
        }
        
        if (self::$enabled) {
            self::scanControllers();
            register_shutdown_function([self::class, 'generateDocs']);
        }
    }
    
    private static function scanControllers(): void
    {
        $controllersPath = YADRO_PHP__ROOT_DIR . '/src/App/Controller';
        
        if (!is_dir($controllersPath)) {
            return;
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($controllersPath)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $content = file_get_contents($file->getRealPath());

                if (preg_match('/namespace\s+([^;]+)/', $content, $namespaceMatch) &&
                    preg_match('/class\s+(\w+)/', $content, $classMatch)) {
                    
                    $className = $namespaceMatch[1] . '\\' . $classMatch[1];
                    
                    if (class_exists($className)) {
                        self::analyzeController($className);
                    }
                }
            }
        }
    }
    
    private static function analyzeController(string $className): void
    {
        try {
            $reflection = new ReflectionClass($className);
            
            if ($reflection->isAbstract() || $reflection->isInterface()) {
                return;
            }
            
            $controllerInfo = [
                'class' => $className,
                'methods' => [],
                'doc_comment' => self::parseDocComment($reflection->getDocComment()),
            ];
            
            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $className) {
                    continue;
                }
                
                $methodInfo = self::analyzeMethod($method);
                if ($methodInfo) {
                    $controllerInfo['methods'][] = $methodInfo;
                }
            }
            
            if (!empty($controllerInfo['methods'])) {
                self::$controllers[] = $controllerInfo;
            }
            
        } catch (ReflectionException $e) {}
    }
    
    private static function analyzeMethod(ReflectionMethod $method): ?array
    {
        $docComment = $method->getDocComment();
        $parsedDoc = self::parseDocComment($docComment);
        
        $routes = self::extractRoutes($docComment);
        
        if (empty($routes) && !str_starts_with($method->getName(), '__')) {
            $routes = self::guessRoute($method);
        }
        
        if (empty($routes)) {
            return null;
        }
        
        $parameters = [];
        foreach ($method->getParameters() as $param) {
            $paramInfo = [
                'name' => $param->getName(),
                'type' => $param->getType()?->getName() ?? 'mixed',
                'has_default' => $param->isDefaultValueAvailable(),
                'default_value' => $param->isDefaultValueAvailable() ? 
                    self::formatDefaultValue($param->getDefaultValue()) : null,
            ];
            $parameters[] = $paramInfo;
        }
        
        $examples = self::extractExamples($docComment);
        
        return [
            'name' => $method->getName(),
            'summary' => $parsedDoc['summary'] ?? '',
            'description' => $parsedDoc['description'] ?? '',
            'routes' => $routes,
            'parameters' => $parameters,
            'return_type' => $method->getReturnType()?->getName() ?? 'void',
            'examples' => $examples,
            'throws' => self::extractThrows($docComment),
        ];
    }
    
    private static function parseDocComment(?string $docComment): array
    {
        if (!$docComment) {
            return [];
        }
        
        $result = ['summary' => '', 'description' => '', 'tags' => []];
        $lines = explode("\n", $docComment);
        
        $summary = [];
        $description = [];
        $inDescription = false;
        
        foreach ($lines as $line) {
            $line = trim($line);
            $line = preg_replace('/^\/\*\*|\*\/|\*/', '', $line);
            
            if (empty($line)) {
                continue;
            }
            
            if (str_starts_with($line, '@')) {
                if (preg_match('/@(\w+)\s+(.+)/', $line, $matches)) {
                    $result['tags'][$matches[1]][] = trim($matches[2]);
                }
            } elseif (!$inDescription && empty($summary)) {
                $summary[] = $line;
            } else {
                $inDescription = true;
                $description[] = $line;
            }
        }
        
        $result['summary'] = implode(' ', $summary);
        $result['description'] = implode("\n", $description);
        
        return $result;
    }
    
    private static function extractRoutes(string $docComment): array
    {
        $routes = [];
        
        if (preg_match_all('/@route\s+(GET|POST|PUT|DELETE|PATCH|OPTIONS)\s+(\S+)/i', 
            $docComment, $matches)) {
            
            for ($i = 0; $i < count($matches[0]); $i++) {
                $routes[] = [
                    'method' => strtoupper($matches[1][$i]),
                    'path' => $matches[2][$i],
                ];
            }
        }
        
        return $routes;
    }
    
    private static function guessRoute(ReflectionMethod $method): array
    {
        $methodName = $method->getName();
        $controllerName = $method->getDeclaringClass()->getShortName();
        
        if (str_ends_with($controllerName, 'Controller')) {
            $resource = strtolower(str_replace('Controller', '', $controllerName));
            
            $routeMap = [
                'index' => ['GET', "/$resource"],
                'show' => ['GET', "/$resource/{id}"],
                'create' => ['GET', "/$resource/create"],
                'store' => ['POST', "/$resource"],
                'edit' => ['GET', "/$resource/{id}/edit"],
                'update' => ['PUT', "/$resource/{id}"],
                'destroy' => ['DELETE', "/$resource/{id}"],
            ];
            
            if (isset($routeMap[$methodName])) {
                list($httpMethod, $path) = $routeMap[$methodName];
                return [['method' => $httpMethod, 'path' => $path]];
            }
        }
        
        return [];
    }
    
    private static function extractExamples(string $docComment): array
    {
        $examples = [];
        
        if (preg_match_all('/@example\s+(curl|http|json)\s*(.+?)(?=\n\s*@|\*\/)/s', 
            $docComment, $matches)) {
            
            for ($i = 0; $i < count($matches[0]); $i++) {
                $examples[] = [
                    'type' => $matches[1][$i],
                    'content' => trim($matches[2][$i]),
                ];
            }
        }
        
        return $examples;
    }
    
    private static function extractThrows(string $docComment): array
    {
        $throws = [];
        
        if (preg_match_all('/@throws\s+(\S+)\s+(.+)/', $docComment, $matches)) {
            for ($i = 0; $i < count($matches[0]); $i++) {
                $throws[] = [
                    'exception' => $matches[1][$i],
                    'description' => $matches[2][$i],
                ];
            }
        }
        
        return $throws;
    }
    
    private static function formatDefaultValue($value): string
    {
        if (is_array($value)) {
            return '[]';
        }
        if (is_null($value)) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_string($value)) {
            return "'" . addslashes($value) . "'";
        }
        return (string)$value;
    }
    
    public static function generateDocs(): void
    {
        if (!self::$enabled || empty(self::$controllers)) {
            return;
        }
        
        $docs = [
            'generated_at' => date('Y-m-d H:i:s'),
            'project' => \Bootstrap\Config\DotEnv::getDataItem('APP_NAME', 'Zero-PHP'),
            'version' => '1.0',
            'base_url' => \Bootstrap\Config\DotEnv::getDataItem('APP_URL', ''),
            'controllers' => self::$controllers,
        ];
        
        $jsonFile = self::$docsDir . 'api_docs.json';
        file_put_contents($jsonFile, json_encode($docs, JSON_PRETTY_PRINT));
        
        self::generateHtmlDocs($docs);
        
        self::generateOpenApiSpec($docs);
    }
    
    private static function generateHtmlDocs(array $docs): void
    {
        $html = self::renderHtmlTemplate($docs);
        file_put_contents(self::$docsDir . 'api_docs.html', $html);
    }
    
    private static function generateOpenApiSpec(array $docs): void
    {
        $openApi = [
            'openapi' => '3.0.0',
            'info' => [
                'title' => $docs['project'],
                'version' => $docs['version'],
                'description' => 'Auto-generated API documentation',
            ],
            'servers' => [
                ['url' => $docs['base_url']]
            ],
            'paths' => [],
        ];
        
        foreach ($docs['controllers'] as $controller) {
            foreach ($controller['methods'] as $method) {
                foreach ($method['routes'] as $route) {
                    $path = $route['path'];
                    $httpMethod = strtolower($route['method']);
                    
                    if (!isset($openApi['paths'][$path])) {
                        $openApi['paths'][$path] = [];
                    }
                    
                    $openApi['paths'][$path][$httpMethod] = [
                        'summary' => $method['summary'],
                        'description' => $method['description'],
                        'parameters' => array_map(function($param) {
                            return [
                                'name' => $param['name'],
                                'in' => 'path',
                                'schema' => ['type' => $param['type']],
                                'required' => !$param['has_default'],
                            ];
                        }, $method['parameters']),
                        'responses' => [
                            '200' => [
                                'description' => 'Success',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['type' => 'object']
                                    ]
                                ]
                            ]
                        ]
                    ];
                }
            }
        }
        
        file_put_contents(
            self::$docsDir . 'openapi.json',
            json_encode($openApi, JSON_PRETTY_PRINT)
        );
    }
    
    private static function renderHtmlTemplate(array $docs): string
    {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>{$docs['project']} - API Documentation</title>
    <style nonce="{{CSP_NONCE}}">
        body { font-family: Arial, sans-serif; margin: 20px; }
        .controller { background: #f5f5f5; padding: 15px; margin: 10px 0; border-radius: 5px; }
        .method { background: white; padding: 10px; margin: 10px 0; border-left: 3px solid #007bff; }
        .route { display: inline-block; background: #007bff; color: white; padding: 2px 8px; border-radius: 3px; margin-right: 5px; }
    </style>
</head>
<body>
    <h1>{$docs['project']} API Documentation</h1>
    <p>Generated: {$docs['generated_at']}</p>
    
    <h2>Controllers</h2>
    
    {$this->renderControllers($docs['controllers'])}
    
    <script>
        document.querySelectorAll('.method').forEach(method => {
            method.addEventListener('click', () => {
                method.classList.toggle('expanded');
            });
        });
    </script>
</body>
</html>
HTML;
    }
    
    private function renderControllers(array $controllers): string
    {
        $html = '';
        foreach ($controllers as $controller) {
            $html .= "<div class='controller'>";
            $html .= "<h3>{$controller['class']}</h3>";
            $html .= "<p>{$controller['doc_comment']['summary']}</p>";
            
            foreach ($controller['methods'] as $method) {
                $html .= "<div class='method'>";
                $html .= "<h4>{$method['name']}</h4>";
                $html .= "<p>{$method['summary']}</p>";
                
                foreach ($method['routes'] as $route) {
                    $html .= "<span class='route'>{$route['method']} {$route['path']}</span>";
                }
                
                $html .= "</div>";
            }
            
            $html .= "</div>";
        }
        
        return $html;
    }
}