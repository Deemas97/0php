#!/usr/bin/env php
<?php
define('YADRO_PHP__ROOT_DIR', realpath(__DIR__ . '/../../'));

require YADRO_PHP__ROOT_DIR . '/src/Bootstrap/Autoloader.php';

use Bootstrap\Config\DotEnv;
use Infrastructure\Cache\OpCacheManager;
use Infrastructure\Cli\CliViewer;

DotEnv::init(YADRO_PHP__ROOT_DIR);

OpCacheManager::reset();

CliViewer::success("Starting OpCache preloading...");

$directories = [
    YADRO_PHP__ROOT_DIR . '/src/Core',
    YADRO_PHP__ROOT_DIR . '/src/Infrastructure',
    YADRO_PHP__ROOT_DIR . '/src/App',
    YADRO_PHP__ROOT_DIR . '/src/Domain'
];

$totalFiles = 0;
$startTime = microtime(true);

foreach ($directories as $directory) {
    if (is_dir($directory)) {
        $relativePath = str_replace(YADRO_PHP__ROOT_DIR, '', $directory);
        CliViewer::info("Preloading: {$relativePath}");
        
        $dirStart = microtime(true);
        OpCacheManager::preloadDirectory($directory);
        $dirTime = round((microtime(true) - $dirStart) * 1000, 2);
        
        CliViewer::line(sprintf("  Completed in %.2f ms", $dirTime), 'dim');
    }
}

$totalTime = round((microtime(true) - $startTime) * 1000, 2);

CliViewer::separator('═', 40, 'cyan');
CliViewer::success("Preloading completed in {$totalTime} ms");

$monitorData = OpCacheManager::monitor();

CliViewer::display($monitorData, [
    'title' => 'OPCACHE STATISTICS',
    'format' => 'tree',
    'color' => true
]);

if (!empty($monitorData['recommendations'])) {
    CliViewer::display([
        'recommendations' => $monitorData['recommendations'],
        'count' => count($monitorData['recommendations'])
    ], [
        'title' => 'RECOMMENDATIONS',
        'format' => 'list'
    ]);
}

if (isset($monitorData['performance']['preload_age_seconds'])) {
    $age = $monitorData['performance']['preload_age_seconds'];
    if ($age !== null && $age > 3600) {
        CliViewer::warning(sprintf(
            "Last preload was %s ago",
            formatDuration($age)
        ));
    }
}

function formatDuration(int $seconds): string
{
    if ($seconds < 60) return "{$seconds} seconds";
    if ($seconds < 3600) return round($seconds / 60) . " minutes";
    if ($seconds < 86400) return round($seconds / 3600, 1) . " hours";
    return round($seconds / 86400, 1) . " days";
}