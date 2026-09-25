<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Services;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Enums\DirectoryStatus;
use App\Modules\Directory\Models\Branch;
use App\Modules\Directory\Models\City;
use App\Modules\Directory\Models\Position;
use App\Modules\Recruiting\Contracts\PipelineRepository;
use App\Modules\Recruiting\Contracts\TouchpointIngestor;
use App\Modules\Recruiting\DTO\CandidateData;
use App\Modules\Recruiting\DTO\DemoReport;
use App\Modules\Recruiting\DTO\IncomingMessage;
use App\Modules\Recruiting\DTO\MoveData;
use App\Modules\Recruiting\DTO\TouchpointData;
use App\Modules\Recruiting\DTO\VacancyData;
use App\Modules\Recruiting\Enums\CandidateSource;
use App\Modules\Recruiting\Enums\Channel;
use App\Modules\Recruiting\Enums\Direction;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Models\PipelineStage;
use App\Modules\Recruiting\Models\Vacancy;
use Illuminate\Contracts\Foundation\Application as Laravel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Synthetic demo data for preview environments: branches, recruiters, vacancies and ~40 candidates spread over the
 * pipeline with touches on every channel (made in the product and captured), some stale, some unmatched messages.
 * Everything goes through the real services, so the data obeys the same rules as production.
 * Names are combined from generic first/last name lists (no real people; Faker is a dev dependency and is absent on
 * deploys); e-mails use the reserved example.test domain, phones are made-up numbers.
 */
final class RecruitingDemoData
{
    public const string MARKER_EMAIL = 'demo-recruiter-1@example.test';

    private const int CANDIDATES = 40;

    private const int UNMATCHED = 6;

    private const array FIRST_NAMES = [
        'Олена', 'Андрій', 'Марія', 'Дмитро', 'Ірина', 'Олег', 'Наталія', 'Сергій', 'Юлія', 'Максим',
        'Тетяна', 'Богдан', 'Софія', 'Віктор', 'Катерина', 'Роман', 'Анна', 'Ігор', 'Вікторія', 'Павло',
    ];

    private const array LAST_NAMES = [
        'Коваленко', 'Бондаренко', 'Ткаченко', 'Кравченко', 'Олійник', 'Шевчук', 'Поліщук', 'Мельничук', 'Савченко',
        'Руденко', 'Марченко', 'Литвиненко', 'Гончаренко', 'Мороз', 'Кузьменко', 'Павленко', 'Лисенко',
    ];

    private const array PHRASES = [
        'Доброго дня! Ще актуальна вакансія?', 'Дякую, підходить час після обіду.', 'Надіслав резюме, перевірте, будь ласка.',
        'Можна перенести співбесіду на завтра?', 'Які умови оплати на випробувальному?', 'Буду вчасно, дякую.',
    ];

    private int $externalSeq = 0;

    private int $nameSeq = 0;

    public function __construct(
        private readonly CandidateService $candidates,
        private readonly ApplicationService $applications,
        private readonly VacancyService $vacancies,
        private readonly TouchpointService $touchpoints,
        private readonly TouchpointIngestor $ingestor,
        private readonly PipelineRepository $pipelines,
        private readonly Laravel $app,
        private readonly LoggerInterface $log,
    ) {}

    public function alreadyGenerated(): bool
    {
        return User::query()->where('email', self::MARKER_EMAIL)->exists();
    }

    /**
     * Creates the demo set once per DB. Works without console commands (runs inside the HTTP ops/migrate request
     * through RecruitingDemoSeeder).
     *
     * @throws RuntimeException in production
     */
    public function generate(): DemoReport
    {
        if ($this->app->isProduction()) {
            throw new RuntimeException('Recruiting demo data is not allowed in production.');
        }
        if ($this->alreadyGenerated()) {
            return new DemoReport(true, [], 0.0);
        }
        $started = hrtime(true);
        $counts = $this->create();
        $seconds = round((hrtime(true) - $started) / 1e9, 2);
        $this->log->info('recruiting.demo_generated', ['seconds' => $seconds] + $counts);

        return new DemoReport(false, $counts, $seconds);
    }

    /** @return array<string, int> counts of created records */
    private function create(): array
    {
        return DB::transaction(function (): array {
            $branches = $this->branches();
            $recruiters = $this->recruiters($branches);
            $admin = $recruiters[0];
            $vacancies = $this->vacancyList($admin, $branches, $recruiters);
            $stages = $this->pipelines->defaultPipeline()?->stages->values()->all() ?? [];
            $reasons = $this->pipelines->rejectReasons(true)->pluck('id')->all();

            $touches = 0;
            for ($i = 0; $i < self::CANDIDATES; $i++) {
                $vacancy = $vacancies[$i % count($vacancies)];
                $recruiter = $recruiters[$i % count($recruiters)];
                $touches += $this->candidateStory($i, $recruiter, $vacancy, $stages, $reasons);
            }
            for ($i = 0; $i < self::UNMATCHED; $i++) {
                $this->unmatched($i, $branches[$i % count($branches)], $recruiters[$i % count($recruiters)]);
            }

            return [
                'branches' => count($branches),
                'recruiters' => count($recruiters),
                'vacancies' => count($vacancies),
                'candidates' => self::CANDIDATES,
                'touchpoints' => $touches,
                'unmatched' => self::UNMATCHED,
            ];
        });
    }

    /** @return list<Branch> */
    private function branches(): array
    {
        $existing = Branch::query()->where('status', DirectoryStatus::Active->value)->orderBy('id')->limit(3)->get()->all();
        if (count($existing) >= 2) {
            return array_values($existing);
        }
        $cities = [];
        foreach (['Демо-місто Північ', 'Демо-місто Південь'] as $name) {
            $cities[] = City::query()->firstOrCreate(['name' => $name], ['status' => DirectoryStatus::Active]);
        }
        foreach (['Менеджер з продажу', 'Адміністратор філії', 'Викладач'] as $name) {
            Position::query()->firstOrCreate(['name' => 'Демо: '.$name], ['status' => DirectoryStatus::Active]);
        }
        $branches = [];
        foreach (['Демо-філія Центр', 'Демо-філія Лівий берег', 'Демо-філія Південь'] as $i => $name) {
            $branches[] = Branch::query()->firstOrCreate(
                ['name' => $name],
                ['status' => DirectoryStatus::Active, 'city_id' => $cities[$i % 2]->id],
            );
        }

        return $branches;
    }

    /**
     * @param  list<Branch>  $branches
     * @return list<User> first one is an admin (sees everything), others are recruiters of one branch each
     */
    private function recruiters(array $branches): array
    {
        $users = [];
        foreach ([UserRole::Admin, UserRole::Recruiter, UserRole::Recruiter] as $i => $role) {
            $user = User::query()->firstOrCreate(
                ['email' => 'demo-recruiter-'.($i + 1).'@example.test'],
                ['name' => $this->nextName(), 'status' => 'active'],
            );
            $user->syncRoles([$role->value]);
            $user->branches()->sync([$branches[$i % count($branches)]->id]);
            $users[] = $user;
        }
        $viewer = User::query()->firstOrCreate(['email' => 'demo-viewer@example.test'], ['name' => $this->nextName(), 'status' => 'active']);
        $viewer->syncRoles([UserRole::Viewer->value]);
        $viewer->branches()->sync(array_map(static fn (Branch $b): int => $b->id, $branches));

        return $users;
    }

    /**
     * @param  list<Branch>  $branches
     * @param  list<User>  $recruiters
     * @return list<Vacancy>
     */
    private function vacancyList(User $admin, array $branches, array $recruiters): array
    {
        $positions = Position::query()->where('status', DirectoryStatus::Active->value)->orderBy('id')->limit(3)->pluck('id')->all();
        $titles = ['Менеджер з продажу', 'Адміністратор', 'Викладач англійської', 'Менеджер з продажу (вечірня зміна)', 'Координатор навчання'];
        $list = [];
        foreach ($titles as $i => $title) {
            $list[] = $this->vacancies->create($admin, new VacancyData([
                'title' => $title,
                'branch_id' => $branches[$i % count($branches)]->id,
                'position_id' => $positions === [] ? null : $positions[$i % count($positions)],
                'recruiter_id' => $recruiters[$i % count($recruiters)]->id,
                'status' => $i === 4 ? 'paused' : 'open',
                'description' => 'Демонстраційна вакансія (синтетичні дані).',
            ]));
        }

        return $list;
    }

    /**
     * One candidate's route: created N days ago, moved through some stages with touches between steps.
     *
     * @param  list<PipelineStage>  $stages
     * @param  list<int>  $reasons
     */
    private function candidateStory(int $i, User $recruiter, Vacancy $vacancy, array $stages, array $reasons): int
    {
        $sources = [CandidateSource::WorkUa, CandidateSource::RobotaUa, CandidateSource::MetaAds, CandidateSource::Site, CandidateSource::Referral, CandidateSource::Telegram, CandidateSource::Manual];
        $source = $sources[$i % count($sources)];
        $phone = sprintf('+38067%07d', 1000000 + $i * 7919);
        $email = sprintf('candidate%02d@example.test', $i + 1);
        $telegram = $i % 3 === 0 ? sprintf('demo_cand_%02d', $i + 1) : null;
        $start = Carbon::now()->subDays(28 - ($i % 25))->setTime(9 + $i % 8, ($i * 7) % 60);

        $candidate = $this->candidates->create($recruiter, new CandidateData(
            fullName: $this->nextName(),
            phone: $phone,
            email: $email,
            telegram: $telegram,
            source: $source,
            utm: $source === CandidateSource::MetaAds ? ['utm_source' => 'facebook', 'utm_medium' => 'paid', 'utm_campaign' => 'demo-autumn'] : null,
            tags: $i % 4 === 0 ? ['демо', 'вечірня зміна'] : ['демо'],
            ownerId: $recruiter->id,
        ));
        $this->backdate($candidate, $start);
        $application = $this->applications->apply($recruiter, $candidate, $vacancy, $start);
        $this->backdate($application, $start);

        // How far the candidate got: 0..5 regular steps; every 6th is rejected, every 9th hired.
        $steps = $i % 6;
        $at = $start->copy();
        $touches = 0;
        $stale = $i % 7 === 3; // no contact for the last days
        $channels = [Channel::Call, Channel::Telegram, Channel::Whatsapp, Channel::Viber, Channel::Email, Channel::Meeting, Channel::Note];
        for ($s = 1; $s <= $steps && $s < count($stages) - 2; $s++) {
            $at = $at->copy()->addHours(6 + ($i * $s) % 30);
            if ($at->isFuture()) {
                break;
            }
            $touches += $this->touch($candidate, $recruiter, $channels[($i + $s) % count($channels)], $at, $telegram);
            $application = $this->applications->move($recruiter, $application, new MoveData($stages[$s]->id), $at->copy()->addMinutes(20));
        }
        $end = end($stages);
        if ($i % 6 === 5 && $end instanceof PipelineStage && $reasons !== []) {
            $at = $at->copy()->addHours(5);
            $this->applications->move($recruiter, $application, new MoveData($end->id, 'Демо: відмова', $reasons[$i % count($reasons)]), $this->notFuture($at));
        } elseif ($i % 9 === 8 && count($stages) >= 2) {
            $at = $at->copy()->addHours(5);
            $this->applications->move($recruiter, $application, new MoveData($stages[count($stages) - 2]->id), $this->notFuture($at));
        }
        if (! $stale) {
            $recent = Carbon::now()->subHours(3 + $i * 2);
            $touches += $this->touch($candidate, $recruiter, $channels[$i % count($channels)], $recent->gt($at) ? $recent : $at->copy()->addHour(), $telegram);
        }

        return $touches;
    }

    /** A touch on the given channel: messengers/calls/e-mail are "captured" (ingestor), notes/meetings are manual. */
    private function touch(Candidate $candidate, User $author, Channel $channel, Carbon $at, ?string $telegram): int
    {
        $at = $this->notFuture($at);
        if ($channel === Channel::Note || $channel === Channel::Meeting) {
            $this->touchpoints->log($author, $candidate, new TouchpointData(
                channel: $channel,
                direction: Direction::Out,
                body: $channel === Channel::Note ? 'Демо-нотатка: кандидат зацікавлений, уточнити графік.' : 'Демо-зустріч у філії.',
                occurredAt: $at,
                meta: $channel === Channel::Meeting ? ['duration_sec' => 1800] : [],
            ));

            return 1;
        }
        $contact = match ($channel) {
            Channel::Email => $candidate->email,
            Channel::Telegram => $telegram !== null ? '@'.$telegram : $candidate->phone,
            default => $candidate->phone,
        };
        $viaProduct = $this->externalSeq % 2 === 0;
        $this->ingestor->ingest(new IncomingMessage(
            channel: $channel,
            direction: $viaProduct ? Direction::Out : Direction::In,
            occurredAt: $at,
            contact: $contact,
            body: $channel === Channel::Call ? null : self::PHRASES[$this->externalSeq % count(self::PHRASES)],
            externalId: 'demo-'.(++$this->externalSeq),
            integrationKey: 'demo',
            authorId: $author->id,
            viaProduct: $viaProduct,
            meta: $channel === Channel::Call ? ['duration_sec' => 60 + ($this->externalSeq * 37) % 600] : [],
        ));

        return 1;
    }

    private function unmatched(int $i, Branch $branch, User $recruiter): void
    {
        $channels = [Channel::Telegram, Channel::Whatsapp, Channel::Viber, Channel::Call, Channel::Email, Channel::Telegram];
        $channel = $channels[$i % count($channels)];
        $contact = match ($channel) {
            Channel::Email => sprintf('unknown%02d@example.test', $i + 1),
            Channel::Telegram => sprintf('@demo_unknown_%02d', $i + 1),
            default => sprintf('+38093%07d', 2000000 + $i * 104729),
        };
        $this->ingestor->ingest(new IncomingMessage(
            channel: $channel,
            direction: Direction::In,
            occurredAt: Carbon::now()->subHours(2 + $i * 9),
            contact: $contact,
            body: $channel === Channel::Call ? null : 'Демо: добрий день, бачив вакансію, ще актуально?',
            externalId: 'demo-unmatched-'.($i + 1),
            integrationKey: 'demo',
            branchId: $branch->id,
            authorId: $i % 2 === 0 ? $recruiter->id : null,
            meta: $channel === Channel::Call ? ['duration_sec' => 45] : [],
        ));
    }

    /** Deterministic synthetic full name (the same set on every run). */
    private function nextName(): string
    {
        $n = $this->nameSeq++;

        return self::LAST_NAMES[($n * 7) % count(self::LAST_NAMES)].' '.self::FIRST_NAMES[$n % count(self::FIRST_NAMES)];
    }

    private function backdate(Candidate|Application $model, Carbon $at): void
    {
        $model->forceFill(['created_at' => $at])->saveQuietly();
    }

    private function notFuture(Carbon $at): Carbon
    {
        $now = Carbon::now()->subMinute();

        return $at->gt($now) ? $now : $at;
    }
}
