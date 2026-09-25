<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Services;

use App\Models\User;
use App\Modules\Recruiting\Contracts\ApplicationRepository;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Scripts\Contracts\ScriptRepository;
use App\Modules\Scripts\Enums\TemplateVariable;
use App\Modules\Scripts\Support\TemplateRenderer;

/**
 * Message templates of the active scripts, filled for one candidate: {Ім'я} — the first word of the full name,
 * {Рекрутер} — the signed-in user, {Вакансія} — the vacancy of the latest active application. Links and the address
 * have no source yet (vacancies/branches do not store them): the tokens stay for the recruiter to fill in.
 */
final readonly class TemplateService
{
    public function __construct(private ScriptRepository $scripts, private ApplicationRepository $applications) {}

    /** @return list<array{script_id: int, script_name: string, channel: string, key: string, title: string, text: string, missing: list<string>}> */
    public function forCandidate(User $actor, Candidate $candidate): array
    {
        $vacancy = $this->applications->latestActiveFor($candidate->id)?->vacancy;
        $values = [
            TemplateVariable::Name->value => self::firstName($candidate->full_name),
            TemplateVariable::Recruiter->value => $actor->name,
            TemplateVariable::Vacancy->value => $vacancy?->title,
            TemplateVariable::VacancyLink->value => null,
            TemplateVariable::InterviewLink->value => null,
            TemplateVariable::Address->value => null,
        ];

        $out = [];
        foreach ($this->scripts->active() as $script) {
            $version = $script->activeVersion;
            if ($version === null) {
                continue;
            }
            foreach ($version->content()->templates as $template) {
                $filled = TemplateRenderer::render($template['text'], $values);
                $out[] = [
                    'script_id' => $script->id,
                    'script_name' => $script->name,
                    'channel' => $script->channel->value,
                    'key' => $template['key'],
                    'title' => $template['title'],
                    'text' => $filled['text'],
                    'missing' => $filled['missing'],
                ];
            }
        }

        return $out;
    }

    public static function firstName(string $fullName): ?string
    {
        $parts = preg_split('/\s+/u', trim($fullName)) ?: [];

        return ($parts[0] ?? '') === '' ? null : $parts[0];
    }
}
