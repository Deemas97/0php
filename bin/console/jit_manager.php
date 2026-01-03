#!/usr/bin/env php
<?php
/**
 * JIT Manager CLI Tool
 * Usage:
 *   php bin/console/jit-manager status        - Show JIT status
 *   php bin/console/jit-manager enable        - Enable JIT with auto profile
 *   php bin/console/jit-manager enable <profile> - Enable specific profile
 *   php bin/console/jit-manager disable       - Disable JIT
 *   php bin/console/jit-manager benchmark     - Run performance benchmark
 *   php bin/console/jit-manager reset         - Reset JIT buffer
 *   php bin/console/jit-manager optimize      - Auto-optimize JIT settings
 */

define('YADRO_PHP__ROOT_DIR', realpath(__DIR__ . '/../../'));

require YADRO_PHP__ROOT_DIR . '/src/Bootstrap/Autoloader.php';

use Bootstrap\Config\DotEnv;
use Infrastructure\Cli\CliViewer;
use Infrastructure\Jit\JitManager;

DotEnv::init(YADRO_PHP__ROOT_DIR);

$command = $argv[1] ?? 'status';
$profile = $argv[2] ?? '';

CliViewer::display([
    'command' => $command,
    'profile' => $profile,
    'php_version' => PHP_VERSION,
    'sapi' => PHP_SAPI
], [
    'title' => 'JIT MANAGER',
    'format' => 'list',
    'compact' => true
]);

CliViewer::separator();

try {
    switch ($command) {
        case 'status':
            showJitStatus();
            break;
            
        case 'enable':
            enableJit($profile);
            break;
            
        case 'disable':
            disableJit();
            break;
            
        case 'benchmark':
            runBenchmark();
            break;
            
        case 'reset':
            resetJit();
            break;
            
        case 'optimize':
            optimizeJit();
            break;
            
        case 'cron':
            runCronTask();
            break;
            
        case 'help':
        default:
            showHelp();
    }
} catch (Exception $e) {
    CliViewer::error("Error: " . $e->getMessage());
    exit(1);
}

CliViewer::success("Command completed successfully");
exit(0);

function showJitStatus(): void
{
    $config = JitManager::getConfig();
    $stats = JitManager::getStats();
    $recommendations = JitManager::getRecommendations();
    
    CliViewer::display([
        'JIT Configuration' => $config,
        'JIT Statistics' => $stats,
        'Recommendations' => $recommendations
    ], [
        'title' => 'JIT STATUS',
        'format' => 'tree'
    ]);
    
    if (isset($stats['buffer_usage_percent'])) {
        $percent = $stats['buffer_usage_percent'];
        CliViewer::info("JIT Buffer Usage: {$percent}%");
        CliViewer::progress((int)$percent, 100, 40);
    }
    
    if (isset($stats['performance_impact'])) {
        $impact = $stats['performance_impact'];
        $color = $impact === 'high' ? 'green' : ($impact === 'moderate' ? 'yellow' : 'dim');
        CliViewer::line("Performance Impact: " . ucfirst($impact), $color);
    }
}

function enableJit(string $profile = ''): void
{
    if (empty($profile)) {
        $profile = JitManager::autoConfigure();
        CliViewer::success("JIT enabled with auto-configured profile: {$profile}");
    } else {
        JitManager::enable($profile);
        CliViewer::success("JIT enabled with profile: {$profile}");
    }
    
    $config = JitManager::getConfig();
    CliViewer::display($config, [
        'title' => 'CURRENT JIT CONFIG',
        'format' => 'list'
    ]);
}

function disableJit(): void
{
    if (JitManager::disable()) {
        CliViewer::success("JIT disabled");
    } else {
        CliViewer::warning("JIT was already disabled or not supported");
    }
}

function runBenchmark(): void
{
    CliViewer::info("Running JIT benchmark...");
    
    $testFunction = function() {
        $result = 0;
        for ($i = 0; $i < 1000; $i++) {
            $result += sin($i) * cos($i);
        }
        
        $str = '';
        for ($i = 0; $i < 100; $i++) {
            $str .= chr(rand(65, 90));
        }
        
        $arr = [];
        for ($i = 0; $i < 500; $i++) {
            $arr[$i] = $i * $i;
        }
        
        return $result + strlen($str) + count($arr);
    };
    
    $results = JitManager::benchmark($testFunction, 5000);
    
    $rows = [
        ['Mode', 'Time (s)', 'Speedup'],
        [
            'Without JIT',
            sprintf('%.4f', $results['without_jit']),
            '0% (baseline)'
        ],
        [
            'JIT Function',
            sprintf('%.4f', $results['with_jit_function']),
            sprintf('+%.1f%%', abs($results['function_speedup']))
        ],
        [
            'JIT Tracing',
            sprintf('%.4f', $results['with_jit_tracing']),
            sprintf('+%.1f%%', abs($results['tracing_speedup']))
        ]
    ];
    
    CliViewer::table(['Mode', 'Time', 'Speedup'], array_slice($rows, 1), ['left', 'right', 'right']);
    
    $bestSpeedup = max($results['tracing_speedup'], $results['function_speedup']);
    if ($bestSpeedup > 10) {
        CliViewer::success("JIT provides significant speedup: +{$bestSpeedup}%");
    } elseif ($bestSpeedup > 0) {
        CliViewer::info("JIT provides moderate speedup: +{$bestSpeedup}%");
    } else {
        CliViewer::warning("JIT didn't show improvement in this test");
    }
}

function resetJit(): void
{
    if (JitManager::reset()) {
        CliViewer::success("JIT buffer reset successfully");
    } else {
        CliViewer::warning("JIT reset failed or not enabled");
    }
}

function optimizeJit(): void
{
    CliViewer::info("Analyzing system for JIT optimization...");
    
    $memoryLimit = getMemoryLimitMB();
    $isCli = PHP_SAPI === 'cli';
    $cpuCores = getCpuCores();
    
    CliViewer::display([
        'System Information' => [
            'memory_limit_mb' => $memoryLimit,
            'cpu_cores' => $cpuCores,
            'sapi' => PHP_SAPI,
            'php_version' => PHP_VERSION,
            'opcache_enabled' => extension_loaded('opcache') && ini_get('opcache.enable')
        ]
    ], [
        'format' => 'list',
        'compact' => true
    ]);
    
    $profile = JitManager::autoConfigure();
    CliViewer::success("JIT optimized with profile: {$profile}");
    
    $recommendations = [];
    
    if ($memoryLimit < 256) {
        $recommendations[] = "Low memory ({$memoryLimit}MB). Consider increasing memory_limit for better JIT performance";
    }
    
    if ($cpuCores < 2) {
        $recommendations[] = "Single CPU core detected. JIT benefits from multiple cores";
    }
    
    if (!empty($recommendations)) {
        CliViewer::display([
            'System Recommendations' => $recommendations
        ], [
            'format' => 'list',
            'title' => 'RECOMMENDATIONS'
        ]);
    }
}

function runCronTask(): void
{
    CliViewer::info("Running JIT cron maintenance task...");
    
    $startTime = microtime(true);
    $actions = [];
    
    $config = JitManager::getConfig();
    $stats = JitManager::getStats();
    
    if (isset($stats['buffer_usage_percent']) && $stats['buffer_usage_percent'] > 90) {
        JitManager::reset();
        $actions[] = 'JIT buffer reset (usage > 90%)';
    }
    
    $currentProfile = $config['jit_mode'] ?? 'disabled';
    $recommendedProfile = getRecommendedProfile();
    
    if ($currentProfile !== $recommendedProfile) {
        JitManager::enable($recommendedProfile);
        $actions[] = "JIT profile changed: {$currentProfile} → {$recommendedProfile}";
    }
    
    $logData = [
        'timestamp' => date('Y-m-d H:i:s'),
        'config' => $config,
        'stats' => $stats,
        'actions' => $actions,
        'duration' => round(microtime(true) - $startTime, 4)
    ];
    
    $logFile = YADRO_PHP__ROOT_DIR . '/var/log/jit/jit_cron_' . date('Y-m-d') . '.json';
    
    if (!is_dir(dirname($logFile))) {
        mkdir(dirname($logFile), 0755, true);
    }
    
    $existing = [];
    if (file_exists($logFile)) {
        $existing = json_decode(file_get_contents($logFile), true) ?: [];
    }
    
    $existing[] = $logData;
    file_put_contents($logFile, json_encode($existing, JSON_PRETTY_PRINT));
    
    CliViewer::display([
        'Cron Task Results' => [
            'actions_taken' => count($actions),
            'duration' => $logData['duration'] . 's',
            'log_file' => str_replace(YADRO_PHP__ROOT_DIR, '', $logFile),
            'jit_status' => $config['enabled'] ? 'enabled' : 'disabled'
        ]
    ], [
        'format' => 'list',
        'title' => 'CRON TASK COMPLETED'
    ]);
    
    if (!empty($actions)) {
        CliViewer::display(['Actions' => $actions], ['format' => 'list']);
    }
}

function showHelp(): void
{
    CliViewer::display([
        'Available Commands' => [
            'status' => 'Show JIT status and statistics',
            'enable [profile]' => 'Enable JIT (auto or specific profile: tracing, function, experimental)',
            'disable' => 'Disable JIT',
            'benchmark' => 'Run performance benchmark',
            'reset' => 'Reset JIT buffer',
            'optimize' => 'Auto-optimize JIT settings based on system',
            'cron' => 'Run maintenance cron task',
            'help' => 'Show this help message'
        ],
        'Available Profiles' => [
            'tracing' => 'Best for production, optimizes hot code paths',
            'function' => 'Good for development, lower memory usage',
            'experimental' => 'Aggressive optimization for CLI/benchmarks'
        ],
        'Examples' => [
            'Enable tracing JIT' => 'php bin/jit-manager enable tracing',
            'Run benchmark' => 'php bin/jit-manager benchmark',
            'Cron maintenance' => 'php bin/jit-manager cron'
        ]
    ], [
        'title' => 'JIT MANAGER HELP',
        'format' => 'tree'
    ]);
}

function getMemoryLimitMB(): int
{
    $limit = ini_get('memory_limit');
    
    if ($limit === '-1') {
        return 1024 * 1024;
    }
    
    $unit = strtoupper(substr($limit, -1));
    $value = (int)substr($limit, 0, -1);
    
    switch ($unit) {
        case 'G': return $value * 1024;
        case 'M': return $value;
        case 'K': return (int)($value / 1024);
        default: return (int)($value / 1024 / 1024);
    }
}

function getCpuCores(): int
{
    if (PHP_OS_FAMILY === 'Windows') {
        return (int)getenv('NUMBER_OF_PROCESSORS');
    }
    
    if (is_file('/proc/cpuinfo')) {
        return (int)exec('grep -c ^processor /proc/cpuinfo');
    }
    
    return 1;
}

function getRecommendedProfile(): string
{
    $memory = getMemoryLimitMB();
    $isCli = PHP_SAPI === 'cli';
    
    if ($isCli && $memory > 512) {
        return 'experimental';
    }
    
    if ($memory > 256) {
        return 'tracing';
    }
    
    return 'function';
}