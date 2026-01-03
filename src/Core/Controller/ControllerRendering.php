<?php
namespace Core\Controller;

use Core\Controller\ControllerAbstract;
use Core\Controller\ControllerResponse;
use Core\Service\Renderer;
use Core\View\View;

abstract class ControllerRendering extends ControllerAbstract
{
    public function __construct(
        protected Renderer $renderer
    )
    {}

    protected function render(string $templateName, array $data = []): ControllerResponse
    {
        $response = $this->initResponse();

        $content = $this->renderer->render($templateName, $data);
        $response->set('view_html', new View($content));

        return $response;
    }
}
