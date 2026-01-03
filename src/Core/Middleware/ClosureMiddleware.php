<?php
namespace Core\Middleware;

use Core\MessageBus\MessageBusInterface;
use RuntimeException;

class ClosureMiddleware implements CoreMiddlewareInterface
{
    private array $securityConfig;
    private array $validationRules;
    private bool $isConfigured = false;

    public function __construct()
    {
        $this->securityConfig = $this->getDefaultSecurityConfig();
        $this->validationRules = $this->getDefaultValidationRules();
    }

    private function getDefaultSecurityConfig(): array
    {
        return [
            'xss_protection' => true,
            'content_type_validation' => true,
            'header_injection_protection' => true,
            'json_validation' => true,
            'max_response_size' => 10 * 1024 * 1024,
            'allowed_content_types' => [
                'application/json',
                'text/html',
                'text/plain',
                'application/xml',
            ],
            'disallowed_patterns' => [
                '/<\s*script/i',
                '/javascript:/i',
                '/on\w+\s*=/i',
                '/data:/i',
                '/vbscript:/i',
            ],
        ];
    }

    private function getDefaultValidationRules(): array
    {
        return [
            'response_structure' => [
                'required_keys' => ['status', 'data', 'message'],
                'allowed_keys' => ['status', 'data', 'message', 'errors', 'meta', 'pagination'],
                'status_values' => ['success', 'error', 'fail'],
            ],
            'data_types' => [
                'status' => 'string',
                'message' => 'string|array|null',
                'data' => 'array|object|null',
                'errors' => 'array|null',
            ],
            'sanitization' => [
                'strip_tags' => true,
                'trim_strings' => true,
                'escape_html' => true,
                'normalize_line_endings' => true,
            ],
        ];
    }

    public function process(MessageBusInterface $messageBus): MessageBusInterface
    {
        if (!$messageBus->has('response')) {
            return $messageBus;
        }

        $response = $messageBus->get('response');
        
        try {
            $validatedResponse = $this->validateResponse($response);
            
            $sanitizedResponse = $this->sanitizeResponse($validatedResponse);
            
            $securedResponse = $this->secureResponseHeaders($sanitizedResponse, $messageBus);
            
            $this->checkResponseSize($securedResponse);
            
            $messageBus->set('response', $securedResponse);
            $messageBus->set('_closure_processed', true);
            $messageBus->set('_closure_timestamp', microtime(true));
            $messageBus->set('_closure_config_used', $this->isConfigured);
            
        } catch (RuntimeException $e) {
            $messageBus->set('response', $this->createSecureFallbackResponse($e));
            $messageBus->set('_closure_error', $e->getMessage());
            $messageBus->set('_closure_fallback', true);
        }

        return $messageBus;
    }

    public function setSecurityConfig(array $config): self
    {
        $this->securityConfig = array_merge($this->securityConfig, $config);
        $this->isConfigured = true;
        return $this;
    }

    public function setValidationRules(array $rules): self
    {
        $this->validationRules = array_merge($this->validationRules, $rules);
        $this->isConfigured = true;
        return $this;
    }

    public function resetToDefaults(): self
    {
        $this->securityConfig = $this->getDefaultSecurityConfig();
        $this->validationRules = $this->getDefaultValidationRules();
        $this->isConfigured = false;
        return $this;
    }

    public function isCustomConfigured(): bool
    {
        return $this->isConfigured;
    }

    private function validateResponse(array $response): array
    {
        $rules = $this->validationRules['response_structure'];
        
        foreach ($rules['required_keys'] as $key) {
            if (!array_key_exists($key, $response)) {
                throw new RuntimeException(
                    sprintf('Response missing required key: %s', $key)
                );
            }
        }
        
        foreach (array_keys($response) as $key) {
            if (!in_array($key, $rules['allowed_keys'])) {
                throw new RuntimeException(
                    sprintf('Disallowed response key: %s', $key)
                );
            }
        }
        
        if (isset($response['status']) && !in_array($response['status'], $rules['status_values'])) {
            throw new RuntimeException(
                sprintf('Invalid status value: %s', $response['status'])
            );
        }
        
        $this->validateDataTypes($response);
        
        return $response;
    }

    private function validateDataTypes(array $response): void
    {
        $rules = $this->validationRules['data_types'];
        
        foreach ($rules as $key => $allowedTypes) {
            if (!isset($response[$key])) {
                continue;
            }
            
            $types = explode('|', $allowedTypes);
            $value = $response[$key];
            $isValid = false;
            
            foreach ($types as $type) {
                if ($type === 'null' && $value === null) {
                    $isValid = true;
                    break;
                }
                
                $checkMethod = 'is_' . $type;
                if (function_exists($checkMethod) && $checkMethod($value)) {
                    $isValid = true;
                    break;
                }
                
                if ($type === 'array' && is_array($value)) {
                    $isValid = true;
                    break;
                }
                
                if ($type === 'object' && is_object($value)) {
                    $isValid = true;
                    break;
                }
            }
            
            if (!$isValid) {
                throw new RuntimeException(
                    sprintf('Invalid data type for %s. Expected: %s, Got: %s',
                        $key,
                        $allowedTypes,
                        gettype($value)
                    )
                );
            }
        }
    }

    private function sanitizeResponse(array $response): array
    {
        if (!$this->securityConfig['xss_protection']) {
            return $response;
        }

        $sanitized = [];
        
        foreach ($response as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeResponse($value);
            } elseif (is_string($value)) {
                $sanitized[$key] = $this->sanitizeString($value);
            } else {
                $sanitized[$key] = $value;
            }
        }
        
        return $sanitized;
    }

    private function sanitizeString(string $value): string
    {
        $config = $this->validationRules['sanitization'];
        
        if ($config['strip_tags']) {
            $value = strip_tags($value, '<p><br><strong><em><u><ol><ul><li><h1><h2><h3><h4><h5><h6>');
        }
        
        if ($config['trim_strings']) {
            $value = trim($value);
        }
        
        if ($config['escape_html']) {
            $value = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        
        if ($config['normalize_line_endings']) {
            $value = preg_replace('/\r\n|\r|\n/', "\n", $value);
        }
        
        foreach ($this->securityConfig['disallowed_patterns'] as $pattern) {
            if (preg_match($pattern, $value)) {
                throw new RuntimeException(
                    'Response contains potentially dangerous content'
                );
            }
        }
        
        return $value;
    }

    private function secureResponseHeaders(array $response, MessageBusInterface $messageBus): array
    {
        if (!$this->securityConfig['header_injection_protection']) {
            return $response;
        }

        $secureHeaders = [
            'Content-Type' => 'application/json; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'X-XSS-Protection' => '1; mode=block',
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
        ];

        $headers = $messageBus->get('response_headers') ?? [];
        $headers = array_merge($secureHeaders, $headers);
        $headers = $this->filterDangerousHeaders($headers);
        
        $messageBus->set('response_headers', $headers);
        
        return $response;
    }

    private function filterDangerousHeaders(array $headers): array
    {
        $dangerousHeaders = [
            'X-Powered-By',
            'Server',
            'X-AspNet-Version',
            'X-AspNetMvc-Version',
        ];
        
        foreach ($dangerousHeaders as $dangerousHeader) {
            unset($headers[$dangerousHeader]);
        }
        
        return $headers;
    }

    private function checkResponseSize(array $response): void
    {
        $maxSize = $this->securityConfig['max_response_size'];
        
        $jsonResponse = json_encode($response);
        if ($jsonResponse === false) {
            throw new RuntimeException('Failed to encode response to JSON');
        }
        
        $size = strlen($jsonResponse);
        
        if ($size > $maxSize) {
            throw new RuntimeException(
                sprintf('Response size (%s bytes) exceeds maximum allowed size (%s bytes)',
                    $size,
                    $maxSize
                )
            );
        }
    }

    private function createSecureFallbackResponse(RuntimeException $exception): array
    {
        return [
            'status' => 'error',
            'data' => null,
            'message' => 'An error occurred while processing your request',
            'errors' => [
                [
                    'code' => 'SECURITY_ERROR',
                    'message' => $exception->getMessage(),
                ]
            ],
            '_meta' => [
                'secure_fallback' => true,
                'timestamp' => time(),
            ]
        ];
    }

    public function getSecurityConfig(): array
    {
        return $this->securityConfig;
    }

    public function getValidationRules(): array
    {
        return $this->validationRules;
    }
}