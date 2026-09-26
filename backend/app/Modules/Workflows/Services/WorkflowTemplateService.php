<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Services;

use App\Models\User;
use App\Modules\Workflows\Contracts\WorkflowTemplateRepository;
use App\Modules\Workflows\Exceptions\WorkflowException;
use App\Modules\Workflows\Models\WorkflowTemplate;
use App\Modules\Workflows\Support\WebhookSecrets;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

/** Workflow templates (admin): CRUD with inline steps, reorder, per-template webhook signing key. */
final readonly class WorkflowTemplateService
{
    public function __construct(
        private WorkflowTemplateRepository $templates,
        private WebhookSecrets $secrets,
        private LoggerInterface $log,
    ) {}

    /** @return Collection<int, WorkflowTemplate> */
    public function list(): Collection
    {
        return $this->templates->list();
    }

    /** @throws ModelNotFoundException<WorkflowTemplate> */
    public function find(int $id): WorkflowTemplate
    {
        return $this->templates->find($id) ?? throw (new ModelNotFoundException)->setModel(WorkflowTemplate::class, [$id]);
    }

    /**
     * @param  array<string, mixed>  $attributes  template fields
     * @param  list<array<string, mixed>>  $steps  validated steps (id? for existing ones)
     */
    public function create(User $actor, array $attributes, array $steps): WorkflowTemplate
    {
        $template = $this->templates->transaction(function () use ($actor, $attributes, $steps): WorkflowTemplate {
            $template = $this->templates->create($attributes + ['created_by' => $actor->id]);
            $this->templates->syncSteps($template, $steps);

            return $template;
        });
        $this->log->info('workflows.template_created', ['id' => $template->id, 'by' => $actor->id]);

        return $this->find($template->id);
    }

    /**
     * Running runs are not affected: they keep their snapshot.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>|null  $steps  null = keep the steps as they are
     */
    public function update(User $actor, WorkflowTemplate $template, array $attributes, ?array $steps): WorkflowTemplate
    {
        $this->templates->transaction(function () use ($template, $attributes, $steps): void {
            $this->templates->update($template, $attributes);
            if ($steps !== null) {
                $this->templates->syncSteps($template, $steps);
            }
        });
        $this->log->info('workflows.template_updated', ['id' => $template->id, 'by' => $actor->id]);

        return $this->find($template->id);
    }

    /**
     * @param  list<int>  $stepIds  exactly the template's step ids, in the new order
     *
     * @throws WorkflowException invalid_order
     */
    public function reorder(WorkflowTemplate $template, array $stepIds): WorkflowTemplate
    {
        $current = $template->steps->pluck('id')->map(static fn (mixed $id): int => (int) $id)->sort()->values()->all();
        $given = $stepIds;
        sort($given);
        if ($current !== $given) {
            throw WorkflowException::invalidOrder();
        }
        $this->templates->transaction(fn () => $this->templates->reorder($template, $stepIds));

        return $this->find($template->id);
    }

    /** @throws WorkflowException has_runs — history is kept: deactivate instead */
    public function delete(User $actor, WorkflowTemplate $template): void
    {
        if ($this->templates->hasRuns($template)) {
            throw WorkflowException::hasRuns();
        }
        $this->templates->delete($template);
        $this->secrets->forget($template->id);
        $this->log->info('workflows.template_deleted', ['id' => $template->id, 'by' => $actor->id]);
    }

    /** null removes the key (webhook steps then fail with missing_secret). */
    public function setWebhookSecret(User $actor, WorkflowTemplate $template, #[SensitiveParameter] ?string $secret): void
    {
        if ($secret === null) {
            $this->secrets->forget($template->id);
        } else {
            $this->secrets->put($template->id, $secret, $actor->id);
        }
        // The fact only — never the value.
        $this->log->info('workflows.webhook_secret_changed', ['template' => $template->id, 'by' => $actor->id, 'set' => $secret !== null]);
    }
}
