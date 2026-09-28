/** Contract of /api/assistant (OpenAI chat format; the client owns the history, the server is stateless). */

export interface ToolCall {
  id: string;
  type: 'function';
  function: { name: string; arguments: string };
}

export interface UserMessage {
  role: 'user';
  content: string;
}

export interface AssistantMessage {
  role: 'assistant';
  content: string | null;
  tool_calls?: ToolCall[];
}

export interface ToolMessage {
  role: 'tool';
  tool_call_id: string;
  content: string;
}

export type ChatMessage = UserMessage | AssistantMessage | ToolMessage;

export type ClientToolName = 'api_get' | 'api_write' | 'open_page';

export interface ClientCall {
  id: string;
  name: ClientToolName;
  arguments: Record<string, unknown>;
}

export type TurnState = 'done' | 'pending' | 'failed';

export interface TurnResult {
  state: TurnState;
  request_id: number;
  assistant?: AssistantMessage;
  server_results?: ToolMessage[];
  client_calls?: ClientCall[];
  error?: string;
}

export interface TurnPage {
  path: string;
  title: string;
}

export interface TurnRequest {
  messages: ChatMessage[];
  page: TurnPage;
}

export type AssistantUnavailableReason = 'ai_disabled' | 'ai_not_configured' | 'ai_purpose_disabled';

export interface AssistantStatus {
  available: boolean;
  reason: string | null;
  mcp_url: string;
}

export interface McpTokenInfo {
  active: boolean;
  created_at: string | null;
  last_used_at: string | null;
  expires_at: string | null;
}

/** POST /mcp-token answer: the token is shown once. */
export interface McpTokenCreated extends McpTokenInfo {
  token: string;
}

/** POST /transcribe and GET /transcriptions/{id} answer. */
export interface TranscriptionResult {
  state: TurnState;
  request_id: number;
  text?: string;
  error?: string;
}

/** GET /quips answer: short AI one-liners for a situation ([] with source 'none' when AI is off or failed). */
export interface QuipsResult {
  jokes: string[];
  source: 'ai' | 'none';
}

export type WriteMethod = 'POST' | 'PUT' | 'PATCH' | 'DELETE';

/** A write the model proposed; runs only after the user presses «Виконати». */
export interface WriteRequest {
  method: WriteMethod;
  path: string;
  body: object | null;
  summary: string;
}

/** Mascot reactions driven by the conversation. */
export type AssistantMood = 'idle' | 'think' | 'talk' | 'celebrate' | 'shrug' | 'point' | 'listen';

/** Known failure codes → assistant.errors.<code>. */
export const ASSISTANT_ERROR_CODES = [
  'ai_disabled',
  'ai_not_configured',
  'ai_purpose_disabled',
  'ai_budget_exceeded',
  'ai_timeout',
  'ai_provider',
  'ai_invalid_output',
] as const;

export const UNAVAILABLE_REASONS: readonly AssistantUnavailableReason[] = ['ai_disabled', 'ai_not_configured', 'ai_purpose_disabled'];
