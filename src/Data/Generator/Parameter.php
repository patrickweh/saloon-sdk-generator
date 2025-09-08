<?php

namespace Crescat\SaloonSdkGenerator\Data\Generator;

class Parameter
{
    public function __construct(
        public string $type,
        public bool $nullable,
        public string $name,
        public ?string $description = null,
        public ?array $enumValues = null,
        public ?string $enumName = null
    ) {}

    public function hasEnum(): bool
    {
        return ! empty($this->enumValues);
    }
}
