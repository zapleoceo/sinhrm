<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Requests;

use App\Models\User;
use App\Modules\Directory\Models\City;
use App\Modules\Recruiting\DTO\CandidateData;
use App\Modules\Recruiting\Enums\CandidateSource;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Models\Vacancy;
use App\Modules\Recruiting\Support\ContactNormalizer;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /candidates (full_name required; optional vacancy_id applies right away; a contact match → 409)
 * and PATCH /candidates/{candidate} (partial). Contacts must be normalizable.
 */
final class SaveCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $candidate = $this->route('candidate');

        return $candidate instanceof Candidate
            ? (bool) $this->user()?->can('update', $candidate)
            : (bool) $this->user()?->can('create', Candidate::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $normalizer = new ContactNormalizer;
        $contact = static fn (string $method): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($normalizer, $method): void {
            if (is_string($value) && $value !== '' && $normalizer->{$method}($value) === null) {
                $fail("The $attribute is not a valid contact.");
            }
        };

        return [
            'full_name' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'min:2', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32', $contact('phone')],
            'email' => ['sometimes', 'nullable', 'string', 'max:255', $contact('email')],
            'telegram_username' => ['sometimes', 'nullable', 'string', 'max:80', $contact('telegram')],
            'city_id' => ['sometimes', 'nullable', 'integer', Rule::exists(City::class, 'id')],
            'source' => ['sometimes', 'required', Rule::enum(CandidateSource::class)],
            'utm' => ['sometimes', 'nullable', 'array', 'max:10'],
            'utm.*' => ['nullable', 'string', 'max:255'],
            'tags' => ['sometimes', 'nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'min:1', 'max:40'],
            'owner_id' => ['sometimes', 'nullable', 'integer', Rule::exists(User::class, 'id')],
            'vacancy_id' => $creating ? ['sometimes', 'nullable', 'integer', Rule::exists(Vacancy::class, 'id')] : ['prohibited'],
        ];
    }

    public function candidateData(): CandidateData
    {
        /** @var array<string, mixed> $row */
        $row = $this->safe()->all();
        $data = CandidateData::fromArray($row);

        // fromArray() defaults an unknown source to "import"; for the API a missing source means "manual" on create
        // and "unchanged" on update.
        return new CandidateData(
            fullName: $data->fullName,
            phone: $data->phone,
            email: $data->email,
            telegram: $data->telegram,
            cityId: $data->cityId,
            source: $this->enum('source', CandidateSource::class),
            utm: $data->utm,
            tags: $data->tags,
            ownerId: $data->ownerId,
            vacancyId: $data->vacancyId,
        );
    }
}
