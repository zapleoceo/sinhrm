<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Controllers;

use App\Modules\Recruiting\Http\Requests\ChannelCostRequest;
use App\Modules\Recruiting\Http\Requests\SaveAcquisitionChannelRequest;
use App\Modules\Recruiting\Http\Requests\UtmRuleRequest;
use App\Modules\Recruiting\Http\Resources\AcquisitionChannelPresenter;
use App\Modules\Recruiting\Models\AcquisitionChannel;
use App\Modules\Recruiting\Providers\RecruitingServiceProvider;
use App\Modules\Recruiting\Services\AcquisitionChannelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/** Acquisition channels dictionary (tz3): read for everyone (candidate form, filters), manage — recruiting-manage. */
final class AcquisitionChannelController
{
    public function __construct(private readonly AcquisitionChannelService $channels) {}

    public function index(Request $request): JsonResponse
    {
        $manage = Gate::allows(RecruitingServiceProvider::MANAGE);
        $list = $this->channels->list($manage && $request->boolean('all'));

        return new JsonResponse(['data' => $list->map(static fn (AcquisitionChannel $c): array => AcquisitionChannelPresenter::present($c, $manage))->values()->all()]);
    }

    public function store(SaveAcquisitionChannelRequest $request): JsonResponse
    {
        return new JsonResponse(['data' => AcquisitionChannelPresenter::present($this->channels->save(null, $request->channelAttributes()), true)], 201);
    }

    public function update(SaveAcquisitionChannelRequest $request, int $channel): JsonResponse
    {
        $saved = $this->channels->save($this->channels->find($channel), $request->channelAttributes());

        return new JsonResponse(['data' => AcquisitionChannelPresenter::present($saved, true)]);
    }

    public function addRule(UtmRuleRequest $request, int $channel): JsonResponse
    {
        $model = $this->channels->find($channel);
        $this->channels->addRule($model, $request->rule());

        return new JsonResponse(['data' => AcquisitionChannelPresenter::present($this->channels->find($channel), true)], 201);
    }

    public function deleteRule(int $rule): Response
    {
        $this->channels->deleteRule($rule);

        return new Response(null, 204);
    }

    public function addCost(ChannelCostRequest $request, int $channel): JsonResponse
    {
        $model = $this->channels->find($channel);
        $this->channels->addCost($model, $request->cost());

        return new JsonResponse(['data' => AcquisitionChannelPresenter::present($this->channels->find($channel), true)], 201);
    }

    public function deleteCost(int $cost): Response
    {
        $this->channels->deleteCost($cost);

        return new Response(null, 204);
    }

    /** "Which channel would these UTM tags give?" — the rules editor's test box. */
    public function preview(UtmRuleRequest $request): JsonResponse
    {
        $rule = $request->rule();
        unset($rule['priority']);

        return new JsonResponse(['data' => $this->channels->preview($rule)]);
    }
}
