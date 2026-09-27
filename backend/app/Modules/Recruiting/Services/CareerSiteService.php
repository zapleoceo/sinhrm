<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Services;

use App\Modules\Documents\Contracts\DocumentStorage;
use App\Modules\Documents\Repositories\DatabaseDocumentStorage;
use App\Modules\Recruiting\DTO\CandidateData;
use App\Modules\Recruiting\Enums\AddedVia;
use App\Modules\Recruiting\Enums\CandidateSource;
use App\Modules\Recruiting\Enums\VacancyStatus;
use App\Modules\Recruiting\Exceptions\RecruitingException;
use App\Modules\Recruiting\Models\CareerSubmission;
use App\Modules\Recruiting\Models\Vacancy;
use App\Modules\Scripts\Services\TaskService;
use finfo;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

/**
 * Public career page (/jobs): published open vacancies and anonymous applications. An application goes through the
 * same createOrMatch as other machine sources (dedup by e-mail/phone, first stage), source "site" → channel
 * "Career site", added_via career_site, plus a "call the new applicant" task for the vacancy recruiter.
 * Anti-spam: a honeypot field (handled by the controller) and MAX_PER_HOUR submissions per hashed client IP.
 */
final readonly class CareerSiteService
{
    public const int MAX_PER_HOUR = 5;

    /** CV: PDF, DOC or DOCX, detected from the bytes; the size limit is the documents one (2 MB). */
    private const array CV_MIMES = ['application/pdf', 'application/msword'];

    public function __construct(
        private CandidateService $candidates,
        private TaskService $tasks,
        private RateLimiter $limiter,
        private LoggerInterface $log,
    ) {}

    /** @return Collection<int, Vacancy> */
    public function published(): Collection
    {
        return Vacancy::query()->with(['branch', 'position'])
            ->where('published', true)->where('status', VacancyStatus::Open->value)
            ->orderByDesc('opened_at')->orderByDesc('id')->get();
    }

    public function findPublished(string $slug): Vacancy
    {
        return Vacancy::query()->with(['branch', 'position'])
            ->where('slug', $slug)->where('published', true)->where('status', VacancyStatus::Open->value)
            ->first() ?? abort(404);
    }

    /** Slug for a published vacancy without one: transliterated title + id (stable after title edits). */
    public static function slugFor(Vacancy $vacancy): string
    {
        $base = Str::limit(Str::slug($vacancy->title), 120, '');

        return ($base === '' ? 'job' : $base).'-'.$vacancy->id;
    }

    /**
     * @throws RecruitingException too_many_requests | no_contacts
     */
    public function apply(
        Vacancy $vacancy,
        string $fullName,
        string $email,
        ?string $phone,
        ?string $message,
        ?string $cvContent,
        ?string $cvName,
        string $ip,
    ): CareerSubmission {
        $ipHash = hash_hmac('sha256', $ip, (string) config('app.key'));
        $key = 'career-apply:'.$ipHash;
        if ($this->limiter->tooManyAttempts($key, self::MAX_PER_HOUR)) {
            throw RecruitingException::tooManySubmissions();
        }
        $this->limiter->hit($key, 3600);

        $cv = $cvContent === null ? null : $this->cv($cvContent, (string) $cvName);
        $now = Carbon::now();
        $match = $this->candidates->createOrMatch(null, new CandidateData(
            fullName: $fullName,
            phone: $phone,
            email: $email,
            source: CandidateSource::Site,
            addedVia: AddedVia::CareerSite,
        ), $vacancy, $now);

        if ($match->applicationCreated && $match->application !== null) {
            $this->tasks->scheduleNewApplicantCall($vacancy->recruiter_id, $match->candidate->id, $match->application->id, $now);
        }
        $submission = CareerSubmission::query()->create([
            'vacancy_id' => $vacancy->id,
            'candidate_id' => $match->candidate->id,
            'application_id' => $match->application?->id,
            'message' => $message,
            'consent_at' => $now,
            'ip_hash' => $ipHash,
            ...($cv ?? []),
        ]);
        $this->log->info('recruiting.career_applied', [
            'vacancy' => $vacancy->id, 'candidate' => $match->candidate->id, 'new_candidate' => $match->created,
        ]);

        return $submission;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RecruitingException invalid_cv
     */
    private function cv(string $content, string $name): array
    {
        if ($content === '' || strlen($content) > DocumentStorage::MAX_BYTES) {
            throw RecruitingException::invalidCv();
        }
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($content);
        if (! in_array($mime, self::CV_MIMES, true)) {
            $docx = DatabaseDocumentStorage::detect($content, $name);
            if ($docx === null || $docx === 'application/pdf' || str_starts_with($docx, 'image/')) {
                throw RecruitingException::invalidCv();
            }
            $mime = $docx;
        }

        return [
            'cv_filename' => DatabaseDocumentStorage::safeName($name === '' ? 'cv' : $name),
            'cv_mime' => $mime,
            'cv_size' => strlen($content),
            'cv_sha256' => hash('sha256', $content),
            'cv_content' => base64_encode($content),
        ];
    }
}
