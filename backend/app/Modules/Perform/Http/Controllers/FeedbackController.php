<?php

declare(strict_types=1);

namespace App\Modules\Perform\Http\Controllers;

use App\Modules\Perform\Http\Requests\GiveFeedbackRequest;
use App\Modules\Perform\Http\Requests\ListFeedbackRequest;
use App\Modules\Perform\Http\Resources\FeedbackResource;
use App\Modules\Perform\Models\Feedback;
use App\Modules\Perform\Services\FeedbackService;
use App\Modules\Perform\Services\PerformAccess;
use Illuminate\Http\JsonResponse;

/** GET ?box=received|given|requests|team|public; POST gives feedback, asks for it or answers a request. */
final class FeedbackController extends PerformController
{
    public function __construct(PerformAccess $access, private readonly FeedbackService $feedback)
    {
        parent::__construct($access);
    }

    public function index(ListFeedbackRequest $request): JsonResponse
    {
        $box = $request->box();
        $viewer = $this->viewer($request);

        return new JsonResponse(['data' => $this->feedback->list($viewer, $box)
            ->filter(fn (Feedback $f): bool => $this->feedback->canView($viewer, $f))
            ->map(fn (Feedback $f): array => FeedbackResource::for($f, $this->feedback->canAnswer($viewer, $f))->resolve())
            ->values()->all()]);
    }

    public function store(GiveFeedbackRequest $request): JsonResponse
    {
        $feedback = $this->feedback->give($this->viewer($request), $request->payload());

        return FeedbackResource::for($feedback, false)->response()->setStatusCode(201);
    }
}
