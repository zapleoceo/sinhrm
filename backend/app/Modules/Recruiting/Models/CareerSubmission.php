<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One application sent from the public career page: message, consent to personal data processing
 * (Law of Ukraine 2297-VI), hashed client IP and the CV (base64, same limits as documents files).
 *
 * @property int $id
 * @property int $vacancy_id
 * @property int $candidate_id
 * @property int|null $application_id
 * @property string|null $message
 * @property Carbon $consent_at
 * @property string $ip_hash
 * @property string|null $cv_filename
 * @property string|null $cv_mime
 * @property int|null $cv_size
 * @property string|null $cv_sha256
 * @property string|null $cv_content
 */
final class CareerSubmission extends Model
{
    protected $fillable = [
        'vacancy_id', 'candidate_id', 'application_id', 'message', 'consent_at', 'ip_hash',
        'cv_filename', 'cv_mime', 'cv_size', 'cv_sha256', 'cv_content',
    ];

    /** @var list<string> */
    protected $hidden = ['cv_content', 'ip_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['consent_at' => 'datetime'];
    }
}
