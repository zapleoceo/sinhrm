<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Requests;

use App\Modules\Recruiting\DTO\ClipData;
use App\Modules\Recruiting\Enums\ClipperSite;
use App\Modules\Recruiting\Providers\RecruitingServiceProvider;
use App\Modules\Recruiting\Services\ClipperService;
use App\Modules\Recruiting\Support\ContactNormalizer;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/clipper/candidates (browser extension). profile_url must be https on the host of source_site
 * (LinkedIn → linkedin.com, …); contacts must be normalizable. Writers only (recruiter/admin/superadmin).
 */
final class ClipCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(RecruitingServiceProvider::WRITE);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $normalizer = new ContactNormalizer;
        $contact = static fn (string $method): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($normalizer, $method): void {
            if (is_string($value) && $value !== '' && $normalizer->{$method}($value) === null) {
                $fail("The $attribute is not a valid contact.");
            }
        };
        $site = ClipperSite::tryFrom((string) $this->input('source_site'));

        return [
            'full_name' => ['required', 'string', 'min:2', 'max:255'],
            'source_site' => ['required', Rule::enum(ClipperSite::class)],
            'profile_url' => ['required', 'string', 'max:512', static function (string $attribute, mixed $value, Closure $fail) use ($site): void {
                if ($site !== null && (! is_string($value) || $site->normalizeUrl($value) === null)) {
                    $fail("The $attribute must be an https link to {$site->label()}.");
                }
            }],
            'headline' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32', $contact('phone')],
            'email' => ['nullable', 'string', 'max:255', $contact('email')],
            'telegram' => ['nullable', 'string', 'max:80', $contact('telegram')],
            // The extension trims to 2000; a little slack for line-break differences, the service cuts to 2000.
            'summary' => ['nullable', 'string', 'max:'.(ClipperService::SUMMARY_LIMIT + 500)],
            'vacancy_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function clipData(): ClipData
    {
        $site = ClipperSite::from($this->string('source_site')->toString());
        $str = fn (string $key): ?string => $this->filled($key) ? $this->string($key)->trim()->toString() : null;

        return new ClipData(
            fullName: $this->string('full_name')->trim()->toString(),
            site: $site,
            profileUrl: (string) $site->normalizeUrl($this->string('profile_url')->toString()),
            headline: $str('headline'),
            location: $str('location'),
            phone: $str('phone'),
            email: $str('email'),
            telegram: $str('telegram'),
            summary: $str('summary'),
            vacancyId: $this->filled('vacancy_id') ? $this->integer('vacancy_id') : null,
        );
    }
}
