<?php
namespace Core\Security;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class CsrfAttribute implements AttributeInterface
{
    public function __construct(
        public bool $enabled = true,
        public bool $ajaxOnly = false
    ) {}
}