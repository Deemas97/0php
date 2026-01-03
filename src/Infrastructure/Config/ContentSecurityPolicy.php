<?php
namespace Infrastructure\Config;

use Bootstrap\Config\ProjectMode;
use RuntimeException;

class ContentSecurityPolicy
{
    private static array $policies = [];
    private static bool $initialized = false;
    private static string $nonce;
    
    public static function initialize(): void
    {
        if (self::$initialized) {
            return;
        }
        
        self::$nonce = base64_encode(random_bytes(16));
        
        $policyFile = YADRO_PHP__ROOT_DIR . '/configs/content_security_policy.php';
        if (!file_exists($policyFile)) {
            throw new RuntimeException('CSP config file not found: ' . $policyFile);
        }
        
        $allPolicies = require $policyFile;
        $mode = ProjectMode::getCurrentMode();
        
        if ($mode === 'production') {
            self::$policies = $allPolicies['production'] ?? [];
        } elseif ($mode === 'test') {
            self::$policies = $allPolicies['test'] ?? $allPolicies['development'] ?? [];
        } else {
            self::$policies = $allPolicies['development'] ?? [];
        }
        
        self::$initialized = true;
    }
    
    public static function apply(): void
    {
        self::initialize();
        
        $directives = [];
        foreach (self::$policies as $directive => $sources) {
            if (!empty($sources)) {
                $processedSources = array_map(function($source) {
                    return str_replace('{nonce}', self::$nonce, $source);
                }, $sources);
                
                $directives[] = $directive . ' ' . implode(' ', $processedSources);
            }
        }
        
        if (!empty($directives)) {
            header('Content-Security-Policy: ' . implode('; ', $directives));
        }
    }
    
    public static function getNonce(): string
    {
        self::initialize();
        return self::$nonce;
    }
    
    public static function addPolicy(string $directive, string $source): void
    {
        self::initialize();
        
        if (!isset(self::$policies[$directive])) {
            self::$policies[$directive] = [];
        }
        
        if (!in_array($source, self::$policies[$directive])) {
            self::$policies[$directive][] = $source;
        }
    }
    
    public static function getPolicies(): array
    {
        self::initialize();
        return self::$policies;
    }
}