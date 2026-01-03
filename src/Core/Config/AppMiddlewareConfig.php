<?php
namespace Core\Config;

use RuntimeException;

class AppMiddlewareConfig
{
    private array $config = [];
    private array $enabled = [];
    private array $priorities = [];
    private bool $isLoaded = false;

    public function __construct(private string $configPath) {}

    public function load(): array
    {
        if ($this->isLoaded) {
            return $this->config;
        }

        if (!file_exists($this->configPath)) {
            throw new RuntimeException(
                sprintf('App middleware config file not found: %s', $this->configPath)
            );
        }

        $config = require $this->configPath;

        if (!is_array($config)) {
            throw new RuntimeException(
                sprintf('App middleware config must return array, %s given', gettype($config))
            );
        }

        $this->config = $config;
        $this->parseConfig();
        $this->isLoaded = true;

        return $this->config;
    }

    private function parseConfig(): void
    {
        foreach ($this->config as $middlewareClass => $settings) {
            if (!is_array($settings)) {
                throw new RuntimeException(
                    sprintf('Settings for middleware %s must be array', $middlewareClass)
                );
            }

            $enabled = $settings['enabled'] ?? true;
            if ($enabled) {
                $this->enabled[] = $middlewareClass;
            }

            if (isset($settings['priority'])) {
                $this->priorities[$middlewareClass] = (int) $settings['priority'];
            }

            $this->config[$middlewareClass] = $settings;
        }
    }

    public function getEnabled(): array
    {
        $this->load();
        return $this->enabled;
    }

    public function getPriorities(): array
    {
        $this->load();
        return $this->priorities;
    }

    public function getConfig(string $middlewareClass): ?array
    {
        $this->load();
        return $this->config[$middlewareClass] ?? null;
    }

    public function isEnabled(string $middlewareClass): bool
    {
        $this->load();
        return in_array($middlewareClass, $this->enabled);
    }
}