<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Enums;

/** Sites the browser extension (SinHRM Clipper) imports a single profile page from. */
enum ClipperSite: string
{
    case Linkedin = 'linkedin';
    case WorkUa = 'work_ua';
    case Djinni = 'djinni';
    case Dou = 'dou';

    /** @return list<string> exact hosts a profile_url of this site may have */
    public function hosts(): array
    {
        return match ($this) {
            self::Linkedin => ['linkedin.com', 'www.linkedin.com'],
            self::WorkUa => ['work.ua', 'www.work.ua'],
            self::Djinni => ['djinni.co', 'www.djinni.co'],
            self::Dou => ['dou.ua', 'www.dou.ua'],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Linkedin => 'LinkedIn',
            self::WorkUa => 'Work.ua',
            self::Djinni => 'Djinni',
            self::Dou => 'DOU',
        };
    }

    public function candidateSource(): CandidateSource
    {
        return match ($this) {
            self::Linkedin => CandidateSource::Linkedin,
            self::WorkUa => CandidateSource::WorkUa,
            self::Djinni => CandidateSource::Djinni,
            self::Dou => CandidateSource::Dou,
        };
    }

    /**
     * Canonical form used as the dedupe key: https, lowercase host without "www.", path without trailing slash
     * (Work.ua: without the /ru|/en language prefix), no query/fragment. Null when not an https URL of this site.
     */
    public function normalizeUrl(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }
        $host = strtolower($parts['host']);
        if (! in_array($host, $this->hosts(), true)) {
            return null;
        }
        $path = rtrim($parts['path'] ?? '', '/');
        if ($this === self::WorkUa) {
            // The same resume in another interface language: /ru/resumes/1 = /en/resumes/1 = /resumes/1.
            $path = (string) preg_replace('#^/(ru|en)(?=/)#', '', $path);
        }
        if ($path === '') {
            return null;
        }

        return 'https://'.preg_replace('/^www\./', '', $host).$path;
    }
}
