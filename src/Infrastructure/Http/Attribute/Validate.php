<?php
namespace Infrastructure\Http\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class Validate
{
    public function __construct(
        public array $rules = [],
        public ?string $dto = null
    ) {}
}