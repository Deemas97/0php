<?php
namespace Core\Service\Configurer;

use Bootstrap\Config\DotEnv;
use Core\Service\GzipCompressor;
use Infrastructure\Config\ContentSecurityPolicy;
use Infrastructure\Http\Protocol;
use Infrastructure\Jit\JitManager;

class ModeStateProduction implements ModeStateInterface
{
    public function init(): void
    {
        // === LOGGING ===
        // ERROR REPORTING
        ini_set('display_errors', '0');
        error_reporting(0);
        ////
        // === /LOGGING ===
    


        // === SECURITY ===
        // HTTPS
        if (Protocol::isHttps() || Protocol::isHttpsForced()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
        ////

        // CORS-HEADERS
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('X-XSS-Protection: 0');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        ////
    
        // CSP
        ContentSecurityPolicy::apply();
        ////
        // === /SECURITY ===
        


        // === PERFORMANCE ===
        // JIT
        JitManager::autoConfigure();
        ////

        // GZIP COMPRESSION
        $gzipEnabled = DotEnv::getDataItem('GZIP_ENABLED', '0') === '1';
        if ($gzipEnabled) {
            GzipCompressor::init(6); // Баланс между скоростью и степенью сжатия
            GzipCompressor::enableOutputCompression();
        }
        ////
        // === /PERFORMANCE ===
    }
}