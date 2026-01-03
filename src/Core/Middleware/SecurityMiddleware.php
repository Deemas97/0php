<?php
namespace Core\Middleware;

use Core\MessageBus\MessageBusInterface;
use Core\Security\AuthAttribute;
use Core\Security\CsrfAttribute;
use Core\Service\AuthService;
use Core\Service\SessionManager;
use ReflectionMethod;
use RuntimeException;

final class SecurityMiddleware implements CoreMiddlewareInterface
{
    public function __construct(
        private AuthService $authService,
        private SessionManager $sessionManager
    )
    {}

    public function process(MessageBusInterface $messageBus): MessageBusInterface
    {
        $reflectionMethod = $messageBus->get('reflectionMethod');
        
        if (!$reflectionMethod instanceof ReflectionMethod) {
            throw new RuntimeException("ReflectionMethod is not provided in MessageBus");
        }

        $methodAttributes = $this->validateMethodAttributes($reflectionMethod, $messageBus);
        
        $messageBus->set('controllerMethodAttributes', $methodAttributes);
        
        if ($methodAttributes['requiresAuth']) {
            $this->addUserInfoToMessageBus($messageBus);
        }
        
        return $messageBus;
    }

    private function validateMethodAttributes(ReflectionMethod $method, MessageBusInterface $messageBus): array
    {
        $attributes = [
            'requiresCSRF' => false,
            'csrfAjaxOnly' => false,

            'requiresAuth' => false,
            'authTable' => '',
            'authRoles' => [],
            'authStatus' => '',
            'authStrict' => true
        ];

        $csrfAttributes = $method->getAttributes(CsrfAttribute::class);
        if (!empty($csrfAttributes)) {
            $csrfAttr = $csrfAttributes[0]->newInstance();
            $attributes['requiresCSRF'] = $csrfAttr->enabled;
            $attributes['csrfAjaxOnly'] = $csrfAttr->ajaxOnly;
            $messageBus->set('csrf_ajax', $attributes['csrfAjaxOnly']);

            if ($csrfAttr->enabled) {
                $this->validateCSRFToken($messageBus);
            }
        }

        $authAttributes = $method->getAttributes(AuthAttribute::class);
        if (!empty($authAttributes)) {
            $authAttr = $authAttributes[0]->newInstance();
            $attributes['requiresAuth'] = true;
            $attributes['authTable'] = $authAttr->table;
            $attributes['authRoles'] = $authAttr->roles;
            $attributes['authStatus'] = $authAttr->status;
            $attributes['authStrict'] = $authAttr->strict;

            $this->validateAuth($authAttr->table, $authAttr->roles, $authAttr->status, $authAttr->strict);
        }

        return $attributes;
    }

    private function validateCSRFToken(MessageBusInterface $messageBus): void
    {
        $headers = $messageBus->get('headers') ?? [];
        $serverData = $messageBus->get('server_data') ?? [];
        
        if ($messageBus->get('csrf_ajax') === true) {
            $isAjax = !empty($serverData['HTTP_X_REQUESTED_WITH']) 
                && strtolower($serverData['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

            if (!$isAjax) {
                $this->redirect('/error_403');
                
            }
        }
        
        $csrfToken = (($headers['X-CSRF-TOKEN'] ?? null) ?? ($headers['X-XSRF-TOKEN'] ?? null));

        if (!$csrfToken) {
            $this->redirect('/error_403');
        }

        if (!$this->sessionManager->validateCsrfToken($csrfToken)) {
            $this->redirect('/error_403');
        }
    }

    private function validateAuth(string $table, array $requiredRoles = [], string $requiredStatus = '', bool $strict = true): void
    {
        $this->authService->setUserTable($table);

        if (!$this->authService->isAuthenticated()) {
            $this->redirect('/login');
        }

        $user = $this->authService->getUser();

        if (!$user) {
            $this->redirect('/login');
        }

        if ($requiredStatus !== '') {
            $userStatus = $user->getStatus();

            switch ($userStatus) {
                case 'premoderation':
                    $this->redirect('/premoderation_info');
                case 'banned':
                    $this->redirect('/ban_info');
                case null:
                case '':
                    $this->redirect('/crash');
            }

            if ($userStatus !== $requiredStatus) {
                $this->redirect('/crash');
            }
        }

        if (!empty($requiredRoles)) {
            $userRole = $user->getRole();

            if (is_string($userRole)) {
                $hasRequiredRole = in_array($userRole, $requiredRoles);
            } elseif (is_array($userRole)) {
                if ($strict) {
                    $hasRequiredRole = empty(array_diff($requiredRoles, $userRole));
                } else {
                    $hasRequiredRole = !empty(array_intersect($requiredRoles, $userRole));
                }
            } else {
                $hasRequiredRole = false;
            }

            if (!$hasRequiredRole) {
                $this->redirect('/error_403');
            }
        }
    }

    private function addUserInfoToMessageBus(MessageBusInterface $messageBus): void
    {
        $isAuthenticated = $this->authService->isAuthenticated();
        $messageBus->set('user_authenticated', $isAuthenticated);
        
        if ($isAuthenticated) {
            $user = $this->authService->getUser();
            if ($user) {
                $messageBus->set('user', $user);
                $messageBus->set('user_id', $user->getId());
                $messageBus->set('user_email', $user->getEmail());
                $messageBus->set('user_name', $user->getName());
                
                if (method_exists($user, 'getRole')) {
                    $role = $user->getRole();
                    $messageBus->set('user_role', $role);
                    $messageBus->set('user_roles', is_array($role) ? $role : [$role]);
                }
            }
        }
    }

    protected function redirect(string $path): void
    {
        header('Location: ' . $path);
        exit;
    }
}