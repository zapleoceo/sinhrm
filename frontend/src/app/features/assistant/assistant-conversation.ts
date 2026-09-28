import { HttpErrorResponse } from '@angular/common/http';
import { DOCUMENT } from '@angular/common';
import { Injectable, computed, effect, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { AuthService } from '../../core/auth/auth.service';
import {
  ASSISTANT_ERROR_CODES,
  AssistantMood,
  AssistantStatus,
  ChatMessage,
  ClientCall,
  TurnResult,
  UNAVAILABLE_REASONS,
  WriteRequest,
} from './assistant.model';
import { AssistantService } from './assistant.service';
import { AssistantToolExecutor } from './assistant-tools';

/** Round trips to the server per user message before «я заплутався». */
export const MAX_ROUNDS = 8;
/** Messages sent to the server and kept in sessionStorage. */
export const HISTORY_LIMIT = 40;
export const POLL_INTERVAL_MS = 2500;
export const POLL_TIMEOUT_MS = 60_000;
const STORAGE_PREFIX = 'sinhrm.assistant.history.';

export interface TranscriptItem {
  role: 'user' | 'assistant';
  text: string;
}

/** A mood change; seq makes a repeated mood (celebrate twice) a new event. */
export interface MoodEvent {
  mood: AssistantMood;
  seq: number;
}

/**
 * Last `limit` messages without cutting a tool exchange: the window starts at a user message
 * (or, when there is none, at least not with orphaned tool replies).
 */
export function trimHistory(history: readonly ChatMessage[], limit = HISTORY_LIMIT): ChatMessage[] {
  if (history.length <= limit) {
    return [...history];
  }
  let window = history.slice(-limit);
  const firstUser = window.findIndex((m) => m.role === 'user');
  if (firstUser > 0) {
    window = window.slice(firstUser);
  } else if (firstUser < 0) {
    while (window.length > 0 && window[0].role === 'tool') {
      window = window.slice(1);
    }
  }
  return window;
}

/** i18n key of a failure code of a turn ("ai_provider_http_500" → assistant.errors.ai_provider). */
export function turnErrorKey(code: string | null | undefined): string {
  const normalized = code?.startsWith('ai_provider') ? 'ai_provider' : code;
  return normalized && (ASSISTANT_ERROR_CODES as readonly string[]).includes(normalized)
    ? `assistant.errors.${normalized}`
    : 'assistant.errors.generic';
}

/** i18n key of a failed HTTP call to the assistant API. */
export function httpErrorKey(error: unknown): string {
  if (error instanceof HttpErrorResponse) {
    if (error.status === 429) {
      return 'assistant.errors.throttled';
    }
    const code: unknown = (error.error as { code?: unknown } | null)?.code;
    if (typeof code === 'string') {
      return turnErrorKey(code);
    }
  }
  return 'assistant.errors.generic';
}

/** i18n key explaining why the chat is unavailable. */
export function unavailableKey(reason: string | null): string {
  return reason && (UNAVAILABLE_REASONS as readonly string[]).includes(reason)
    ? `assistant.unavailable.${reason}`
    : 'assistant.unavailable.generic';
}

function isChatMessage(value: unknown): value is ChatMessage {
  if (!value || typeof value !== 'object') {
    return false;
  }
  const role: unknown = (value as { role?: unknown }).role;
  return role === 'user' || role === 'assistant' || role === 'tool';
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * The conversation loop with the server (docs/modules/assistant.md): user text → POST /turn → poll while pending →
 * run client tools (writes only after confirmation) → POST again … until a final answer or MAX_ROUNDS.
 */
@Injectable({ providedIn: 'root' })
export class AssistantConversation {
  private readonly api = inject(AssistantService);
  private readonly tools = inject(AssistantToolExecutor);
  private readonly router = inject(Router);
  private readonly document = inject(DOCUMENT);
  private readonly auth = inject(AuthService);
  private readonly userId = computed(() => this.auth.user()?.id ?? null);
  private readonly messages = signal<ChatMessage[]>([]);
  private readonly working = signal(false);
  private readonly pending = signal<WriteRequest | null>(null);
  private readonly error = signal<string | null>(null);
  private readonly moodState = signal<MoodEvent>({ mood: 'idle', seq: 0 });
  private readonly statusState = signal<AssistantStatus | null>(null);
  private resolveWrite: ((ok: boolean) => void) | null = null;

  readonly history = this.messages.asReadonly();
  readonly busy = this.working.asReadonly();
  /** Write waiting for «Виконати» / «Скасувати». */
  readonly pendingWrite = this.pending.asReadonly();
  readonly errorKey = this.error.asReadonly();
  readonly mood = this.moodState.asReadonly();
  readonly status = this.statusState.asReadonly();
  /** What the chat shows: user texts and assistant answers (tool traffic stays hidden). */
  readonly transcript = computed<TranscriptItem[]>(() =>
    this.messages().flatMap((m): TranscriptItem[] => {
      if (m.role === 'user') {
        return [{ role: 'user', text: m.content }];
      }
      if (m.role === 'assistant' && m.content && m.content.trim()) {
        return [{ role: 'assistant', text: m.content }];
      }
      return [];
    }),
  );

  constructor() {
    effect(() => this.messages.set(this.load(this.userId())));
  }

  /** GET /status; a failed call counts as "unavailable" with no reason. */
  async loadStatus(): Promise<AssistantStatus> {
    try {
      const status = await firstValueFrom(this.api.status());
      this.statusState.set(status);
      return status;
    } catch {
      const status: AssistantStatus = { available: false, reason: null, mcp_url: '' };
      this.statusState.set(status);
      return status;
    }
  }

  async send(text: string): Promise<void> {
    const content = text.trim();
    if (!content || this.working()) {
      return;
    }
    this.error.set(null);
    this.append({ role: 'user', content });
    this.working.set(true);
    this.setMood('think');
    try {
      for (let round = 0; round < MAX_ROUNDS; round++) {
        const result = await this.requestTurn();
        if (result.state !== 'done') {
          this.fail(turnErrorKey(result.state === 'pending' ? 'ai_timeout' : result.error));
          return;
        }
        if (result.assistant) {
          this.append(result.assistant);
        }
        const serverResults = result.server_results ?? [];
        this.append(...serverResults);
        const calls = result.client_calls ?? [];
        if (calls.length > 0) {
          await this.runCalls(calls);
          continue;
        }
        if (serverResults.length > 0) {
          continue;
        }
        this.setMood('talk');
        return;
      }
      this.fail('assistant.errors.lost');
    } catch (e: unknown) {
      this.fail(httpErrorKey(e));
    } finally {
      this.working.set(false);
      this.save();
    }
  }

  /** Answer of the confirmation card. */
  confirmWrite(ok: boolean): void {
    const resolve = this.resolveWrite;
    this.resolveWrite = null;
    this.pending.set(null);
    resolve?.(ok);
  }

  /** «Нова розмова». */
  reset(): void {
    if (this.working()) {
      return;
    }
    this.messages.set([]);
    this.error.set(null);
    this.setMood('idle');
    this.save();
  }

  private async runCalls(calls: ClientCall[]): Promise<void> {
    for (const call of calls) {
      const run = await this.tools.run(call, (request) => this.askConfirm(request));
      this.append({ role: 'tool', tool_call_id: call.id, content: run.content });
      if (run.wrote) {
        this.setMood('celebrate');
      } else if (call.name === 'api_write' && run.outcome === 'error') {
        this.setMood('shrug');
      }
    }
    this.setMood('think');
  }

  private askConfirm(request: WriteRequest): Promise<boolean> {
    this.setMood('point');
    this.pending.set(request);
    return new Promise<boolean>((resolve) => (this.resolveWrite = resolve));
  }

  private async requestTurn(): Promise<TurnResult> {
    let result = await firstValueFrom(
      this.api.turn({ messages: trimHistory(this.messages()), page: { path: this.router.url, title: this.document.title } }),
    );
    const deadline = Date.now() + POLL_TIMEOUT_MS;
    while (result.state === 'pending' && Date.now() < deadline) {
      await sleep(POLL_INTERVAL_MS);
      result = await firstValueFrom(this.api.poll(result.request_id));
    }
    return result;
  }

  private fail(key: string): void {
    this.error.set(key);
    this.setMood('shrug');
  }

  private append(...items: ChatMessage[]): void {
    if (items.length > 0) {
      this.messages.update((list) => [...list, ...items]);
    }
  }

  private setMood(mood: AssistantMood): void {
    this.moodState.update((m) => ({ mood, seq: m.seq + 1 }));
  }

  private load(userId: number | null): ChatMessage[] {
    if (userId === null) {
      return [];
    }
    try {
      const parsed: unknown = JSON.parse(globalThis.sessionStorage?.getItem(STORAGE_PREFIX + userId) ?? '[]');
      return Array.isArray(parsed) ? parsed.filter(isChatMessage) : [];
    } catch {
      return [];
    }
  }

  private save(): void {
    const userId = this.userId();
    if (userId === null) {
      return;
    }
    try {
      globalThis.sessionStorage?.setItem(STORAGE_PREFIX + userId, JSON.stringify(trimHistory(this.messages())));
    } catch {
      // storage unavailable — the history lives in memory only
    }
  }
}
