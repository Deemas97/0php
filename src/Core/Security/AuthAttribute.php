<?php
namespace Core\Security;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class AuthAttribute implements AttributeInterface
{
    public function __construct(
        public string $table = '',
        public array $roles = [],
        public string $status = '',
        public bool $strict = true
    ) {}
}