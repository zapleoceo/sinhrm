/** Mirrors backend App\Modules\Channels\Enums\ChannelMode: off → nothing, demo → recorded without the provider, live → real calls. */
export type ChannelMode = 'off' | 'demo' | 'live';

/** Mirrors backend App\Modules\Channels\Enums\WebhookAuth. */
export type WebhookAuth = 'header_secret' | 'hmac' | 'query_token';

/** Timeline channels that can be sent from the candidate card (backend SendMessageRequest::CHANNELS). */
export type SendChannel = 'telegram' | 'whatsapp' | 'viber';
export const SEND_CHANNELS: readonly string[] = ['telegram', 'whatsapp', 'viber'];

/** Item of GET /api/channels (any active user): what the card can do now. */
export interface ChannelAvailability {
  key: string;
  channel: string;
  mode: ChannelMode;
}

/** Item of GET /api/channels/admin (superadmin): webhook info for the Integrations page. */
export interface ChannelInfo {
  key: string;
  channel: string;
  mode: ChannelMode;
  webhook_url: string;
  auth: WebhookAuth;
  can_register: boolean;
  can_send: boolean;
  can_call: boolean;
  handshake: boolean;
}

/** Body of POST /api/candidates/{id}/messages. */
export interface SendMessage {
  channel: SendChannel;
  text: string;
  application_id?: number;
}

/** Body of POST /api/channels/{key}/simulate. */
export interface SimulateEvent {
  candidate_id?: number;
  contact?: string;
  text?: string;
}

export interface SimulateResult {
  events: number;
  created: number;
}

/** Business error codes of the Channels API ({code}); anything else → "generic". */
export const CHANNEL_ERROR_CODES = [
  'channel_not_connected',
  'no_conversation',
  'template_required',
  'invalid_recipient',
  'send_failed',
  'telephony_not_connected',
  'click_to_call_unsupported',
  'no_phone',
  'not_demo',
  'channel_off',
  'unsupported',
  'application_mismatch',
] as const;
