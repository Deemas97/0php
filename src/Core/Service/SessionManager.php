<?php
namespace Core\Service;

use Infrastructure\DataBase\DBConnectorInterface;

class SessionManager implements CoreServiceInterface
{
    public const CSRF_TOKEN_EXPIRATION = 3600;

    private const SESSION_TIMEOUT = (30 * 60);
    private const REMEMBER_TOKEN_LENGTH = 32;
    private const TOKEN_EXPIRATION = '+1 month';
    
    private DBConnectorInterface $db;

    public function __construct(
        DBConnectionManager $dbManager,
        private CookiesManager $cookies
    )
    {
        $this->db = $dbManager->getConnection();
    }

    public function startCsrfSession(string $token): void
    {
        $this->ensureSessionStarted('CSRF-SESSION-ID');
        $this->regenerateSessionId();

        $this->cookies->setSessionCookie(session_name(), session_id(), [
            'expires' => 0,
        ]);

        $_SESSION['csrf'] = [
            'token' => $token,
            'user_agent' => ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'expires' => time() + self::CSRF_TOKEN_EXPIRATION
        ];
    }

    public function getCsrfToken()
    {
        $this->ensureSessionStarted('CSRF-SESSION-ID');
        return ($_SESSION['csrf']['token'] ?? null);
    }

    public function startUserSession(string $userTable, array $userData, bool $remember = false): void
    {
        $this->ensureSessionStarted();
        $this->regenerateSessionId();
        
        $_SESSION['user'] = array_merge($userData, [
            'user_agent' => ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'last_activity' => time()
        ]);

        if ($remember) {
            $this->createRememberToken($userTable, $userData['id']);
        }
    }

    public function renewSession(): void
    {
        if ($this->isSessionActive()) {
            $_SESSION['user']['last_activity'] = time();
        }
    }

    public function validateSession(string $userTable): ?array
    {
        $this->ensureSessionStarted();
        
        if (!$this->isSessionActive() && !empty($_COOKIE['REMEMBER-TOKEN'])) {
            $this->handleRememberToken($userTable);
        }
    
        if (!$this->isSessionActive()) {
            return null;
        }
    
        if (!$this->validateSessionSecurity()) {
            $this->endSession($userTable);
            return null;
        }
    
        $this->renewSession();
        return $_SESSION['user'];
    }

    public function validateCsrfToken(string $token): bool
    {
        $this->ensureSessionStarted();

        if (empty($_SESSION['csrf'])) {
            return false;
        }

        $csrfData = $_SESSION['csrf'];

        if (!hash_equals($csrfData['token'], $token)) {
            return false;
        }

        if (time() > $csrfData['expires']) {
            unset($_SESSION['csrf']);
            return false;
        }

        if (($csrfData['user_agent'] ?? '') !== ($_SERVER['HTTP_USER_AGENT'] ?? '')) {
            return false;
        }

        return true;
    }
    
    public function endSession(string $userTable): void
    {
        $this->clearRememberToken($userTable);

        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $this->cookies->remove(session_name());
        }
    }

    public function addUserItem(string $key, string $value): void
    {
        $_SESSION['user'][$key] = $value;
    }

    private function ensureSessionStarted(?string $name = null): void
    {
        if (isset($name)) {
            session_name($name);
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    private function regenerateSessionId(): void
    {
        session_regenerate_id(true);
    }

    private function isSessionActive(): bool
    {
        return isset($_SESSION['user']);
    }

    private function validateSessionSecurity(): bool
    {
        $user = $_SESSION['user'];
        
        if (($user['user_agent'] ?? '') !== ($_SERVER['HTTP_USER_AGENT'] ?? '')) {
            return false;
        }

        return (time() - ($user['last_activity'] ?? 0)) <= self::SESSION_TIMEOUT;
    }

    private function handleRememberToken(string $userTable): void
    {
        $token = $_COOKIE['REMEMBER-TOKEN'];
        $userData = $this->getUserByRememberToken($userTable, $token);
        
        if ($userData) {
            $this->startUserSession($userTable, $userData, true);
        }
    }

    private function getUserByRememberToken(string $userTable, string $token): ?array
    {
        $safeToken = $this->db->escape($token);
        $currentDate = date('Y-m-d H:i:s');

        $query = "SELECT u.id, u.role_id, u.status, u.email, 
                  CONCAT(u.f, ' ', u.i) as name, u.avatar 
                  FROM {$userTable} u
                  JOIN {$userTable}_remember_tokens rt ON u.id = rt.user_id
                  WHERE rt.value = '{$safeToken}' 
                  AND rt.expire_at > '{$currentDate}'
                  LIMIT 1";

        $result = $this->db->query($query);
        return $result[0] ?? null;
    }

    private function createRememberToken(string $userTable, int $userId): void
    {
        $token = bin2hex(random_bytes(self::REMEMBER_TOKEN_LENGTH));
        $expires = date('Y-m-d H:i:s', strtotime(self::TOKEN_EXPIRATION));

        $this->saveRememberToken($userTable, $userId, $token, $expires);
        
        $this->cookies->set('REMEMBER-TOKEN', $token, [
            'expires' => strtotime(self::TOKEN_EXPIRATION),
        ]);
    }

    private function saveRememberToken(string $userTable, int $userId, string $token, string $expires): void
    {
        $table = "{$userTable}_remember_tokens";
        $safeToken = $this->db->escape($token);
        $safeExpires = $this->db->escape($expires);

        $query = "INSERT INTO {$table} (user_id, value, expire_at)
                  VALUES ({$userId}, '{$safeToken}', '{$safeExpires}')
                  ON DUPLICATE KEY UPDATE 
                  value = '{$safeToken}', 
                  expire_at = '{$safeExpires}'";

        $this->db->query($query);
    }

    private function clearRememberToken(string $userTable): void
    {
        if (!empty($_COOKIE['REMEMBER-TOKEN'])) {
            $token = $_COOKIE['REMEMBER-TOKEN'];
            $table = "{$userTable}_remember_tokens";
            $safeToken = $this->db->escape($token);

            $this->db->query("DELETE FROM {$table} WHERE value = '{$safeToken}'");
            $this->cookies->remove('REMEMBER-TOKEN');
        }
    }
}