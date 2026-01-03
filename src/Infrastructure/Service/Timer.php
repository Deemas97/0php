<?php
namespace Infrastructure\Service;

use Core\Service\InfrastructureServiceInterface;

class Timer implements InfrastructureServiceInterface
{
    private static ?self $instance = null;
    private array $timers = [];
    private array $measurements = [];
    private bool $enabled = true;
    private float $startTime;

    private function __construct()
    {
        $this->startTime = microtime(true);
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Начать измерение
     */
    public function start(string $name, array $tags = []): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->timers[$name] = [
            'start' => microtime(true),
            'tags' => $tags,
            'memory_start' => memory_get_usage(true),
            'memory_peak_start' => memory_get_peak_usage(true),
        ];
    }

    /**
     * Остановить измерение и сохранить результат
     */
    public function stop(string $name): ?array
    {
        if (!$this->enabled || !isset($this->timers[$name])) {
            return null;
        }

        $endTime = microtime(true);
        $timer = $this->timers[$name];
        
        $duration = $endTime - $timer['start'];
        $memoryUsed = memory_get_usage(true) - $timer['memory_start'];
        $memoryPeak = memory_get_peak_usage(true) - $timer['memory_peak_start'];

        $measurement = [
            'name' => $name,
            'duration' => $duration,
            'duration_ms' => round($duration * 1000, 2),
            'memory_used' => $memoryUsed,
            'memory_used_mb' => round($memoryUsed / 1024 / 1024, 2),
            'memory_peak' => $memoryPeak,
            'memory_peak_mb' => round($memoryPeak / 1024 / 1024, 2),
            'tags' => $timer['tags'],
            'timestamp' => microtime(true),
        ];

        $this->measurements[$name][] = $measurement;
        unset($this->timers[$name]);

        return $measurement;
    }

    /**
     * Быстрое измерение времени выполнения callback
     */
    public function measure(string $name, callable $callback, array $tags = []): mixed
    {
        $this->start($name, $tags);
        try {
            $result = $callback();
            $this->stop($name);
            return $result;
        } catch (\Throwable $e) {
            $this->stop($name);
            throw $e;
        }
    }

    /**
     * Получить все измерения
     */
    public function getMeasurements(): array
    {
        return $this->measurements;
    }

    /**
     * Получить измерения по имени
     */
    public function getMeasurement(string $name): array
    {
        return $this->measurements[$name] ?? [];
    }

    /**
     * Получить общее время выполнения запроса
     */
    public function getRequestDuration(): float
    {
        return microtime(true) - $this->startTime;
    }

    /**
     * Получить общее время по всем измерениям
     */
    public function getTotalMeasuredTime(): float
    {
        $total = 0;
        foreach ($this->measurements as $measurements) {
            foreach ($measurements as $measurement) {
                $total += $measurement['duration'];
            }
        }
        return $total;
    }

    /**
     * Сбросить все измерения
     */
    public function reset(): void
    {
        $this->timers = [];
        $this->measurements = [];
        $this->startTime = microtime(true);
    }

    /**
     * Включить/выключить таймер
     */
    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }
}