<?php

declare(strict_types=1);

namespace App\Modules\Audit\Support;

use App\Modules\Audit\Contracts\AuditLogger;
use App\Modules\Audit\Enums\AuditAction;
use Illuminate\Database\Eloquent\Model;

/**
 * Generic Eloquent observer: one audit row per create/update/delete of a tracked model.
 * The action is refined from what changed: stage → stage_changed, activated prompt → prompt_activated,
 * status → status_changed, anything else → updated. Bulk query()->update() bypasses it by design (system work).
 */
final readonly class AuditObserver
{
    /** @param  array<class-string<Model>, string>  $entityTypes */
    public function __construct(private AuditLogger $logger, private array $entityTypes) {}

    public function created(Model $model): void
    {
        $changes = [];
        foreach ($model->getAttributes() as $field => $value) {
            if ($value !== null) {
                $changes[$field] = ['from' => null, 'to' => $value];
            }
        }
        $this->write($model, AuditAction::Created, $changes);
    }

    public function updated(Model $model): void
    {
        $changes = [];
        foreach (array_keys($model->getChanges()) as $field) {
            $changes[$field] = ['from' => $model->getRawOriginal($field), 'to' => $model->getAttributes()[$field] ?? null];
        }
        $this->write($model, $this->actionFor($changes), $changes);
    }

    public function deleted(Model $model): void
    {
        $this->write($model, AuditAction::Deleted, null);
    }

    /** @param  array<string, array{from: mixed, to: mixed}>  $changes */
    private function actionFor(array $changes): AuditAction
    {
        return match (true) {
            array_key_exists('stage_id', $changes) => AuditAction::StageChanged,
            array_key_exists('is_active', $changes) && (bool) $changes['is_active']['to'] => AuditAction::PromptActivated,
            array_key_exists('status', $changes) => AuditAction::StatusChanged,
            default => AuditAction::Updated,
        };
    }

    /** @param  array<string, array{from: mixed, to: mixed}>|null  $changes */
    private function write(Model $model, AuditAction $action, ?array $changes): void
    {
        $type = $this->entityTypes[$model::class] ?? null;
        $id = $model->getKey();
        if ($type === null || ! is_int($id)) {
            return;
        }
        $meta = null;
        $candidateId = $model->getAttribute('candidate_id');
        if ($type === 'application' && is_numeric($candidateId)) {
            $meta = ['candidate_id' => (int) $candidateId];
        }
        $this->logger->record($type, $id, $action, $changes, $meta);
    }
}
