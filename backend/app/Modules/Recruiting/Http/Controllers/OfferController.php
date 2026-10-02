<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Controllers;

use App\Modules\Core\Http\Concerns\ResolvesActor;
use App\Modules\Recruiting\Enums\OfferStatus;
use App\Modules\Recruiting\Http\Requests\OfferRequest;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\Offer;
use App\Modules\Recruiting\Providers\RecruitingServiceProvider;
use App\Modules\Recruiting\Services\OfferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Offer of an application. Every action (reading too) needs the "offer" ability: the salary is sensitive. */
final class OfferController
{
    use ResolvesActor;

    public function __construct(private readonly OfferService $service) {}

    /** Active Documents templates of category "offer" (id + name) for the "Створити оффер" form. */
    public function templates(Request $request): JsonResponse
    {
        Gate::forUser($this->actor($request))->authorize(RecruitingServiceProvider::WRITE);

        return new JsonResponse(['data' => $this->service->templates()]);
    }

    public function show(Request $request, Application $application): JsonResponse
    {
        Gate::forUser($this->actor($request))->authorize('offer', $application);
        $offer = $this->service->forApplication($application);

        return new JsonResponse(['data' => $offer === null ? null : self::present($offer)]);
    }

    public function store(OfferRequest $request, Application $application): JsonResponse
    {
        $offer = $this->service->create($this->actor($request), $application, $request->offerData());

        return new JsonResponse(['data' => self::present($offer)], 201);
    }

    public function send(OfferRequest $request, Application $application): JsonResponse
    {
        $offer = $this->service->forApplication($application) ?? abort(404);

        return new JsonResponse(['data' => self::present($this->service->send($this->actor($request), $offer))]);
    }

    public function decision(OfferRequest $request, Application $application): JsonResponse
    {
        $offer = $this->service->forApplication($application) ?? abort(404);
        $status = OfferStatus::from($request->string('status')->toString());

        return new JsonResponse(['data' => self::present($this->service->decide($this->actor($request), $offer, $status))]);
    }

    /** @return array<string, mixed> */
    private static function present(Offer $offer): array
    {
        return [
            'id' => $offer->id,
            'application_id' => $offer->application_id,
            'template_id' => $offer->template_id,
            'position' => $offer->position,
            'salary' => $offer->salary,
            'start_date' => $offer->start_date?->toDateString(),
            'conditions' => $offer->conditions,
            'content_md' => $offer->content_md,
            'status' => $offer->status->value,
            'sent_at' => $offer->sent_at?->toIso8601String(),
            'decided_at' => $offer->decided_at?->toIso8601String(),
        ];
    }
}
