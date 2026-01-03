<?php
namespace Core\Service\Renderer;

use Core\Service\CoreServiceInterface;

class HtmlMinificator implements CoreServiceInterface
{
    public function __construct()
    {}

    public function minify(string $html)
    {
        $html = preg_replace('/<!--(?!\s*(?:\[if [^\]]+]|<!|>))(?:(?!-->).)*-->/s', '', $html);
        $html = preg_replace('/\/\*[\s\S]*?\*\/|([^\\:]|^)\/\/.*$/m', '', $html);
    
        $html = preg_replace('/\s{2,}/', ' ', $html);
        $html = preg_replace('/\s*([<>])\s*/', '$1', $html);
        $html = preg_replace('/\s+>/', '>', $html);
        $html = preg_replace('/>\s+/', '>', $html);
        
        return trim($html);
    }
}