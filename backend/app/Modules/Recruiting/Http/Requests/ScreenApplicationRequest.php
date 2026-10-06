<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Requests;

use App\Models\User;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Services\RecruitingScope;
use App\Modules\Recruiting\Support\ApplicationVisibility;
use Illuminate\Foundation\Http\FormRequest;

/** POST /applications/{application}/screening — no body; requires candidate edit and application visibility. */
final class ScreenApplicationRequest extends FormRequest
{
    public function authorize(RecruitingScope $scope): bool
    {
        $application = $this->route('application');
        $actor = $this->user();

        return $application instanceof Application && $actor instanceof User
            && (bool) $actor->can('update', $application->candidate)
            && ApplicationVisibility::query($scope->for($actor))->whereKey($application->id)->exists();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
