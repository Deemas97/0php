<?php
namespace Infrastructure\Cli;

class CliViewer
{
    private const COLORS = [
        'reset' => "\033[0m",
        'black' => "\033[30m",
        'red' => "\033[31m",
        'green' => "\033[32m",
        'yellow' => "\033[33m",
        'blue' => "\033[34m",
        'magenta' => "\033[35m",
        'cyan' => "\033[36m",
        'white' => "\033[37m",
        'bold' => "\033[1m",
        'dim' => "\033[2m",
        'underline' => "\033[4m",
        'reverse' => "\033[7m",
    ];

    private const TYPE_STYLES = [
        'integer' => 'yellow',
        'double' => 'yellow',
        'float' => 'yellow',
        'string' => 'green',
        'boolean' => 'magenta',
        'NULL' => 'dim',
        'array' => 'cyan',
        'object' => 'blue',
    ];

    private const SYMBOLS = [
        'pointer' => '↳',
        'array' => '[]',
        'object' => '{}',
        'true' => '✓',
        'false' => '✗',
        'null' => '∅',
        'ellipsis' => '…',
        'vertical' => '│',
        'horizontal' => '─',
        'corner' => '└',
        'branch' => '├',
        'cross' => '┼',
    ];

    public static function display($data, array $options = []): void
    {
        $defaultOptions = [
            'title' => null,
            'format' => 'auto', // auto, tree, table, json, yaml, list
            'depth' => 10,
            'color' => true,
            'compact' => false,
            'show_types' => false,
            'max_string_length' => 100,
            'max_items' => 50,
            'indent' => 2,
            'collapse' => false, // Сворачивать ли длинные структуры
        ];

        $options = array_merge($defaultOptions, $options);

        echo "\n";

        if ($options['title']) {
            self::printTitle($options['title'], $options['color']);
        }

        if ($options['format'] === 'auto') {
            $options['format'] = self::detectBestFormat($data);
        }

        switch ($options['format']) {
            case 'tree':
                self::displayTree($data, $options);
                break;
            case 'table':
                self::displayTable($data, $options);
                break;
            case 'json':
                self::displayJson($data, $options);
                break;
            case 'yaml':
                self::displayYaml($data, $options);
                break;
            case 'list':
                self::displayList($data, $options);
                break;
            case 'inline':
                self::displayInline($data, $options);
                break;
            default:
                self::displayTree($data, $options);
        }

        echo "\n";
    }

    private static function displayTree($data, array $options, int $level = 0, string $prefix = ''): void
    {
        $type = gettype($data);
        $isLast = true;
        
        switch ($type) {
            case 'array':
            case 'object':
                if (is_object($data)) {
                    $data = self::objectToArray($data);
                }
                
                $count = count($data);
                
                if ($count === 0) {
                    echo $prefix . self::style("empty $type", 'dim', $options['color']);
                    return;
                }
                
                if ($options['collapse'] && $count > 10 && $level > 0) {
                    echo $prefix . self::style("$type ($count items)", 'dim', $options['color']);
                    return;
                }
                
                $i = 0;
                foreach ($data as $key => $value) {
                    $isLast = (++$i === $count);
                    
                    $branch = $isLast ? self::SYMBOLS['corner'] : self::SYMBOLS['branch'];
                    $linePrefix = $prefix . $branch . str_repeat(self::SYMBOLS['horizontal'], 2);
                    $nextPrefix = $prefix . ($isLast ? ' ' : self::SYMBOLS['vertical']) . ' ';
                    
                    $keyDisplay = self::formatKey($key, $options);
                    echo $linePrefix . $keyDisplay;
                    
                    $valueType = gettype($value);
                    
                    if (is_scalar($value) || $value === null) {
                        echo ': ' . self::formatValue($value, $options);
                        echo "\n";
                    } else {
                        echo ': ' . self::style("$valueType", self::TYPE_STYLES[$valueType] ?? 'dim', $options['color']);
                        
                        if (in_array($valueType, ['array', 'object'])) {
                            $subCount = is_array($value) ? count($value) : count(get_object_vars($value));
                            echo ' ' . self::style("($subCount)", 'dim', $options['color']);
                        }
                        
                        echo "\n";
                        
                        if ($level < $options['depth']) {
                            self::displayTree($value, $options, $level + 1, $nextPrefix);
                        }
                    }
                }
                break;
                
            default:
                echo $prefix . self::formatValue($data, $options) . "\n";
        }
    }

    private static function displayTable($data, array $options): void
    {
        if (!is_array($data) || empty($data)) {
            self::displayTree($data, $options);
            return;
        }

        $firstRow = reset($data);
        if (!is_array($firstRow) && !is_object($firstRow)) {
            self::displayList($data, $options);
            return;
        }

        $rows = [];
        foreach ($data as $row) {
            if (is_object($row)) {
                $rows[] = self::objectToArray($row);
            } else {
                $rows[] = $row;
            }
        }

        $headers = [];
        foreach ($rows as $row) {
            $headers = array_merge($headers, array_keys($row));
        }
        $headers = array_unique($headers);
        
        if (count($rows) > $options['max_items']) {
            $rows = array_slice($rows, 0, $options['max_items']);
            $truncated = true;
        }

        $widths = [];
        foreach ($headers as $header) {
            $widths[$header] = strlen($header);
        }
        
        foreach ($rows as $row) {
            foreach ($headers as $header) {
                $value = $row[$header] ?? '';
                $display = self::formatValue($value, array_merge($options, ['compact' => true]));
                $widths[$header] = max($widths[$header], strlen($display));
            }
        }

        foreach ($widths as &$width) {
            $width += 2;
        }

        self::printTableBorder($widths, 'top', $options['color']);
        
        echo self::SYMBOLS['vertical'];
        foreach ($headers as $header) {
            $width = $widths[$header];
            $padding = $width - strlen($header) - 1;
            echo ' ' . self::style($header, 'bold|cyan', $options['color']) . 
                 str_repeat(' ', $padding) . self::SYMBOLS['vertical'];
        }
        echo "\n";

        self::printTableBorder($widths, 'middle', $options['color']);

        foreach ($rows as $rowIndex => $row) {
            echo self::SYMBOLS['vertical'];
            foreach ($headers as $header) {
                $value = $row[$header] ?? '';
                $display = self::formatValue($value, array_merge($options, ['compact' => true]));
                $width = $widths[$header];
                $padding = $width - strlen($display) - 1;
                echo ' ' . $display . str_repeat(' ', $padding) . self::SYMBOLS['vertical'];
            }
            echo "\n";
        }

        self::printTableBorder($widths, 'bottom', $options['color']);

        if (!empty($truncated)) {
            echo self::style("(showing " . count($rows) . " of " . count($data) . " rows)", 'dim', $options['color']) . "\n";
        }
    }

    private static function displayList($data, array $options): void
    {
        if (!is_array($data) && !is_object($data)) {
            echo self::formatValue($data, $options) . "\n";
            return;
        }

        if (is_object($data)) {
            $data = self::objectToArray($data);
        }

        $maxKeyLength = 0;
        foreach (array_keys($data) as $key) {
            $maxKeyLength = max($maxKeyLength, strlen((string)$key));
        }

        foreach ($data as $key => $value) {
            $keyDisplay = str_pad((string)$key, $maxKeyLength);
            $keyStyled = self::style($keyDisplay, 'blue', $options['color']);
            
            echo "  " . $keyStyled . " : " . self::formatValue($value, $options) . "\n";
        }
    }

    private static function displayJson($data, array $options): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
        if ($options['color']) {
            $json = self::highlightJson($json);
        }
        
        echo $json . "\n";
    }

    private static function displayYaml($data, array $options, int $level = 0): void
    {
        $indent = str_repeat(' ', $level * $options['indent']);
        
        if (is_array($data) || is_object($data)) {
            if (is_object($data)) {
                $data = self::objectToArray($data);
            }
            
            foreach ($data as $key => $value) {
                echo $indent . self::formatKey($key, $options) . ":";
                
                if (is_array($value) || is_object($value)) {
                    echo "\n";
                    self::displayYaml($value, $options, $level + 1);
                } else {
                    echo " " . self::formatValue($value, $options) . "\n";
                }
            }
        } else {
            echo $indent . self::formatValue($data, $options) . "\n";
        }
    }

    private static function displayInline($data, array $options): void
    {
        echo self::formatValue($data, array_merge($options, ['compact' => true])) . "\n";
    }

    private static function formatKey($key, array $options): string
    {
        $keyStr = (string)$key;
        
        if (is_int($key)) {
            return self::style("[$keyStr]", 'yellow', $options['color']);
        }
        
        return self::style($keyStr, 'blue', $options['color']);
    }

    private static function formatValue($value, array $options): string
    {
        $type = gettype($value);
        $style = self::TYPE_STYLES[$type] ?? 'reset';
        
        switch ($type) {
            case 'boolean':
                $display = $value ? self::SYMBOLS['true'] . ' true' : self::SYMBOLS['false'] . ' false';
                break;
            case 'NULL':
                $display = self::SYMBOLS['null'] . ' null';
                break;
            case 'string':
                $display = self::formatString($value, $options);
                break;
            case 'integer':
            case 'double':
            case 'float':
                $display = (string)$value;
                break;
            case 'array':
                $count = count($value);
                $display = "array($count)";
                $style = 'cyan|dim';
                break;
            case 'object':
                $class = get_class($value);
                $display = "$class object";
                $style = 'blue|dim';
                break;
            default:
                $display = (string)$value;
        }
        
        if ($options['show_types'] && !in_array($type, ['string', 'integer', 'double', 'float', 'boolean'])) {
            $display .= ' ' . self::style("[$type]", 'dim', $options['color']);
        }
        
        return self::style($display, $style, $options['color']);
    }

    private static function formatString(string $value, array $options): string
    {
        $value = addcslashes($value, "\0..\37");
        
        if (strlen($value) > $options['max_string_length']) {
            $value = substr($value, 0, $options['max_string_length']) . self::SYMBOLS['ellipsis'];
        }
        
        return '"' . $value . '"';
    }

    private static function style(string $text, string $style, bool $useColor): string
    {
        if (!$useColor) {
            return $text;
        }
        
        $styles = explode('|', $style);
        $result = '';
        
        foreach ($styles as $s) {
            if (isset(self::COLORS[$s])) {
                $result .= self::COLORS[$s];
            }
        }
        
        return $result . $text . self::COLORS['reset'];
    }

    private static function objectToArray($object): array
    {
        if (method_exists($object, 'toArray')) {
            return $object->toArray();
        }
        
        if ($object instanceof \JsonSerializable) {
            return (array)$object->jsonSerialize();
        }
        
        return get_object_vars($object);
    }

    private static function detectBestFormat($data): string
    {
        $type = gettype($data);
        
        if ($type === 'array') {
            if (count($data) > 0) {
                $first = reset($data);
                if (is_array($first) || is_object($first)) {
                    $keys = null;
                    $isTable = true;
                    
                    foreach ($data as $item) {
                        if (is_object($item)) {
                            $itemKeys = array_keys(self::objectToArray($item));
                        } elseif (is_array($item)) {
                            $itemKeys = array_keys($item);
                        } else {
                            $isTable = false;
                            break;
                        }
                        
                        if ($keys === null) {
                            $keys = $itemKeys;
                        } elseif ($keys != $itemKeys) {
                            $isTable = false;
                            break;
                        }
                    }
                    
                    if ($isTable && count($data) > 1) {
                        return 'table';
                    }
                }
            }
            
            if (self::isAssoc($data)) {
                return 'tree';
            }
            
            return 'list';
        }
        
        if ($type === 'object') {
            return 'tree';
        }
        
        return 'inline';
    }

    private static function isAssoc(array $array): bool
    {
        if (empty($array)) {
            return false;
        }
        
        return array_keys($array) !== range(0, count($array) - 1);
    }

    private static function printTitle(string $title, bool $useColor): void
    {
        $line = str_repeat('═', strlen($title) + 4);
        echo self::style($line, 'cyan', $useColor) . "\n";
        echo self::style('  ' . $title . '  ', 'bold|cyan', $useColor) . "\n";
        echo self::style($line, 'cyan', $useColor) . "\n\n";
    }

    private static function printTableBorder(array $widths, string $type, bool $useColor): void
    {
        $chars = [
            'top' => ['left' => '┌', 'middle' => '┬', 'right' => '┐', 'fill' => '─'],
            'middle' => ['left' => '├', 'middle' => '┼', 'right' => '┤', 'fill' => '─'],
            'bottom' => ['left' => '└', 'middle' => '┴', 'right' => '┘', 'fill' => '─'],
        ];
        
        $c = $chars[$type] ?? $chars['middle'];
        
        echo self::style($c['left'], 'dim', $useColor);
        
        $first = true;
        foreach ($widths as $width) {
            if (!$first) {
                echo self::style($c['middle'], 'dim', $useColor);
            }
            echo self::style(str_repeat($c['fill'], $width), 'dim', $useColor);
            $first = false;
        }
        
        echo self::style($c['right'], 'dim', $useColor) . "\n";
    }

    private static function highlightJson(string $json): string
    {
        $patterns = [
            '/"([^"\\\\]|\\\\.)*"/' => self::COLORS['green'],
            '/\b-?\d+(\.\d+)?([eE][+-]?\d+)?\b/' => self::COLORS['yellow'],
            '/\b(true|false|null)\b/' => self::COLORS['magenta'],
            '/([\[\]\{\},:])/' => self::COLORS['dim'],
        ];
        
        foreach ($patterns as $pattern => $color) {
            $json = preg_replace($pattern, $color . '$0' . self::COLORS['reset'], $json);
        }
        
        return $json;
    }
    
    public static function dump($data, array $options = []): void
    {
        $options['title'] = $options['title'] ?? 'DEBUG DUMP';
        $options['format'] = $options['format'] ?? 'tree';
        $options['show_types'] = $options['show_types'] ?? true;
        
        self::display($data, $options);
    }

    public static function table(array $headers, array $rows, array $options = []): void
    {
        $data = [];
        foreach ($rows as $row) {
            $data[] = array_combine($headers, $row);
        }
        
        $options['format'] = 'table';
        $options['title'] = $options['title'] ?? 'TABLE';
        
        self::display($data, $options);
    }

    public static function json($data, array $options = []): void
    {
        $options['format'] = 'json';
        $options['title'] = $options['title'] ?? 'JSON';
        
        self::display($data, $options);
    }

    public static function yaml($data, array $options = []): void
    {
        $options['format'] = 'yaml';
        $options['title'] = $options['title'] ?? 'YAML';
        
        self::display($data, $options);
    }

    public static function success(string $message): void
    {
        echo self::COLORS['green'] . "✓ " . $message . self::COLORS['reset'] . "\n";
    }

    public static function error(string $message): void
    {
        echo self::COLORS['red'] . "✗ " . $message . self::COLORS['reset'] . "\n";
    }

    public static function info(string $message): void
    {
        echo self::COLORS['cyan'] . "ℹ " . $message . self::COLORS['reset'] . "\n";
    }

    public static function warning(string $message): void
    {
        echo self::COLORS['yellow'] . "⚠ " . $message . self::COLORS['reset'] . "\n";
    }

    public static function line(string $message = '', string $color = 'reset'): void
    {
        echo self::style($message, $color, true) . "\n";
    }

    public static function separator(string $char = '─', int $length = 60, string $color = 'dim'): void
    {
        echo self::style(str_repeat($char, $length), $color, true) . "\n";
    }

    public static function progress(int $current, int $total, int $width = 50): void
    {
        $percent = $total > 0 ? ($current / $total) * 100 : 100;
        $filled = round(($percent / 100) * $width);
        
        $bar = '';
        for ($i = 0; $i < $width; $i++) {
            if ($i < $filled) {
                $color = $percent >= 90 ? 'green' : ($percent >= 70 ? 'yellow' : 'blue');
                $bar .= self::style("█", $color, true);
            } else {
                $bar .= self::style("░", 'dim', true);
            }
        }
        
        echo sprintf("\r[%s] %3d%% (%d/%d)", $bar, $percent, $current, $total);
        
        if ($current >= $total) {
            echo "\n";
        }
    }
}