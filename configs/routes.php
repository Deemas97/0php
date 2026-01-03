<?php
const ROUTES_CONFIG = [
    // PRIVATE METHODS - DEV TOOLS
    [
        'path' => '/_dev/cache/clear',
        'http_method' => 'POST',
        'controller' => 'Dev\Controller\Api\CacheApiController',
        'controller_method' => 'apiClearCache'
    ],

    // PUBLIC METHODS - UNAUTHORIZED
    [
        'path' => '/api/public/contact_form/{id}/get',
        'http_method' => 'GET',
        'controller' => 'App\Controller\Api\Public\ContactFormUploaderApiController',
        'controller_method' => 'apiGet'
    ],

    // PUBLIC METHODS - AUTHORIZED
    [
        'path' => '/api/public/customer/contact_form/submit',
        'http_method' => 'POST',
        'controller' => 'App\Controller\Api\Public\CustomerContactFormApiController',
        'controller_method' => 'apiSubmit'
    ],

    // WEB - UNAUTHORIZED
    [
        'path' => '/login',
        'http_method' => 'GET',
        'controller' => 'App\Controller\Web\Public\AuthController',
        'controller_method' => 'authForm'
    ],

    // WEB - AUTHORIZED
    [
        'path' => '/',
        'http_method' => 'GET',
        'controller' => 'App\Controller\Web\MainController',
        'controller_method' => 'index'
    ],
];