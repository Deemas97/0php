<?php
namespace Core\Service\Configurer;

use Bootstrap\Config\DotEnv;
use Core\Service\GzipCompressor;
use Infrastructure\Config\ContentSecurityPolicy;
use Infrastructure\Http\Protocol;
use Infrastructure\Jit\JitManager;
use Infrastructure\Service\DevModeManager;

class ModeStateDev implements ModeStateInterface
{
    public function init(): void
    {
        // === UTILITIES ===
        DevModeManager::init();
        // === /UTILITIES ===


        
        // === LOGGING ===
        // ERROR REPORTING
        ini_set('display_errors', '1');
        ini_set('display_startup_errors', '1');
        error_reporting(E_ALL);
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
        header('X-XSS-Protection: 0');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        ////
    
        // CSP
        ContentSecurityPolicy::apply();
        ////
        // === /SECURITY ===
        


        // === PERFORMANCE ===
        // JIT
        $jitProfile = DotEnv::getDataItem('JIT_PROFILE', 'function');
        JitManager::enable($jitProfile);
        ////

        // Redis
        // ...
        ////

        // GZIP CONFIGURATION
        $gzipEnabled = DotEnv::getDataItem('GZIP_ENABLED', '0') === '1';
        if ($gzipEnabled) {
            $gzipLevel = (int) DotEnv::getDataItem('GZIP_COMPRESSION_LEVEL', '6');
            GzipCompressor::init($gzipLevel);

            GzipCompressor::enableOutputCompression();
        }
        ////
        // === /PERFORMANCE ===



        // === FILE SYSTEM ===
        // ...
        // === /FILE SYSTEM ===



        // === DATA BASES ===
        // ...
        // === /DATA BASES ===



        // === QUEUES ===
        // ...
        // === /QUEUES ===
    }
}