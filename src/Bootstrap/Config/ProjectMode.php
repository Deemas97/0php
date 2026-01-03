<?php
namespace Bootstrap\Config;

use InvalidArgumentException;

class ProjectMode
{
    private const ALLOWED_MODES = ['dev', 'test', 'production'];
    private static ?string $modeCurrent = null;

    public static function init(): void
    {
        $mode = DotEnv::getDataItem('APP_ENV');

        if (!in_array($mode, self::ALLOWED_MODES)) {
            throw new InvalidArgumentException(
                "Неизвестный режим проекта: $mode. Поддерживаются: " . implode(', ', self::ALLOWED_MODES)
            );
        }
        self::$modeCurrent = $mode;
    }

    public static function getCurrentMode(): string
    {
        return self::$modeCurrent;
    }

    public static function checkMode(string $mode): bool
    {
        return (self::$modeCurrent === $mode);
    }
}