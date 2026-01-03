<?php
namespace Infrastructure\Http\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class Auth
{
    public function __construct(
        public array $roles = [],
        public ?string $permission = null,
        public bool $required = true
    ) {}
}