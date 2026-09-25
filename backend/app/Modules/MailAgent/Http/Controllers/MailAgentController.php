<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Http\Controllers;

use App\Models\User;
use App\Modules\MailAgent\Contracts\MailLogRepository;
use App\Modules\MailAgent\Contracts\SenderRuleRepository;
use App\Modules\MailAgent\Contracts\UnknownSenderRepository;
use App\Modules\MailAgent\Enums\SenderKind;
use App\Modules\MailAgent\Http\Requests\AssignSenderRequest;
use App\Modules\MailAgent\Http\Requests\SaveSenderRuleRequest;
use App\Modules\MailAgent\Http\Resources\MailMessageResource;
use App\Modules\MailAgent\Http\Resources\SenderRuleResource;
use App\Modules\MailAgent\Http\Resources\UnknownSenderResource;
use App\Modules\MailAgent\Models\SenderRule;
use App\Modules\MailAgent\Models\UnknownSender;
use App\Modules\MailAgent\Services\MailAgentService;
use App\Modules\MailAgent\Services\MailSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Admin → Mail (superadmin): status, "Sync now", sender rules, unknown senders, processed log. */
final readonly class MailAgentController
{
    public const int LIST_LIMIT = 50;

    public function __construct(
        private MailAgentService $service,
        private MailSyncService $sync,
        private SenderRuleRepository $rules,
        private UnknownSenderRepository $unknown,
        private MailLogRepository $log,
    ) {}

    public function status(): JsonResponse
    {
        return new JsonResponse(['data' => $this->service->status()]);
    }

    /** POST /api/mail/sync → {data: counters}; not connected → 422, reconnect_required → 409. */
    public function sync(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->sync->sync('manual', $this->actor($request))]);
    }

    public function rules(): AnonymousResourceCollection
    {
        return SenderRuleResource::collection($this->rules->all());
    }

    public function storeRule(SaveSenderRuleRequest $request): JsonResponse
    {
        $rule = $this->service->createRule($this->actor($request), (string) $request->pattern(), SenderKind::from($request->string('kind')->toString()), $request->parser());

        return (new SenderRuleResource($rule))->response()->setStatusCode(201);
    }

    public function updateRule(SaveSenderRuleRequest $request, SenderRule $rule): SenderRuleResource
    {
        return new SenderRuleResource($this->service->updateRule(
            $this->actor($request),
            $rule,
            $request->pattern(),
            $request->kind(),
            $request->parser(),
            $request->has('parser'),
        ));
    }

    public function deleteRule(Request $request, SenderRule $rule): JsonResponse
    {
        $this->service->deleteRule($this->actor($request), $rule);

        return new JsonResponse(null, 204);
    }

    public function unknown(): AnonymousResourceCollection
    {
        return UnknownSenderResource::collection($this->unknown->list(self::LIST_LIMIT));
    }

    public function assign(AssignSenderRequest $request, UnknownSender $sender): JsonResponse
    {
        $rule = $this->service->assign($this->actor($request), $sender, $request->kind(), $request->parser(), $request->wholeDomain());

        return (new SenderRuleResource($rule))->response()->setStatusCode(201);
    }

    public function dismiss(UnknownSender $sender): JsonResponse
    {
        $this->service->dismiss($sender);

        return new JsonResponse(null, 204);
    }

    public function messages(): AnonymousResourceCollection
    {
        return MailMessageResource::collection($this->log->recent(self::LIST_LIMIT));
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        assert($actor instanceof User);

        return $actor;
    }
}
