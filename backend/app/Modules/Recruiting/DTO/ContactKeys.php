<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\DTO;

/** Normalized contacts used to find the same person (dedupe, message matching). */
final readonly class ContactKeys
{
    public function __construct(
        public ?string $phone,
        public ?string $email,
        public ?string $telegram,
    ) {}

    public function isEmpty(): bool
    {
        return $this->phone === null && $this->email === null && $this->telegram === null;
    }
}
