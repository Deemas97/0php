<?php
namespace Core\Middleware;

use Core\Container\ControllerContainer;
use Core\Container\SharingContainer;
use Core\MessageBus\MessageBusInterface;
use Core\MessageBus\ResponseInterface;
use Core\MessageBus\Response;
use Core\Service\AuthService;
use Core\Service\CsrfService;
use ErrorException;

final class ActionsMiddleware implements CoreMiddlewareInterface
{
    public function __construct(
        private ControllerContainer $container,
        private SharingContainer $containerSharing,
        private CsrfService $csrfService,
        private AuthService $authService
    )
    {
        $this->container->set(SharingContainer::class, $this->containerSharing);
        $this->container->set(CsrfService::class, $this->csrfService);
        $this->container->set(AuthService::class, $this->authService);
    }

    public function process(MessageBusInterface $messageBus): ResponseInterface
    {
        $response = new Response();
        
        $controllerName = $messageBus->get('controllerName');
        $methodName     = $messageBus->get('methodName');
        $controllerMethodParams = $messageBus->get('controllerMethodParams') ?? [];

        $controller = $this->container->get($controllerName);
        
        try {
            $controllerResponse = $controller->$methodName(...$controllerMethodParams);
        } catch (ErrorException $error) {
            throw $error;
        }
    
        $controllerResponseDump = $controllerResponse->getAll();

        $responseType = ((isset($controllerResponseDump['is_json']) && ($controllerResponseDump['is_json'] === true)) ? 'api_response' : 'html_response');

        $response->set('type',         $responseType);
        $response->set('response',     $controllerResponseDump);
        $response->set('status',       $controllerResponse->getStatusCode());

        $response->set('request_time', $messageBus->get('request_time') ?? null);
        $response->set('request_id',   $messageBus->get('request_id') ?? null);

        return $response;
    }
}