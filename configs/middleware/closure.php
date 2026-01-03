<?php
return [
    'security' => [
        'xss_protection' => true,
        'content_type_validation' => true,
        'header_injection_protection' => true,
        'json_validation' => true,
        'max_response_size' => 5242880,
        'allowed_content_types' => [
            'application/json',
            'application/json; charset=utf-8',
            'text/html; charset=utf-8',
        ],
        'disallowed_patterns' => [
            '/<\s*script/i',
            '/javascript:/i',
            '/on\w+\s*=/i',
            '/data:/i',
            '/vbscript:/i',
        ],
    ],
    
    'validation' => [
        'response_structure' => [
            'required_keys' => ['status'],
            'allowed_keys' => ['status', 'data', 'message', 'errors', 'meta'],
            'status_values' => ['success', 'error'],
        ],
        'data_types' => [
            'status' => 'string',
            'message' => 'string|array|null',
            'data' => 'array|object|string|integer|boolean|null',
            'errors' => 'array|null',
        ],
        'sanitization' => [
            'strip_tags' => true,
            'trim_strings' => true,
            'escape_html' => true,
            'normalize_line_endings' => true,
        ],
    ]
];