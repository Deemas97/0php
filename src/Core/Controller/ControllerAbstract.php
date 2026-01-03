<?php
namespace Core\Controller;

abstract class ControllerAbstract
{
    public function __construct() {}

    protected function initResponse(): ControllerResponse
    {
        return new ControllerResponse();
    }

    protected function initJsonResponse(array $data = [], int $statusCode = 200, array $headers = []): ControllerResponse
    {
        $response = new ControllerResponse();
        $response->setStatusCode($statusCode);
        
        foreach ($data as $key => $value) {
            $response->set($key, $value);
        }
        
        $response->set('is_json', true);
        
        if (!empty($headers)) {
            $response->set('headers', $headers);
        }
        
        return $response;
    }
}
