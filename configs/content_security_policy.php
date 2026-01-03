<?php
return [
    'production' => [
        'default-src' => ["'self'"],
        
        'script-src' => [
            "'self'",
            'https://cdn.jsdelivr.net',
            'https://code.jquery.com',
            'https://unpkg.com',
            "'nonce-{nonce}'",
            "'strict-dynamic'"
        ],
        
        'style-src' => [
            "'self'",
            'https://cdn.jsdelivr.net',
            'https://cdnjs.cloudflare.com',
            'https://fonts.googleapis.com',
            "'nonce-{nonce}'"
        ],
        
        'img-src' => [
            "'self'",
            'data:',
            'blob:',
            'https:'
        ],
        
        'font-src' => [
            "'self'",
            'data:',
            'https://fonts.gstatic.com',
            'https://cdnjs.cloudflare.com'
        ],
        
        'connect-src' => [
            "'self'",
            'https://cdn.jsdelivr.net',
            'https://code.jquery.com'
        ],
        
        'worker-src' => ["'self'", 'blob:'],
        'child-src' => ["'self'", 'blob:'],
        'frame-src' => ["'self'"],
        
        'frame-ancestors' => ["'none'"],
        'base-uri' => ["'self'"],
        'form-action' => ["'self'"],
        'object-src' => ["'none'"],
        'manifest-src' => ["'self'"],
        
        'report-uri' => ['/csp-report'],
        'report-to' => ['csp-endpoint']
    ],
    
    'development' => [
        'default-src' => ["'self'", "'unsafe-inline'", "'unsafe-eval'"],
        'script-src' => ["'self'", "'unsafe-inline'", "'unsafe-eval'", 'https:'],
        'style-src' => ["'self'", "'unsafe-inline'", 'https:'],
        'img-src' => ["'self'", 'data:', 'blob:', 'https:'],
        'font-src' => ["'self'", 'data:', 'https:'],
        'connect-src' => ["'self'", 'https:'],
        'worker-src' => ["'self'", 'blob:'],
        'frame-src' => ["'self'"],
        'frame-ancestors' => ["'none'"]
    ],
    
    'test' => [
        'default-src' => ["'self'", "'unsafe-inline'", "'unsafe-eval'"],
        'script-src' => ["'self'", "'unsafe-inline'", "'unsafe-eval'"],
        'style-src' => ["'self'", "'unsafe-inline'"],
        'img-src' => ["'self'", 'data:', 'blob:', 'https:'],
        'font-src' => ["'self'", 'data:', 'https:'],
        'frame-ancestors' => ["'none'"]
    ]
];