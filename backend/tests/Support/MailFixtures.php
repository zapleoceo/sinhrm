<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A fake Gmail box for mail agent tests. Every message here is INVENTED (no real job-board mail is copied):
 * structure = what a "new application" notification typically contains.
 */
trait MailFixtures
{
    /** @var array<string, array<string, mixed>> gmail id → messages.get answer */
    protected array $mailbox = [];

    /** @var list<string> q parameters of every messages.list call */
    protected array $listQueries = [];

    /** @param  list<string>  $labels */
    protected function addMail(string $id, string $from, string $subject, string $text, int $minutesAgo = 120, array $labels = ['INBOX'], ?string $html = null): void
    {
        $parts = [[
            'mimeType' => 'text/plain',
            'headers' => [['name' => 'Content-Type', 'value' => 'text/plain; charset="UTF-8"']],
            'body' => ['data' => self::b64url($text)],
        ]];
        if ($html !== null) {
            $parts = [['mimeType' => 'text/html', 'body' => ['data' => self::b64url($html)]]];
        }
        $this->mailbox[$id] = [
            'id' => $id,
            'threadId' => 't-'.$id,
            'labelIds' => $labels,
            'internalDate' => (string) ((time() - $minutesAgo * 60) * 1000),
            'payload' => [
                'mimeType' => 'multipart/alternative',
                'headers' => [['name' => 'From', 'value' => $from], ['name' => 'Subject', 'value' => $subject]],
                'parts' => $parts,
            ],
        ];
    }

    /** Answers users.messages.list / get from $mailbox; anything else is a stray request (fails the test). */
    protected function fakeGmail(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);
            if (! str_starts_with($url, 'https://gmail.googleapis.com/gmail/v1/users/me/messages')) {
                return null;
            }
            if ($path === '/gmail/v1/users/me/messages') {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $this->listQueries[] = (string) ($query['q'] ?? '');
                $ids = array_reverse(array_keys($this->mailbox)); // newest first, like Gmail

                return Http::response(['messages' => array_map(static fn (string $id): array => ['id' => $id, 'threadId' => 't-'.$id], $ids)]);
            }
            $id = basename($path);

            return isset($this->mailbox[$id]) ? Http::response($this->mailbox[$id]) : Http::response(['error' => 'not found'], 404);
        });
    }

    protected function jobBoardMail(string $id, string $vacancy, string $name, string $phone, string $email, int $minutesAgo = 120): void
    {
        $this->addMail(
            $id,
            'Jobs Board <notify@jobs.example.test>',
            'Новий відгук на вакансію «'.$vacancy.'»',
            "Доброго дня!\nНа вашу вакансію надійшов новий відгук.\n\nІм'я: {$name}\nТелефон: {$phone}\nE-mail: {$email}\n"
                ."Резюме: https://jobs.example.test/resumes/000111\n\nЛист надіслано автоматично, не відповідайте на нього.",
            $minutesAgo,
        );
    }

    private static function b64url(string $text): string
    {
        return rtrim(strtr(base64_encode($text), '+/', '-_'), '=');
    }
}
