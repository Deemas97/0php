<?php
namespace Core\Service\Configurer;

use Core\Service\GzipCompressor;
use Bootstrap\Config\DotEnv;
use Infrastructure\Cache\OpCacheManager;
use Infrastructure\Config\ContentSecurityPolicy;
use Infrastructure\Http\Protocol;
use Infrastructure\Jit\JitManager;
use Infrastructure\Service\DevModeManager;

class ModeStateTest implements ModeStateInterface
{
    public function init(): void
    {
        // === LOGGING ===
        // ERROR REPORTING
        ini_set('display_errors', '1');
        error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
        ////
        // === /LOGGING ===
        
        // === SECURITY ===
        // HTTPS
        if (Protocol::isHttpsForced()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
        ////

        // CORS-HEADERS
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        ////

        // SESSION
        ini_set('session.cookie_lifetime', '0');
        ////
        
        // CSP
        ContentSecurityPolicy::apply();
        ////
        // === /SECURITY ===

        
        
        // === PERFORMANCE ===
        // CACHE DISABLING
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');

        if (function_exists('opcache_reset')) {
            OpCacheManager::reset();
        }
        ////
        
        // JIT
        if (DotEnv::getDataItem('JIT_IN_TESTS', '0') === '1') {
            JitManager::enable('function');
        } else {
            JitManager::disable();
        }
        ////

        // GZIP
        $gzipInTests = DotEnv::getDataItem('GZIP_IN_TESTS', '0') === '1';
        if ($gzipInTests) {
            GzipCompressor::init(1);
        }
        ////
        // === /PERFORMANCE ===

        DevModeManager::init();
    }
}