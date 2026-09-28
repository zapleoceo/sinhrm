<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Http\Requests;

use App\Modules\Ai\DTO\AiAudio;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/** POST /api/assistant/transcribe — one voice recording from the chat (≤ 4 MB: Vercel's request body limit is 4.5 MB). */
final class TranscribeRequest extends FormRequest
{
    public const int MAX_KB = 4096;

    /** Browsers label MediaRecorder output differently (Chrome: webm, Safari: mp4/m4a, Firefox: ogg). */
    public const array MIME_TYPES = [
        'audio/webm', 'video/webm', 'audio/ogg', 'application/ogg', 'audio/mp4', 'video/mp4', 'audio/x-m4a', 'audio/m4a',
        'audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/aac',
    ];

    private const array EXTENSIONS = ['audio/mp4' => 'm4a', 'video/mp4' => 'm4a', 'audio/x-m4a' => 'm4a', 'audio/m4a' => 'm4a',
        'audio/ogg' => 'ogg', 'application/ogg' => 'ogg', 'audio/mpeg' => 'mp3', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav',
        'audio/aac' => 'aac'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'audio' => ['required', 'file', 'max:'.self::MAX_KB, 'mimetypes:'.implode(',', self::MIME_TYPES)],
        ];
    }

    /** The recording under a neutral name (the client's file name is not trusted or forwarded). */
    public function audio(): AiAudio
    {
        $file = $this->file('audio');
        assert($file instanceof UploadedFile);

        return new AiAudio((string) $file->get(), 'voice.'.(self::EXTENSIONS[(string) $file->getMimeType()] ?? 'webm'));
    }
}
