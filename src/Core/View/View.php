<?php
namespace Core\View;

class View implements ViewInterface
{
    public function __construct(
        private string $content = ''
    )
    {}

    public function getContent(): string
    {
        return $this->content;
    }
}