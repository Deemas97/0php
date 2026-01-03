<?php
namespace Core\Middleware;

use Bootstrap\Config\DotEnv;
use Core\MessageBus\MessageBusInterface;
use Core\Service\GzipCompressor;

final class ResponseMiddleware implements CoreMiddlewareInterface
{
    public function process(MessageBusInterface $messageBus): MessageBusInterface
    {
        $compressOutput = $messageBus->get('compress_output', false);
        
        switch ($messageBus->get('type')) {
            case 'html_response':
                $htmlResponse = $messageBus->get('response');
                if ($compressOutput && GzipCompressor::isEnabled()) {
                    ob_start();
                    if (is_array($htmlResponse)) {
                        echo $htmlResponse['view_html']?->getContent();
                    } else {
                        echo $htmlResponse->getContent();
                    }
                    
                    $content = ob_get_clean();
                    $compressed = GzipCompressor::compressIfNeeded($content, 'text/html');
                    
                    if (GzipCompressor::isCompressed($compressed)) {
                        header('Content-Encoding: gzip');
                        echo $compressed;
                    } else {
                        echo $content;
                    }
                } else {
                    if (is_array($htmlResponse)) {
                        echo $htmlResponse['view_html']?->getContent();
                    } else {
                        echo $htmlResponse->getContent();
                    }
                }
                break;
            case 'api_response':
            default:
                $apiResponse = $messageBus->get('response');
            
                $responseData = (is_array($apiResponse) ? $apiResponse : $apiResponse->getAll());
                $statusCode = ($messageBus->get('status') ?? 200);
                
                http_response_code($statusCode);
                header('Content-Type: application/json');
                
                if (is_array($apiResponse) && isset($apiResponse['headers'])) {
                    foreach ($apiResponse['headers'] as $name => $value) {
                        header("$name: $value");
                    }
                } elseif (is_object($apiResponse) && method_exists($apiResponse, 'get')) {
                    if ($headers = $apiResponse->get('headers')) {
                        foreach ($headers as $name => $value) {
                            header("$name: $value");
                        }
                    }
                }

                if ($compressOutput && GzipCompressor::isEnabled()) {
                    $responseData = json_encode($responseData);
                    $compressed = GzipCompressor::compressIfNeeded($responseData, 'application/json');
                
                    if (GzipCompressor::isCompressed($compressed)) {
                        header('Content-Encoding: gzip');
                        echo $compressed;
                        return $messageBus;
                    }
                }

                echo json_encode($responseData);
        }
        
        return $messageBus;
    }
}
