<?php
namespace Core\Config;

class RoutesConfig
{
    private int $dataPointer = -1;
    private array  $data = [];

    public function __construct()
    {
        require_once YADRO_PHP__ROOT_DIR . '/configs/routes.php';

        if (!empty(ROUTES_CONFIG)) {
            $this->data = ROUTES_CONFIG;
            $this->dataPointer = 0;
        }
    }

    public function extractRouteData(bool $isMoving = true): ?array
    {
        $routeData = (($this->dataPointer !== -1) ? $this->data[$this->dataPointer] : null);

        if ($isMoving === true) {
            $this->movePointer();
        }

        return $routeData;
    }

    public function reset()
    {
        if (!empty($data)) {
            $this->dataPointer = 0;
        }
    }

    private function movePointer(): void
    {
        if ($this->dataPointer !== -1) {
            if (isset($this->data[$this->dataPointer + 1])) {
                $this->dataPointer++;
            } else {
                $this->dataPointer = -1;
            }
        }
    }
}