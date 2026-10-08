<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Services;

use App\Models\User;
use App\Modules\Documents\Contracts\DocumentStorage;
use App\Modules\Documents\DTO\StoredFile;
use App\Modules\Documents\Repositories\DatabaseDocumentStorage;
use App\Modules\Recruiting\Contracts\ApplicationRepository;
use App\Modules\Recruiting\Contracts\VacancyRepository;
use App\Modules\Recruiting\DTO\CandidateData;
use App\Modules\Recruiting\Enums\AddedVia;
use App\Modules\Recruiting\Enums\CandidateSource;
use App\Modules\Recruiting\Exceptions\RecruitingException;
use App\Modules\Recruiting\Models\CareerSubmission;
use App\Modules\Recruiting\Models\Vacancy;
use App\Modules\Scripts\Contracts\TaskScheduler;
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
 * The CV sent with an application is read back by recruiters through cvFor() (GET /api/applications/{id}/cv).
 */
final readonly class CareerSiteService
{
    public const int MAX_PER_HOUR = 5;

    /** CV: PDF, DOC or DOCX, detected from the bytes; the size limit is the documents one (2 MB). */
    private const array CV_MIMES = ['application/pdf', 'application/msword'];

    public function __construct(
        private CandidateService $candidates,
        private TaskScheduler $tasks,
        private RateLimiter $limiter,
        private LoggerInterface $log,
        private VacancyRepository $vacancies,
        private ApplicationRepository $applications,
        private RecruitingScope $scope,
    ) {}

    /** @return Collection<int, Vacancy> */
    public function published(): Collection
    {
        return $this->vacancies->published();
    }

    public function findPublished(string $slug): Vacancy
    {
        return $this->vacancies->findPublishedBySlug($slug) ?? abort(404);
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
        $submission = $this->vacancies->createCareerSubmission([
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
     * The newest CV sent with this application, for a user who sees the application (ApplicationVisibility:
     * branch, hiring manager, interviewer). Not visible, no CV or an unreadable body → 404 (no existence leak).
     * The MIME type is detected from the bytes again, not taken from the row.
     */
    public function cvFor(User $actor, int $applicationId): StoredFile
    {
        abort_unless($this->applications->isVisible($applicationId, $this->scope->for($actor)), 404);
        $submission = $this->applications->latestCv($applicationId) ?? abort(404);
        $content = base64_decode((string) $submission->cv_content, true);
        abort_if($content === false || $content === '', 404);
        $filename = (string) $submission->cv_filename;

        return new StoredFile($filename, self::cvMime($content, $filename) ?? 'application/octet-stream', strlen($content), $content);
    }

    /** PDF, DOC or DOCX detected from the bytes (a .docx is a ZIP: its name tells DOCX from a plain ZIP); else null. */
    public static function cvMime(string $content, string $name): ?string
    {
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($content);
        if (in_array($mime, self::CV_MIMES, true)) {
            return $mime;
        }
        $docx = DatabaseDocumentStorage::detect($content, $name);

        return $docx === null || $docx === 'application/pdf' || str_starts_with($docx, 'image/') ? null : $docx;
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
        $mime = self::cvMime($content, $name) ?? throw RecruitingException::invalidCv();

        return [
            'cv_filename' => DatabaseDocumentStorage::safeName($name === '' ? 'cv' : $name),
            'cv_mime' => $mime,
            'cv_size' => strlen($content),
            'cv_sha256' => hash('sha256', $content),
            'cv_content' => base64_encode($content),
        ];
    }
}
