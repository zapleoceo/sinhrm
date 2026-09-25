<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\DTO;

/** A new application extracted from a job-board e-mail. At least one contact (phone or e-mail) is guaranteed. */
final readonly class IncomingApplication
{
    public function __construct(
        public ?string $fullName,
        public ?string $phone,
        public ?string $email,
        public ?string $vacancyTitle,
        public ?string $vacancyRef = null,
        public ?string $cvUrl = null,
    ) {}

    public function contact(): string
    {
        return (string) ($this->email ?? $this->phone);
    }
}
