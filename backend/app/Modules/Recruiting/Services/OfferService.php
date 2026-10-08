<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Services;

use App\Models\User;
use App\Modules\Channels\Services\MessageService;
use App\Modules\Documents\Contracts\DocumentTemplateRepository;
use App\Modules\Documents\Enums\DocumentVariable;
use App\Modules\Documents\Exceptions\DocumentException;
use App\Modules\Documents\Support\TemplateFiller;
use App\Modules\Recruiting\Contracts\ApplicationRepository;
use App\Modules\Recruiting\Enums\Channel;
use App\Modules\Recruiting\Enums\OfferStatus;
use App\Modules\Recruiting\Enums\StageKind;
use App\Modules\Recruiting\Exceptions\RecruitingException;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\Offer;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * Offers: generated from a Documents template of category "offer" (variables {ПІБ}, {Посада}, {Зарплата},
 * {Дата виходу}, {Умови}, …), sent to the candidate's e-mail through the Channels e-mail path (Mailer → outbound
 * touchpoint), accepted/declined manually by the recruiter.
 */
final readonly class OfferService
{
    public const string TEMPLATE_CATEGORY = 'offer';

    public function __construct(
        private MessageService $messages,
        private LoggerInterface $log,
        private DocumentTemplateRepository $documentTemplates,
        private ApplicationRepository $applications,
    ) {}

    /** @return list<array{id: int, name: string}> */
    public function templates(): array
    {
        $templates = $this->documentTemplates->activeOfCategory(self::TEMPLATE_CATEGORY);

        return array_values($templates->map(static fn ($t): array => ['id' => $t->id, 'name' => $t->name])->all());
    }

    public function forApplication(Application $application): ?Offer
    {
        return $this->applications->offerFor($application->id);
    }

    /**
     * @param  array{template_id: int, position: string, salary: string, start_date: ?string, conditions: ?string}  $data
     *
     * @throws RecruitingException not_in_offer_stage | offer_exists | template_not_offer
     * @throws DocumentException template_archived
     */
    public function create(User $actor, Application $application, array $data, ?Carbon $today = null): Offer
    {
        $application->loadMissing(['stage', 'candidate', 'vacancy.branch']);
        if ($application->stage->kind !== StageKind::Hire || $application->stage->is_terminal) {
            throw RecruitingException::notInOfferStage();
        }
        $existing = $this->forApplication($application);
        if ($existing !== null) {
            throw RecruitingException::offerExists($existing->id);
        }
        $template = $this->documentTemplates->find($data['template_id']);
        if ($template === null || $template->category !== self::TEMPLATE_CATEGORY) {
            throw RecruitingException::templateNotOffer();
        }
        if ($template->archived) {
            throw DocumentException::templateArchived();
        }
        $today ??= Carbon::now();
        $start = $data['start_date'] === null ? null : Carbon::parse($data['start_date']);
        $name = $application->candidate->full_name;
        $content = TemplateFiller::fill($template->body, [
            DocumentVariable::FullName->value => $name,
            DocumentVariable::FirstName->value => (preg_split('/\s+/u', trim($name)) ?: [])[0] ?? null,
            DocumentVariable::Position->value => $data['position'],
            DocumentVariable::Branch->value => $application->vacancy->branch->name,
            DocumentVariable::Today->value => $today->format('d.m.Y'),
            DocumentVariable::Salary->value => $data['salary'],
            DocumentVariable::StartDate->value => $start?->format('d.m.Y'),
            DocumentVariable::Conditions->value => $data['conditions'],
        ])['text'];

        $offer = $this->applications->createOffer([
            'application_id' => $application->id,
            'template_id' => $template->id,
            'position' => $data['position'],
            'salary' => $data['salary'],
            'start_date' => $start,
            'conditions' => $data['conditions'],
            'content_md' => $content,
            'status' => OfferStatus::Draft->value,
            'created_by' => $actor->id,
        ]);
        $this->log->info('recruiting.offer_created', ['id' => $offer->id, 'application' => $application->id, 'by' => $actor->id]);

        return $offer;
    }

    /**
     * E-mails the offer text to the candidate (a touchpoint on the application) and marks it sent.
     *
     * @throws RecruitingException offer_status
     */
    public function send(User $actor, Offer $offer): Offer
    {
        if ($offer->status !== OfferStatus::Draft) {
            throw RecruitingException::offerStatus();
        }
        $application = $offer->application;
        $this->messages->send($actor, $application->candidate, Channel::Email, $offer->content_md, $application->id, 'Оффер: '.$offer->position);
        $this->applications->updateOffer($offer, ['status' => OfferStatus::Sent->value, 'sent_at' => Carbon::now()]);
        $this->log->info('recruiting.offer_sent', ['id' => $offer->id, 'by' => $actor->id]);

        return $offer;
    }

    /** @throws RecruitingException offer_status */
    public function decide(User $actor, Offer $offer, OfferStatus $status): Offer
    {
        if ($offer->status !== OfferStatus::Sent || ! in_array($status, [OfferStatus::Accepted, OfferStatus::Declined], true)) {
            throw RecruitingException::offerStatus();
        }
        $this->applications->updateOffer($offer, ['status' => $status->value, 'decided_at' => Carbon::now()]);
        $this->log->info('recruiting.offer_decided', ['id' => $offer->id, 'status' => $status->value, 'by' => $actor->id]);

        return $offer;
    }
}
