<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

use App\Modules\Core\DTO\DataSubject;

/**
 * One module's share of a person's data for the "export" and "erase" requests (docs/architecture/secrets.md).
 * Each module touches only its own tables; the Privacy module runs every tagged provider in one transaction.
 * Modules register providers with $this->app->tag([...], PersonalDataProvider::class).
 *
 * Erasure = anonymization in place: rows that reports count (applications, stage changes, touchpoints) stay,
 * the personal content in them is wiped. It must be idempotent: running it twice changes nothing more.
 */
interface PersonalDataProvider
{
    /** Section key in the export, e.g. "profile", "touchpoints". Unique across providers. */
    public function section(): string;

    /**
     * Why the subject can't be exported/erased (not_found, not_terminated, hired, …), or null when it can.
     * Only the module that owns the subject answers; the others return null.
     */
    public function blocker(DataSubject $subject, bool $erase): ?string;

    /**
     * Everything this module keeps about the subject (files as metadata only: name, type, size, date).
     *
     * @return array<array-key, mixed>
     */
    public function export(DataSubject $subject): array;

    /**
     * Wipe the personal content. Counters only in the answer (never personal data).
     *
     * @return array<string, int>
     */
    public function erase(DataSubject $subject): array;
}
