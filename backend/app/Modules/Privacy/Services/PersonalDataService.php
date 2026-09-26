<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Services;

use App\Modules\Core\Contracts\PersonalDataProvider;
use App\Modules\Core\DTO\DataSubject;
use App\Modules\Privacy\Exceptions\PrivacyException;
use App\Modules\Privacy\Models\PrivacyRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Export and erase of a person's data across modules (Law of Ukraine No. 2297-VI). Runs every tagged
 * PersonalDataProvider; each module touches only its own tables. Every request is journaled (counters only).
 */
final readonly class PersonalDataService
{
    /** @param  iterable<PersonalDataProvider>  $providers */
    public function __construct(private iterable $providers) {}

    /**
     * @return array{subject: array{type: string, id: int}, generated_at: string, sections: array<string, mixed>}
     *
     * @throws PrivacyException
     */
    public function export(DataSubject $subject, ?int $actorId): array
    {
        $this->assertAllowed($subject, false);
        $sections = [];
        foreach ($this->providers as $provider) {
            $sections[$provider->section()] = $provider->export($subject);
        }
        $this->journal($subject, 'export', 'manual', null, $actorId, null);

        return [
            'subject' => ['type' => $subject->type->value, 'id' => $subject->id],
            'generated_at' => Carbon::now()->toIso8601String(),
            'sections' => $sections,
        ];
    }

    /**
     * Anonymize in place, all modules in one transaction (all or nothing). Idempotent.
     *
     * @return array<string, array<string, int>>
     *
     * @throws PrivacyException
     */
    public function erase(DataSubject $subject, string $reason, ?int $actorId, string $trigger = 'manual'): array
    {
        $this->assertAllowed($subject, true);

        return DB::transaction(function () use ($subject, $reason, $actorId, $trigger): array {
            $counts = [];
            foreach ($this->providers as $provider) {
                $counts[$provider->section()] = $provider->erase($subject);
            }
            $this->journal($subject, 'erase', $trigger, $reason, $actorId, $counts);
            Log::info('privacy.erased', ['type' => $subject->type->value, 'id' => $subject->id, 'trigger' => $trigger, 'by' => $actorId]);

            return $counts;
        });
    }

    /** @throws PrivacyException */
    private function assertAllowed(DataSubject $subject, bool $erase): void
    {
        foreach ($this->providers as $provider) {
            $blocker = $provider->blocker($subject, $erase);
            if ($blocker !== null) {
                throw PrivacyException::blocked($blocker);
            }
        }
    }

    /** @param  array<string, array<string, int>>|null  $counts */
    private function journal(DataSubject $subject, string $action, string $trigger, ?string $reason, ?int $actorId, ?array $counts): void
    {
        PrivacyRequest::query()->create([
            'subject_type' => $subject->type->value,
            'subject_id' => $subject->id,
            'action' => $action,
            'trigger' => $trigger,
            'reason' => $reason,
            'actor_id' => $actorId,
            'counts' => $counts === null ? null : array_map(static fn (array $c): int => array_sum($c), $counts),
        ]);
    }
}
