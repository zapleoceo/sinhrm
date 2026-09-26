<?php

declare(strict_types=1);

namespace App\Modules\Desk\Services;

use App\Models\User;
use App\Modules\Desk\Contracts\DeskRepository;
use App\Modules\Desk\Enums\CaseStatus;
use App\Modules\Desk\Exceptions\DeskException;
use App\Modules\Desk\Models\DeskAttachment;
use App\Modules\Desk\Models\DeskCase;
use App\Modules\Desk\Models\DeskCategory;
use App\Modules\Desk\Models\DeskComment;
use App\Modules\Documents\Contracts\DocumentStorage;
use App\Modules\Documents\Repositories\DatabaseDocumentStorage;
use App\Modules\Knowledge\Contracts\PublishedArticles;
use App\Modules\People\Services\PeopleScope;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * HR helpdesk. The employee opens cases and sees only their own (public replies, never internal notes);
 * HR staff (superadmin/admin/hr_manager): the queue, assignment, statuses, internal notes, knowledge links.
 * Invisible cases answer 404 (no existence leak).
 */
final readonly class DeskService
{
    public const int LIMIT = 300;

    public const int MAX_FILES = 10;

    public function __construct(
        private DeskRepository $desk,
        private PeopleScope $scope,
        private PublishedArticles $articles,
    ) {}

    public function isHr(User $user): bool
    {
        return $this->scope->isAdmin($user);
    }

    /** @return Collection<int, DeskCategory> */
    public function categories(User $user, bool $withInactive): Collection
    {
        return $this->desk->categories($withInactive && $this->isHr($user));
    }

    /** @param  array<string, mixed>  $attributes */
    public function saveCategory(?DeskCategory $category, array $attributes): DeskCategory
    {
        if (isset($attributes['default_assignee_id']) && ! $this->desk->isHrUser((int) $attributes['default_assignee_id'])) {
            throw DeskException::invalidAssignee();
        }

        return $this->desk->saveCategory($category, $attributes);
    }

    public function findCategory(int $id): DeskCategory
    {
        return $this->desk->findCategory($id) ?? abort(404);
    }

    /** @return Collection<int, DeskCase> own cases (none without an employee record) */
    public function mine(User $user): Collection
    {
        $self = $this->scope->employeeOf($user);

        return $self === null ? new Collection : $this->desk->cases(['employee_id' => $self->id], self::LIMIT);
    }

    /** How many of mine() have this status (sidebar counter; 0 without an employee record). */
    public function countMine(User $user, CaseStatus $status): int
    {
        $self = $this->scope->employeeOf($user);

        return $self === null ? 0 : $this->desk->countCases(['employee_id' => $self->id, 'status' => $status->value]);
    }

    /**
     * How many queue() would list without the limit (sidebar counter).
     *
     * @param  array{status?: string|null, assignee_id?: int|null, category_id?: int|null, open?: bool}  $filter
     */
    public function countQueue(array $filter): int
    {
        return $this->desk->countCases($filter);
    }

    /**
     * HR queue with filters.
     *
     * @param  array{status?: string|null, assignee_id?: int|null, category_id?: int|null, open?: bool}  $filter
     * @return Collection<int, DeskCase>
     */
    public function queue(array $filter): Collection
    {
        return $this->desk->cases($filter, self::LIMIT);
    }

    public function findVisible(User $user, int $id): DeskCase
    {
        $case = $this->desk->findCase($id);
        if ($case === null || ! ($this->isHr($user) || $this->isRequester($user, $case))) {
            abort(404);
        }

        return $case;
    }

    public function isRequester(User $user, DeskCase $case): bool
    {
        return $user->isActive() && $case->employee->user_id === $user->id;
    }

    public function open(User $user, int $categoryId, string $subject, string $body): DeskCase
    {
        $self = $this->scope->employeeOf($user) ?? throw DeskException::noEmployee();
        $category = $this->desk->findCategory($categoryId);
        if ($category === null || ! $category->active) {
            throw DeskException::categoryInactive();
        }
        $case = $this->desk->createCase([
            'employee_id' => $self->id,
            'category_id' => $category->id,
            'subject' => $subject,
            'body' => $body,
            'status' => CaseStatus::New->value,
            'assignee_id' => $category->default_assignee_id,
            'created_by' => $user->id,
        ]);

        return $this->reload($case);
    }

    /**
     * HR changes status / assignee / category. The requester may only close their own case.
     *
     * @param  array{status?: CaseStatus|null, assignee_id?: int|null, category_id?: int|null, unassign?: bool}  $changes
     */
    public function update(User $user, DeskCase $case, array $changes, ?Carbon $now = null): DeskCase
    {
        $now ??= Carbon::now();
        $hr = $this->isHr($user);
        $status = $changes['status'] ?? null;
        if (! $hr && ($status !== CaseStatus::Closed || isset($changes['assignee_id']) || isset($changes['category_id']) || ($changes['unassign'] ?? false))) {
            abort(403);
        }
        $attributes = [];
        if (isset($changes['assignee_id'])) {
            if (! $this->desk->isHrUser($changes['assignee_id'])) {
                throw DeskException::invalidAssignee();
            }
            $attributes['assignee_id'] = $changes['assignee_id'];
        } elseif ($changes['unassign'] ?? false) {
            $attributes['assignee_id'] = null;
        }
        if (isset($changes['category_id'])) {
            $attributes['category_id'] = $this->findCategory($changes['category_id'])->id;
        }
        if ($status !== null && $status !== $case->status) {
            $attributes += self::statusAttributes($case, $status, $now);
        }

        return $attributes === [] ? $case : $this->reload($this->desk->updateCase($case, $attributes));
    }

    /**
     * A reply in the thread. HR: public or internal, may link a published knowledge article; the first public HR
     * reply stops the first-response clock and moves a new case to "in progress". The requester: public only;
     * their reply to a "waiting" case moves it back to "in progress".
     */
    public function comment(User $user, DeskCase $case, string $body, bool $internal, ?int $articleId, ?Carbon $now = null): DeskComment
    {
        $now ??= Carbon::now();
        $hr = $this->isHr($user);
        if (! $hr && ($internal || $articleId !== null)) {
            abort(403);
        }
        if ($case->status === CaseStatus::Closed) {
            throw DeskException::caseClosed();
        }
        if ($articleId !== null && $this->articles->titles([$articleId]) === []) {
            throw DeskException::articleNotFound();
        }
        $comment = $this->desk->addComment([
            'case_id' => $case->id,
            'author_id' => $user->id,
            'body' => $body,
            'internal' => $internal,
            'article_id' => $articleId,
        ]);

        $requester = $this->isRequester($user, $case);
        $attributes = [];
        if (! $internal && ! $requester) {
            if ($case->first_response_at === null) {
                $attributes['first_response_at'] = $now;
            }
            if ($case->status === CaseStatus::New) {
                $attributes['status'] = CaseStatus::InProgress->value;
            }
        } elseif ($requester && $case->status === CaseStatus::Waiting) {
            $attributes['status'] = CaseStatus::InProgress->value;
        }
        if ($attributes !== []) {
            $this->desk->updateCase($case, $attributes);
        }

        return $comment;
    }

    /** Attaches a small file (Documents limits: ≤ 2 MB, type detected from the bytes). */
    public function attach(User $user, DeskCase $case, string $content, string $filename): DeskAttachment
    {
        if ($case->status === CaseStatus::Closed) {
            throw DeskException::caseClosed();
        }
        if (strlen($content) > DocumentStorage::MAX_BYTES) {
            throw DeskException::fileTooLarge();
        }
        $mime = DatabaseDocumentStorage::detect($content, $filename) ?? throw DeskException::invalidFile();
        if ($this->desk->attachmentCount($case->id) >= self::MAX_FILES) {
            throw DeskException::tooManyFiles();
        }

        return $this->desk->addAttachment([
            'case_id' => $case->id,
            'uploaded_by' => $user->id,
            'filename' => DatabaseDocumentStorage::safeName($filename),
            'mime' => $mime,
            'size' => strlen($content),
            'sha256' => hash('sha256', $content),
            'content' => base64_encode($content),
        ]);
    }

    public function attachment(DeskCase $case, int $id): DeskAttachment
    {
        return $this->desk->findAttachment($case->id, $id) ?? abort(404);
    }

    /**
     * Titles of linked (still published) articles of the thread.
     *
     * @param  iterable<DeskComment>  $comments
     * @return array<int, string>
     */
    public function articleTitles(iterable $comments): array
    {
        $ids = [];
        foreach ($comments as $c) {
            if ($c->article_id !== null) {
                $ids[] = $c->article_id;
            }
        }

        return $ids === [] ? [] : $this->articles->titles(array_values(array_unique($ids)));
    }

    /** @return array<string, mixed> */
    private static function statusAttributes(DeskCase $case, CaseStatus $status, Carbon $now): array
    {
        $attributes = ['status' => $status->value];
        if ($status === CaseStatus::Resolved) {
            $attributes['resolved_at'] = $case->resolved_at ?? $now;
        } elseif ($status === CaseStatus::Closed) {
            $attributes['closed_at'] = $now;
            $attributes['resolved_at'] = $case->resolved_at ?? $now;
        } else {
            // Reopened: the resolve clock runs again from the original opening time.
            $attributes['resolved_at'] = null;
            $attributes['closed_at'] = null;
        }

        return $attributes;
    }

    private function reload(DeskCase $case): DeskCase
    {
        return $this->desk->findCase($case->id) ?? $case;
    }
}
