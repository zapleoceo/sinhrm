<?php

declare(strict_types=1);

namespace App\Modules\Channels\Http\Resources;

use App\Modules\Channels\DTO\ChannelInfo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property ChannelInfo $resource */
final class ChannelInfoResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $info = $this->resource;

        return [
            'key' => $info->key,
            'channel' => $info->channel,
            'mode' => $info->mode->value,
            'webhook_url' => $info->webhookUrl,
            'auth' => $info->auth->value,
            'can_register' => $info->canRegister,
            'can_send' => $info->canSend,
            'can_call' => $info->canCall,
            'handshake' => $info->handshake,
        ];
    }
}
