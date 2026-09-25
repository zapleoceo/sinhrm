<?php

declare(strict_types=1);

namespace App\Modules\Integrations\DTO;

use App\Modules\Integrations\Enums\FieldType;

/** One field of an integration form. Secrets go to the vault, everything else to integrations.settings. */
final readonly class FieldSpec
{
    /** @param  list<string>  $options  allowed values for FieldType::Select */
    public function __construct(
        public string $name,
        public FieldType $type,
        public bool $required = false,
        public array $options = [],
        public ?string $default = null,
    ) {}

    public static function text(string $name, bool $required = false, ?string $default = null): self
    {
        return new self($name, FieldType::Text, $required, default: $default);
    }

    public static function url(string $name, bool $required = false, ?string $default = null): self
    {
        return new self($name, FieldType::Url, $required, default: $default);
    }

    public static function secret(string $name, bool $required = true): self
    {
        return new self($name, FieldType::Secret, $required);
    }

    /** @param  list<string>  $options */
    public static function select(string $name, array $options, bool $required = false, ?string $default = null): self
    {
        return new self($name, FieldType::Select, $required, $options, $default);
    }

    public function isSecret(): bool
    {
        return $this->type === FieldType::Secret;
    }
}
