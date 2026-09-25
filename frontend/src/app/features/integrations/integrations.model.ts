/** Mirrors backend App\Modules\Integrations\Enums\IntegrationStatus. */
export type IntegrationStatus = 'off' | 'demo' | 'connected' | 'error';
/** Statuses a superadmin can set by hand (connected/error come only from a check). */
export type ManualStatus = 'off' | 'demo';
export const MANUAL_STATUSES: readonly ManualStatus[] = ['off', 'demo'];

/** Mirrors backend App\Modules\Integrations\Enums\IntegrationGroup; also the display order. */
export type IntegrationGroup = 'ai' | 'google' | 'messengers' | 'telephony' | 'sources';
export const INTEGRATION_GROUPS: readonly IntegrationGroup[] = ['ai', 'google', 'messengers', 'telephony', 'sources'];

/** Mirrors backend App\Modules\Integrations\Enums\FieldType. */
export type FieldType = 'text' | 'secret' | 'url' | 'select';

/** What the API tells about a stored secret. The value itself is never sent to the browser. */
export interface SecretMeta {
  is_set: boolean;
  updated_at: string | null;
  masked: string | null;
}

/** One form field (backend FieldSpec). Secret fields carry `secret`, the others carry `value`. */
export interface IntegrationField {
  name: string;
  type: FieldType;
  required: boolean;
  options: string[];
  default: string | null;
  value?: string | null;
  secret?: SecretMeta;
}

/** Item of GET /api/integrations (backend IntegrationResource). */
export interface Integration {
  key: string;
  group: IntegrationGroup;
  status: IntegrationStatus;
  supports_check: boolean;
  last_checked_at: string | null;
  last_error: string | null;
  updated_at: string | null;
  fields: IntegrationField[];
}

export interface AiPolicy {
  enabled: boolean;
}

export interface IntegrationsList {
  data: Integration[];
  ai_policy: AiPolicy;
}

/** Body of PUT /api/integrations/{key}. secrets: value = set, null = delete, absent = unchanged. */
export interface UpdateIntegration {
  settings?: Record<string, string>;
  secrets?: Record<string, string | null>;
}

export interface IntegrationLog {
  id: number;
  level: 'info' | 'warning' | 'error';
  message: string;
  context: Record<string, unknown>;
  created_at: string | null;
}

/** Business error codes of the integrations API ({code}); anything else → "generic". */
export const INTEGRATION_ERROR_CODES = ['check_not_supported'] as const;

/** Codes a check stores in last_error (prefix before ":"), translated on the page. */
export const CHECK_RESULT_CODES = [
  'connection_failed',
  'unauthorized',
  'missing_secret',
  'not_verified',
  'invalid_url',
  'http',
  'invalid_token',
  'blocked_host',
  'blocked_port',
  'unresolved_host',
] as const;
