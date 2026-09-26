<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use App\Modules\Documents\Enums\SignatureMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $document_id
 * @property int|null $signer_employee_id
 * @property int|null $signer_user_id
 * @property SignatureMethod $method
 * @property Carbon $signed_at
 * @property string|null $ip_hash
 * @property string|null $user_agent_hash
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Signature extends Model
{
    protected $fillable = ['document_id', 'signer_employee_id', 'signer_user_id', 'method', 'signed_at', 'ip_hash', 'user_agent_hash'];

    /** @var list<string> */
    protected $hidden = ['ip_hash', 'user_agent_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['method' => SignatureMethod::class, 'signed_at' => 'datetime'];
    }
}
