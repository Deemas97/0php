<?php
namespace Core\Middleware;

use Core\Service\GzipCompressor;
use Core\MessageBus\MessageBusInterface;

final class CompressionMiddleware implements CoreMiddlewareInterface
{
    public function process(MessageBusInterface $messageBus): MessageBusInterface
    {
        $responseType = $messageBus->get('type');
        $response = $messageBus->get('response');
        
        $headers = $messageBus->get('headers') ?? [];
        $acceptEncoding = $headers['Accept-Encoding'] ?? $headers['accept-encoding'] ?? '';
        $supportsGzip = strpos($acceptEncoding, 'gzip') !== false;
        
        if ($supportsGzip && GzipCompressor::isEnabled()) {
            if ($responseType === 'api_response') {
                if (is_array($response)) {
                    $json = json_encode($response);
                    $compressed = GzipCompressor::compress($json);
                    if ($compressed !== false) {
                        $messageBus->set('compressed', true);
                        $messageBus->set('response', $compressed);
                        
                        $currentHeaders = $messageBus->get('headers') ?? [];
                        $currentHeaders['Content-Encoding'] = 'gzip';
                        $messageBus->set('headers', $currentHeaders);
                    }
                }
            } elseif ($responseType === 'html_response') {
                $messageBus->set('compress_output', true);
            }
        }
        
        return $messageBus;
    }
}