<?php

declare(strict_types=1);

namespace Tests\Unit\Recruiting;

use App\Modules\Recruiting\Enums\CandidateSource;
use App\Modules\Recruiting\Enums\ClipperSite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClipperSiteTest extends TestCase
{
    /** @return iterable<string, array{ClipperSite, string, string|null}> */
    public static function urls(): iterable
    {
        yield 'linkedin www + slash + query' => [ClipperSite::Linkedin, 'https://www.linkedin.com/in/test-person/?trk=x#a', 'https://linkedin.com/in/test-person'];
        yield 'linkedin upper host' => [ClipperSite::Linkedin, 'https://WWW.LinkedIn.com/in/test-person', 'https://linkedin.com/in/test-person'];
        yield 'work.ua resume' => [ClipperSite::WorkUa, 'https://www.work.ua/resumes/123/', 'https://work.ua/resumes/123'];
        yield 'work.ua language prefix' => [ClipperSite::WorkUa, 'https://www.work.ua/ru/resumes/123/', 'https://work.ua/resumes/123'];
        yield 'djinni' => [ClipperSite::Djinni, 'https://djinni.co/q/abc/', 'https://djinni.co/q/abc'];
        yield 'dou' => [ClipperSite::Dou, 'https://dou.ua/users/test-person/', 'https://dou.ua/users/test-person'];
        yield 'robota.ua candidate' => [ClipperSite::RobotaUa, 'https://www.robota.ua/ru/candidates/123/?x=1', 'https://robota.ua/candidates/123'];
        yield 'robota.ua cv' => [ClipperSite::RobotaUa, 'https://robota.ua/ua/cv/abc', 'https://robota.ua/cv/abc'];
        yield 'http refused' => [ClipperSite::Dou, 'http://dou.ua/users/test-person/', null];
        yield 'other host' => [ClipperSite::Dou, 'https://dou.ua.example.com/users/x', null];
        yield 'subdomain refused' => [ClipperSite::Linkedin, 'https://ua.linkedin.com/in/x', null];
        yield 'credentials refused' => [ClipperSite::WorkUa, 'https://a:b@work.ua/resumes/1', null];
        yield 'port refused' => [ClipperSite::WorkUa, 'https://work.ua:8443/resumes/1', null];
        yield 'root path refused' => [ClipperSite::Djinni, 'https://djinni.co/', null];
        yield 'not a url' => [ClipperSite::Djinni, 'djinni', null];
    }

    #[DataProvider('urls')]
    public function test_normalize_url(ClipperSite $site, string $url, ?string $expected): void
    {
        $this->assertSame($expected, $site->normalizeUrl($url));
    }

    public function test_every_site_maps_to_a_candidate_source_and_label(): void
    {
        foreach (ClipperSite::cases() as $site) {
            $this->assertSame($site->value, $site->candidateSource()->value);
            $this->assertInstanceOf(CandidateSource::class, $site->candidateSource());
            $this->assertNotSame('', $site->label());
        }
    }
}
