<?php

declare(strict_types=1);

namespace App\Modules\Perform\Services;

use App\Modules\Perform\Contracts\FeedbackRepository;
use App\Modules\Perform\DTO\PerformViewer;
use App\Modules\Perform\Enums\FeedbackType;
use App\Modules\Perform\Enums\FeedbackVisibility;
use App\Modules\Perform\Exceptions\PerformException;
use App\Modules\Perform\Models\Feedback;
use Illuminate\Database\Eloquent\Collection;

/**
 * Continuous feedback. Anyone with an employee record gives feedback to a colleague or asks for it (a request).
 * Who reads a feedback: its author and recipient; "manager" — also managers above the recipient; "public" — every
 * active user; admins — everything. A request is private to its two people; answering it creates feedback to the
 * requester (once) with the visibility the answerer picks.
 */
final readonly class FeedbackService
{
    public const int LIMIT = 200;

    public const array BOXES = ['received', 'given', 'requests', 'team', 'public'];

    public function __construct(private FeedbackRepository $feedback) {}

    /** @return Collection<int, Feedback> */
    public function list(PerformViewer $viewer, string $box): Collection
    {
        $self = $viewer->selfId();

        return match ($box) {
            'received' => $self === null ? new Collection : $this->feedback->receivedBy($self, self::LIMIT),
            'given' => $self === null ? new Collection : $this->feedback->givenBy($self, self::LIMIT),
            'requests' => $self === null ? new Collection : $this->feedback->openRequestsTo($self, self::LIMIT),
            'team' => $viewer->admin()
                ? $this->feedback->about(null, null, self::LIMIT)
                : $this->feedback->about($viewer->ctx->subtreeIds, [FeedbackVisibility::Manager, FeedbackVisibility::Public], self::LIMIT),
            default => $this->feedback->about(null, [FeedbackVisibility::Public], self::LIMIT),
        };
    }

    public function canView(PerformViewer $viewer, Feedback $item): bool
    {
        return $viewer->admin() || $viewer->isSelf($item->from_employee_id) || $viewer->isSelf($item->to_employee_id)
            || $item->visibility === FeedbackVisibility::Public
            || ($item->visibility === FeedbackVisibility::Manager && $viewer->isAbove($item->to_employee_id));
    }

    public function canAnswer(PerformViewer $viewer, Feedback $item): bool
    {
        return $item->type === FeedbackType::Request && $item->answered_at === null && $viewer->isSelf($item->to_employee_id);
    }

    /**
     * @param  array{to_employee_id?: int|null, type: string, text: string, visibility?: string|null, request_id?: int|null}  $data
     *
     * @throws PerformException no_employee | self_target | request_not_open
     */
    public function give(PerformViewer $viewer, array $data): Feedback
    {
        $self = $viewer->selfId() ?? throw PerformException::noEmployee();
        $type = FeedbackType::from($data['type']);
        $request = isset($data['request_id']) ? $this->feedback->find($data['request_id']) : null;
        if (isset($data['request_id']) && ($request === null || $type === FeedbackType::Request || ! $this->canAnswer($viewer, $request))) {
            throw PerformException::requestNotOpen();
        }
        $to = $request !== null ? $request->from_employee_id : (int) ($data['to_employee_id'] ?? 0);
        if ($to === $self) {
            throw PerformException::selfTarget();
        }
        $visibility = $type === FeedbackType::Request
            ? FeedbackVisibility::PrivateToRecipient
            : FeedbackVisibility::from($data['visibility'] ?? FeedbackVisibility::PrivateToRecipient->value);
        $attributes = [
            'from_employee_id' => $self,
            'to_employee_id' => $to,
            'type' => $type->value,
            'text' => $data['text'],
            'visibility' => $visibility->value,
            'request_id' => $request?->id,
        ];
        if ($request === null) {
            return $this->feedback->create($attributes);
        }

        return $this->feedback->transaction(function () use ($request, $attributes): Feedback {
            if (! $this->feedback->markAnswered($request)) {
                throw PerformException::requestNotOpen();
            }

            return $this->feedback->create($attributes);
        });
    }
}
