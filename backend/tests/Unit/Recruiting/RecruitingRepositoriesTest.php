<?php

declare(strict_types=1);

namespace Tests\Unit\Recruiting;

use App\Modules\Documents\Contracts\DocumentTemplateRepository;
use App\Modules\Documents\Models\DocumentTemplate;
use App\Modules\Recruiting\Contracts\ApplicationRepository;
use App\Modules\Recruiting\Contracts\VacancyRepository;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\Offer;
use App\Modules\Recruiting\Services\CareerSiteService;
use App\Modules\Recruiting\Services\OfferService;
use Illuminate\Database\Eloquent\Collection;
use Mockery\MockInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/** Offers and the career page read and write through repositories (Recruiting's own and the Documents contract), no DB. */
final class RecruitingRepositoriesTest extends TestCase
{
    public function test_offer_templates_come_from_the_documents_contract(): void
    {
        /** @var DocumentTemplateRepository&MockInterface $templates */
        $templates = $this->mock(DocumentTemplateRepository::class);
        $templates->expects('activeOfCategory')->with(OfferService::TEMPLATE_CATEGORY)
            ->andReturn(new Collection([$this->template(4, 'Base'), $this->template(2, 'Senior')]));

        $this->assertSame([['id' => 4, 'name' => 'Base'], ['id' => 2, 'name' => 'Senior']], $this->app->make(OfferService::class)->templates());
    }

    public function test_the_offer_of_an_application_is_looked_up_in_the_application_repository(): void
    {
        $offer = new Offer;
        /** @var ApplicationRepository&MockInterface $applications */
        $applications = $this->mock(ApplicationRepository::class);
        $applications->expects('offerFor')->with(15)->andReturn($offer);
        $application = new Application;
        $application->id = 15;

        $this->assertSame($offer, $this->app->make(OfferService::class)->forApplication($application));
    }

    public function test_an_unknown_career_slug_is_404(): void
    {
        /** @var VacancyRepository&MockInterface $vacancies */
        $vacancies = $this->mock(VacancyRepository::class);
        $vacancies->expects('findPublishedBySlug')->with('nope-1')->andReturnNull();

        $this->expectException(NotFoundHttpException::class);
        $this->app->make(CareerSiteService::class)->findPublished('nope-1');
    }

    private function template(int $id, string $name): DocumentTemplate
    {
        $template = new DocumentTemplate(['name' => $name]);
        $template->id = $id;

        return $template;
    }
}
