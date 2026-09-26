<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\DTO;

use App\Modules\Recruiting\Enums\AddedVia;
use App\Modules\Recruiting\Enums\CandidateSource;

/**
 * Input for creating/updating a candidate — from the API form or an import row (fromArray). Contacts are raw here;
 * CandidateService normalizes them. Null = not given (on update: unchanged).
 */
final readonly class CandidateData
{
    /**
     * @param  array<string, string>|null  $utm
     * @param  list<string>|null  $tags
     */
    public function __construct(
        public ?string $fullName = null,
        public ?string $phone = null,
        public ?string $email = null,
        public ?string $telegram = null,
        public ?int $cityId = null,
        public ?CandidateSource $source = null,
        public ?array $utm = null,
        public ?array $tags = null,
        public ?int $ownerId = null,
        public ?int $vacancyId = null,
        public ?int $channelId = null,
        public ?AddedVia $addedVia = null,
    ) {}

    /** The same data with "how added" set (the caller knows the path: mail agent, sheets import, …). */
    public function withAddedVia(AddedVia $addedVia): self
    {
        return new self(
            $this->fullName, $this->phone, $this->email, $this->telegram, $this->cityId, $this->source, $this->utm,
            $this->tags, $this->ownerId, $this->vacancyId, $this->channelId, $addedVia,
        );
    }

    /**
     * Import-ready: a loose row (e.g. a spreadsheet line or a job-board payload) → DTO. Unknown source → "import".
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        $str = static fn (string $key): ?string => isset($row[$key]) && is_scalar($row[$key]) && trim((string) $row[$key]) !== ''
            ? trim((string) $row[$key]) : null;
        $utm = null;
        if (isset($row['utm']) && is_array($row['utm'])) {
            $utm = [];
            foreach ($row['utm'] as $k => $v) {
                if (is_string($k) && is_scalar($v)) {
                    $utm[$k] = mb_substr((string) $v, 0, 255);
                }
            }
        }
        $tags = null;
        if (isset($row['tags']) && is_array($row['tags'])) {
            $tags = array_values(array_filter(array_map(
                static fn (mixed $t): string => is_scalar($t) ? trim((string) $t) : '',
                $row['tags'],
            ), static fn (string $t): bool => $t !== ''));
        }
        $int = static fn (string $key): ?int => isset($row[$key]) && is_numeric($row[$key]) ? (int) $row[$key] : null;

        return new self(
            fullName: $str('full_name'),
            phone: $str('phone'),
            email: $str('email'),
            telegram: $str('telegram_username') ?? $str('telegram'),
            cityId: $int('city_id'),
            source: CandidateSource::tryFrom((string) $str('source')) ?? CandidateSource::Import,
            utm: $utm,
            tags: $tags,
            ownerId: $int('owner_id'),
            vacancyId: $int('vacancy_id'),
            channelId: $int('channel_id'),
        );
    }
}
