<?php
namespace Core\Service;

class CsrfService implements CoreServiceInterface
{
    public function __construct(
        private SessionManager $sessionManager
    )
    {}
    
    public function validateToken(bool $ajaxOnly = false): bool
    {
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && !$ajaxOnly) {
            return true;
        }
        
        $token = $this->getTokenFromRequest();
        $sessionToken = $this->sessionManager->getCsrfToken();
        
        return $token && $sessionToken && hash_equals($sessionToken, $token);
    }
    
    private function getTokenFromRequest(): ?string
    {
        $headers = getallheaders();
        
        $headerToken = $headers['X-CSRF-TOKEN'] ?? 
                      $headers['X-XSRF-TOKEN'] ?? 
                      $_SERVER['HTTP_X_CSRF_TOKEN'] ?? 
                      $_SERVER['HTTP_X_XSRF_TOKEN'] ?? null;
        
        $postToken = $_POST['_csrf'] ?? $_POST['csrf_token'] ?? null;
        
        $cookieToken = $_COOKIE['XSRF-TOKEN'] ?? null;
        
        return $headerToken ?? $postToken ?? $cookieToken;
    }
    
    public function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }
}