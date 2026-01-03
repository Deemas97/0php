<?php
namespace Core\Service;

use Bootstrap\Config\DotEnv;
use Core\Service\Renderer\HtmlMinificator;
use Core\Service\Renderer\TemplatesCachingService;
use Infrastructure\Config\ContentSecurityPolicy;
use Throwable;
use RuntimeException;

class Renderer implements CoreServiceInterface
{
    private string $templateDir = '../templates/';

    private array $sectionsStack = [];
    private array $currentSections = [];
    private ?string $currentSection = null;
    private ?string $layout = null;

    private bool $cachingEnabled = false;

    private bool $minifyingEnabled = true;
    private bool $isMinified = false;

    private string $pathPrefixAssets = '';
    private string $pathPrefixLocalStorage = '';
    
    private const string CSP_NONCE_PLACEHOLDER = '{{CSP_NONCE}}';
    private ?string $cspNonce = null;

    private const string CSP_HASH_PLACEHOLDER  = '{{CSP_HASH}}';
    private ?string $cspHash = null;
    
    public function __construct(
        private TemplatesCachingService $caching,
        private HtmlMinificator $minificator
    )
    {
        $this->pathPrefixAssets       = DotEnv::getDataItem('RENDERER__ASSETS__PATH_PREFIX');
        $this->pathPrefixLocalStorage = DotEnv::getDataItem('RENDERER__LOCAL_STORAGE__PATH_PREFIX');

        $this->cspNonce = ContentSecurityPolicy::getNonce();
    }

    public function setCspNonce(string $nonce): void
    {
        $this->cspNonce = $nonce;
    }

    public function getCspNonce(): string
    {
        return $this->cspNonce ?? '';
    }

    public function enableCaching(bool $flag): void
    {
        $this->cachingEnabled = $flag;
    }

    public function enableMinifying(bool $flag): void
    {
        $this->minifyingEnabled = $flag;
    }
    
    public function render(string $templatePath, array $data = []): string
    {
        if ($this->cachingEnabled === true) {
            $cacheFilePath = $this->caching->getCacheFilePath($templatePath, $data);
            
            $cachedContent = $this->caching->extractFromCache($cacheFilePath);
            if ($cachedContent !== null) {
                return $this->applySecurityMacros($cachedContent);
            }
        } else {
            $cacheFilePath = null;
        }

        $content = $this->compileTemplate($templatePath, $data);

        if ($this->minifyingEnabled && !$this->isMinified) {
            $content = $this->minifyContent($content);
        }

        if ($this->cachingEnabled === true && $cacheFilePath !== null) {
            $cacheContent = $this->isMinified ? $content : $this->minifyContent($content);
            $this->saveToCache($cacheFilePath, $cacheContent);
        }

        return $this->applySecurityMacros($content);
    }

    private function compileTemplate(string $templatePath, array $data): string
    {
        $this->sectionsStack[] = $this->currentSections;
        $this->currentSections = [];
        $this->currentSection = null;
        $this->layout = null;
        
        $fullPath = $this->templateDir . $templatePath;
        
        if (!file_exists($fullPath)) {
            throw new RuntimeException("Не найден файл шаблона: " . $fullPath);
        }

        $content = $this->renderTemplate($fullPath, $data);
        
        if ($this->layout) {
            $layoutPath = $this->templateDir . $this->layout;
            $content = $this->compileTemplate($layoutPath, array_merge($data, ['content' => $content]));
        }

        $this->currentSections = array_pop($this->sectionsStack);
        
        return $content;
    }

    private function minifyContent(string $content): string
    {
        $contentMinified = $this->minificator->minify($content);
        $this->isMinified = true;

        return $contentMinified;
    }

    private function saveToCache(string $cacheFilePath, string &$content): void
    {
        $this->caching->cache($cacheFilePath, $content);
    }
    
    private function applySecurityMacros(string $content): string
    {
        $replacements = [
            self::CSP_NONCE_PLACEHOLDER => ($this->cspNonce ?? ''),
            self::CSP_HASH_PLACEHOLDER  => ($this->cspHash ?? '')
        ];

        return str_replace(
            array_keys($replacements),
            array_values($replacements),
            $content
        );
    }
    
    protected function renderTemplate(string $templatePath, array $data): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        
        try {
            include $templatePath;
        } catch (Throwable $e) {
            ob_end_clean();
            throw new RuntimeException("Ошибка рендеринга шаблона: " . $e->getMessage());
        }
        
        return ob_get_clean();
    }

    public function escape(string|array $value)
    {
        if (is_array($value)) {
            return array_map([$this, 'escapeString'], $value);
        }
        return $this->escapeString($value);
    }
    
    public function extend(string $layout): void
    {
        $this->layout = $layout;
    }
    
    public function setLayout(string $layout): void
    {
        $this->layout = $layout;
    }
    
    public function startSection(string $name): void
    {
        $this->currentSection = $name;
        ob_start();
    }
    
    public function endSection(): void
    {
        if ($this->currentSection) {
            $this->currentSections[$this->currentSection] = ob_get_clean();
            $this->currentSection = null;
        }
    }
    
    public function section(string $name): string
    {
        if (isset($this->currentSections[$name])) {
            return $this->currentSections[$name];
        }
        
        foreach (array_reverse($this->sectionsStack) as $sections) {
            if (isset($sections[$name])) {
                return $sections[$name];
            }
        }
        
        return '';
    }

    public function includeComponent(string $componentPath, array $data = []): void
    {
        extract($data, EXTR_SKIP);
        include $this->templateDir . $componentPath;
    }

    public function getPathPrefixAssets(): string
    {
        return $this->pathPrefixAssets;
    }

    public function getPathPrefixLocalStorage(): string
    {
        return $this->pathPrefixLocalStorage;
    }

    public function getStatusColor(string $status, array $statusConfig): string
    {
        foreach ($statusConfig as $typeConfig) {
            if (isset($typeConfig[$status])) {
                return $typeConfig[$status]['color'];
            }
        }
        
        return '#858796';
    }

    public function getStatusText(string $status, array $statusConfig): string
    {
        foreach ($statusConfig as $typeConfig) {
            if (isset($typeConfig[$status])) {
                return $typeConfig[$status]['text'];
            }
        }
        
        return $status;
    }

    private function escapeString(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }

    public function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    public function truncate(string $string, int $length, string $ellipsis = '...'): string
    {
        if (strlen($string) <= $length) {
            return $string;
        }
        return substr($string, 0, $length - strlen($ellipsis)) . $ellipsis;
    }
}