<?php
namespace Dev;

use Infrastructure\Service\DevModeManager;

class Dumper
{
    public static function dump(mixed $var, bool $withTrace = false): void
    {
        if (!DevModeManager::isAccessAllowed()) {
            return;
        }
        
        echo '<div style="margin: 10px; padding: 10px; background: #f5f5f5; border: 1px solid #ddd;">';
        echo '<pre style="margin: 0;">';
        highlight_string("<?php\n" . var_export($var, true));
        echo '</pre>';
        
        if ($withTrace) {
            self::trace();
        }
        
        echo '</div>';
    }
    
    public static function trace(int $limit = 5): void
    {
        if (!DevModeManager::isAccessAllowed()) {
            return;
        }
        
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $limit + 2);
        
        echo '<div style="margin: 10px; padding: 10px; background: #f0f0f0; border-left: 3px solid #ccc;">';
        echo '<strong>Backtrace:</strong><br>';
        foreach ($trace as $i => $frame) {
            if ($i < 2) continue;
            
            $file = $frame['file'] ?? 'internal';
            $line = $frame['line'] ?? '?';
            $function = $frame['function'] ?? 'unknown';
            
            $file = str_replace(
                [YADRO_PHP__ROOT_DIR, $_SERVER['DOCUMENT_ROOT'] ?? ''],
                ['[ROOT]', '[DOCROOT]'],
                $file
            );
            
            echo "#" . ($i-2) . " {$file}:{$line} — {$function}<br>";
        }
        echo '</div>';
    }

    public static function log(mixed $var, string $file = 'dumper.log'): void
    {
        file_put_contents(
            $file,
            '[' . date('Y-m-d H:i:s') . '] ' . print_r($var, true) . "\n",
            FILE_APPEND
        );
    }
}