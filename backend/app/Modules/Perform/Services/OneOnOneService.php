<?php

declare(strict_types=1);

namespace App\Modules\Perform\Services;

use App\Models\User;
use App\Modules\Perform\Contracts\OneOnOneRepository;
use App\Modules\Perform\DTO\PerformViewer;
use App\Modules\Perform\Enums\OneOnOneStatus;
use App\Modules\Perform\Exceptions\PerformException;
use App\Modules\Perform\Models\OneOnOne;
use App\Modules\Perform\Models\OneOnOneTemplate;
use App\Modules\Perform\Support\ListItems;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * 1:1 meetings. Who sees a meeting: its employee, its manager, managers above the employee, admins.
 * Scheduling, status and deletion: the meeting's manager, managers above the employee, admins.
 * Shared notes, agenda and action items: both participants (and the managers/admins above).
 * Private notes: the meeting's manager only — the API never returns them to anybody else, admins included.
 */
final readonly class OneOnOneService
{
    public const int LIMIT = 200;

    private const array ITEM = ['text' => '', 'done' => false];

    private const array ACTION = ['text' => '', 'done' => false, 'due_on' => null];

    public function __construct(private OneOnOneRepository $meetings) {}

    /** @return Collection<int, OneOnOne> */
    public function list(PerformViewer $viewer, ?int $employeeId, ?string $status): Collection
    {
        return $this->meetings->list($viewer->visibleIds(), $viewer->selfId(), $employeeId, $status, self::LIMIT);
    }

    public function canView(PerformViewer $viewer, OneOnOne $meeting): bool
    {
        return $viewer->sees($meeting->employee_id) || $viewer->isSelf($meeting->manager_employee_id);
    }

    public function canManage(PerformViewer $viewer, OneOnOne $meeting): bool
    {
        return $viewer->manages($meeting->employee_id) || $viewer->isSelf($meeting->manager_employee_id);
    }

    public function isMeetingManager(PerformViewer $viewer, OneOnOne $meeting): bool
    {
        return $viewer->isSelf($meeting->manager_employee_id);
    }

    /** @throws ModelNotFoundException<OneOnOne> */
    public function findVisible(PerformViewer $viewer, int $id): OneOnOne
    {
        $meeting = $this->meetings->find($id);
        if ($meeting === null || ! $this->canView($viewer, $meeting)) {
            throw (new ModelNotFoundException)->setModel(OneOnOne::class, [$id]);
        }

        return $meeting;
    }

    /**
     * A manager schedules a 1:1 with someone below them (admins: any pair). The agenda is copied from the template
     * unless given.
     *
     * @param  array{employee_id: int, manager_employee_id?: int|null, scheduled_at: string, template_id?: int|null, agenda?: list<array<string, mixed>>|null}  $data
     *
     * @throws AuthorizationException|PerformException
     */
    public function create(User $actor, PerformViewer $viewer, array $data): OneOnOne
    {
        $managerId = $data['manager_employee_id'] ?? $viewer->selfId() ?? throw PerformException::noEmployee();
        if ($managerId === $data['employee_id']) {
            throw PerformException::selfTarget();
        }
        if (! $viewer->admin() && ! ($viewer->isSelf($managerId) && $viewer->isAbove($data['employee_id']))) {
            throw new AuthorizationException;
        }
        $template = isset($data['template_id']) ? $this->meetings->findTemplate($data['template_id']) : null;
        $agenda = $data['agenda'] ?? array_map(static fn (string $text): array => ['text' => $text], $template->agenda ?? []);

        return $this->meetings->create([
            'manager_employee_id' => $managerId,
            'employee_id' => $data['employee_id'],
            'scheduled_at' => $data['scheduled_at'],
            'template_id' => $template?->id,
            'agenda' => ListItems::normalize($agenda, self::ITEM),
            'action_items' => [],
            'created_by' => $actor->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data  validated subset of scheduled_at, status, agenda, notes_shared, notes_private_manager, action_items
     *
     * @throws AuthorizationException
     */
    public function update(PerformViewer $viewer, OneOnOne $meeting, array $data): OneOnOne
    {
        if (array_key_exists('notes_private_manager', $data) && ! $this->isMeetingManager($viewer, $meeting)) {
            throw new AuthorizationException;
        }
        if ((array_key_exists('scheduled_at', $data) || array_key_exists('status', $data)) && ! $this->canManage($viewer, $meeting)) {
            throw new AuthorizationException;
        }
        $attributes = array_intersect_key($data, array_flip(['scheduled_at', 'status', 'notes_shared', 'notes_private_manager']));
        if (isset($data['agenda']) && is_array($data['agenda'])) {
            $attributes['agenda'] = ListItems::normalize(array_values($data['agenda']), self::ITEM);
        }
        if (isset($data['action_items']) && is_array($data['action_items'])) {
            $attributes['action_items'] = ListItems::normalize(array_values($data['action_items']), self::ACTION);
        }
        if (isset($attributes['status']) && $attributes['status'] instanceof OneOnOneStatus) {
            $attributes['status'] = $attributes['status']->value;
        }

        return $this->meetings->update($meeting, $attributes);
    }

    /** @throws AuthorizationException */
    public function delete(PerformViewer $viewer, OneOnOne $meeting): void
    {
        if (! $this->canManage($viewer, $meeting)) {
            throw new AuthorizationException;
        }
        $this->meetings->delete($meeting);
    }

    /** @return Collection<int, OneOnOneTemplate> */
    public function templates(): Collection
    {
        return $this->meetings->templates();
    }

    public function findTemplate(int $id): OneOnOneTemplate
    {
        return $this->meetings->findTemplate($id) ?? throw (new ModelNotFoundException)->setModel(OneOnOneTemplate::class, [$id]);
    }

    /** @param  array{name: string, agenda: list<string>}  $data */
    public function saveTemplate(User $actor, ?OneOnOneTemplate $template, array $data): OneOnOneTemplate
    {
        return $this->meetings->saveTemplate($template, $data + ($template === null ? ['created_by' => $actor->id] : []));
    }

    public function deleteTemplate(OneOnOneTemplate $template): void
    {
        $this->meetings->deleteTemplate($template);
    }
}
