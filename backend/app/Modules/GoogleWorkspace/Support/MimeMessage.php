<?php

declare(strict_types=1);

namespace App\Modules\GoogleWorkspace\Support;

use App\Modules\GoogleWorkspace\DTO\OutgoingMail;
use App\Modules\GoogleWorkspace\Exceptions\GoogleException;

/**
 * RFC 2822 / MIME text of an outgoing mail for Gmail users.messages.send ("raw", base64url).
 * - From is left out: Gmail sets the connected account itself.
 * - Header values never contain CR/LF (no header injection); non-ASCII subject / name → RFC 2047 "=?UTF-8?B?…?=".
 * - Body: multipart/alternative, text/plain + text/html, both base64 (UTF-8). The HTML part is the ESCAPED text
 *   with line breaks — user input is never sent as HTML.
 */
final class MimeMessage
{
    public const int MAX_SUBJECT = 255;

    public const int MAX_TEXT = 20000;

    /** @throws GoogleException invalid_mail */
    public static function build(OutgoingMail $mail, string $boundary): string
    {
        $to = self::address($mail->to, $mail->toName);
        $subject = self::oneLine($mail->subject);
        if (mb_strlen($subject) > self::MAX_SUBJECT || trim($mail->text) === '' || mb_strlen($mail->text) > self::MAX_TEXT
            || preg_match('/^[A-Za-z0-9]{16,70}$/', $boundary) !== 1) {
            throw GoogleException::invalidMail();
        }
        $text = str_replace(["\r\n", "\r"], "\n", $mail->text);
        $html = '<div style="white-space:pre-wrap">'.nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8'), false).'</div>';

        $headers = [
            'To: '.$to,
            'Subject: '.self::encodeHeader($subject),
            'MIME-Version: 1.0',
        ];
        $replyTo = $mail->inReplyTo === null ? null : self::messageId($mail->inReplyTo);
        if ($replyTo !== null) {
            $headers[] = 'In-Reply-To: '.$replyTo;
            $headers[] = 'References: '.$replyTo;
        }
        $headers[] = 'Content-Type: multipart/alternative; boundary="'.$boundary.'"';

        return implode("\r\n", [
            ...$headers,
            '',
            '--'.$boundary,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            rtrim(chunk_split(base64_encode(str_replace("\n", "\r\n", $text)), 76, "\r\n")),
            '--'.$boundary,
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            rtrim(chunk_split(base64_encode($html), 76, "\r\n")),
            '--'.$boundary.'--',
            '',
        ]);
    }

    public static function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * RFC 2047 encoded-word(s) for non-ASCII text; ASCII stays as is. Words are split on character boundaries so a
     * multibyte letter is never cut in half (each encoded word ≤ 75 chars).
     */
    public static function encodeHeader(string $value): string
    {
        if (preg_match('/[^\x20-\x7E]/', $value) !== 1) {
            return $value;
        }
        $words = [];
        $chunk = '';
        foreach (mb_str_split($value) as $char) {
            if (strlen($chunk.$char) > 45) {
                $words[] = '=?UTF-8?B?'.base64_encode($chunk).'?=';
                $chunk = '';
            }
            $chunk .= $char;
        }
        $words[] = '=?UTF-8?B?'.base64_encode($chunk).'?=';

        return implode("\r\n ", $words);
    }

    /** "Name <a@b.c>" with a validated address; the name is encoded, quotes/brackets/backslash/CR/LF never pass. */
    private static function address(string $email, ?string $name): string
    {
        $email = trim($email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\s<>"]/', $email) === 1) {
            throw GoogleException::invalidMail();
        }
        $name = $name === null ? '' : trim((string) preg_replace('/["<>\x5C]/', '', self::oneLine($name)));
        if ($name === '') {
            return $email;
        }
        $encoded = self::encodeHeader($name);

        return ($encoded === $name ? '"'.$name.'"' : $encoded).' <'.$email.'>';
    }

    /** "<id@host>" only when it looks like a Message-ID; anything else is dropped (no threading, no injection). */
    private static function messageId(string $id): ?string
    {
        $id = trim($id);

        return preg_match('/^<[\x21-\x3B\x3D\x3F-\x7E]{1,250}@[\x21-\x3B\x3D\x3F-\x7E]{1,250}>$/', $id) === 1 ? $id : null;
    }

    private static function oneLine(string $value): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));
    }
}
