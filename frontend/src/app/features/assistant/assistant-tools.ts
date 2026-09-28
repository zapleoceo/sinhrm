import { HttpClient, HttpErrorResponse, HttpParams, HttpResponse } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Router } from '@angular/router';
import { Observable, firstValueFrom } from 'rxjs';
import { ClientCall, WriteMethod, WriteRequest } from './assistant.model';

/** Longest tool answer sent back to the model. */
export const TOOL_CONTENT_LIMIT = 12_000;
const TRUNCATED = '…[truncated]';

/** Mirror of the backend guard: API areas the assistant never touches from the browser. */
export const FORBIDDEN_PREFIXES: readonly string[] = [
  'ops',
  'mcp',
  'assistant',
  'auth/logout',
  'sanctum',
  'clipper',
  'public',
  'me/extension-token',
  'webhooks',
];
const PATH_PATTERN = /^[a-z0-9][a-z0-9\-_/.?=&%:,+ ]*$/i;
const WRITE_METHODS: readonly WriteMethod[] = ['POST', 'PUT', 'PATCH', 'DELETE'];

export type ToolOutcome = 'ok' | 'error' | 'declined';

export interface ToolRun {
  content: string;
  outcome: ToolOutcome;
  /** An api_write was confirmed and succeeded. */
  wrote: boolean;
}

/** Asks the user to confirm a write; resolves true for «Виконати». */
export type ConfirmWrite = (request: WriteRequest) => Promise<boolean>;

/**
 * Relative API path allowed for the assistant ("candidates/12?x=1"), or null.
 * An optional leading "/api/" is stripped; any other leading slash, "..", odd characters, "%" or a backslash outside
 * the query and the forbidden prefixes (exact segment or prefix + "/", case-insensitive, query ignored) are rejected.
 */
export function guardApiPath(raw: unknown): string | null {
  if (typeof raw !== 'string') {
    return null;
  }
  let path = raw.trim();
  if (/^\/api\//i.test(path)) {
    path = path.slice(5);
  }
  // "//" anywhere (not only leading) — "auth//logout" would slip past the multi-segment prefixes (mirrors ApiPath).
  if (!PATH_PATTERN.test(path) || path.includes('..') || path.includes('//') || decodedHasDots(path)) {
    return null;
  }
  const bare = path.split('?')[0].toLowerCase();
  // Percent-encoding is allowed only in the query part.
  if (bare.includes('%') || bare.includes('\\')) {
    return null;
  }
  const forbidden = FORBIDDEN_PREFIXES.some((p) => bare === p || bare.startsWith(`${p}/`));
  return forbidden ? null : path;
}

/** SPA path for open_page: starts with "/" but is not protocol-relative. */
export function guardPagePath(raw: unknown): string | null {
  if (typeof raw !== 'string') {
    return null;
  }
  const path = raw.trim();
  return path.startsWith('/') && !path.startsWith('//') && !path.startsWith('/\\') ? path : null;
}

/** Cuts a tool answer to TOOL_CONTENT_LIMIT characters. */
export function truncateContent(text: string, limit = TOOL_CONTENT_LIMIT): string {
  return text.length <= limit ? text : text.slice(0, limit) + TRUNCATED;
}

/** Scalar query params of api_get; anything else is dropped. */
export function toQueryParams(raw: unknown): Record<string, string | number | boolean> {
  const out: Record<string, string | number | boolean> = {};
  if (raw && typeof raw === 'object' && !Array.isArray(raw)) {
    for (const [k, v] of Object.entries(raw as Record<string, unknown>)) {
      if (typeof v === 'string' || typeof v === 'number' || typeof v === 'boolean') {
        out[k] = v;
      }
    }
  }
  return out;
}

/** Validated api_write arguments, or null when the call is malformed or forbidden. */
export function toWriteRequest(args: Record<string, unknown>): WriteRequest | null {
  const method = typeof args['method'] === 'string' ? args['method'].toUpperCase() : '';
  const path = guardApiPath(args['path']);
  if (!(WRITE_METHODS as readonly string[]).includes(method) || path === null) {
    return null;
  }
  const rawBody = args['body'];
  const body = rawBody && typeof rawBody === 'object' ? rawBody : null;
  const summary = typeof args['summary'] === 'string' && args['summary'].trim() ? args['summary'].trim() : `${method} /api/${path}`;
  return { method: method as WriteMethod, path, body, summary };
}

function decodedHasDots(path: string): boolean {
  try {
    return decodeURIComponent(path).includes('..');
  } catch {
    return true;
  }
}

function json(value: unknown): string {
  return truncateContent(JSON.stringify(value));
}

function errorMessage(e: HttpErrorResponse): string {
  const body: unknown = e.error;
  if (body && typeof body === 'object') {
    const message: unknown = (body as { message?: unknown }).message;
    if (typeof message === 'string' && message) {
      return message;
    }
  }
  return e.statusText || 'error';
}

/** Runs the tools the model asked the browser to run (api_get, api_write after confirmation, open_page). */
@Injectable({ providedIn: 'root' })
export class AssistantToolExecutor {
  private readonly http = inject(HttpClient);
  private readonly router = inject(Router);

  async run(call: ClientCall, confirm: ConfirmWrite): Promise<ToolRun> {
    switch (call.name) {
      case 'api_get':
        return this.get(call.arguments);
      case 'api_write':
        return this.write(call.arguments, confirm);
      case 'open_page':
        return this.open(call.arguments);
      default:
        return { content: json({ error: 'unknown_tool' }), outcome: 'error', wrote: false };
    }
  }

  private async get(args: Record<string, unknown>): Promise<ToolRun> {
    const path = guardApiPath(args['path']);
    if (path === null) {
      return this.forbidden();
    }
    const params = new HttpParams({ fromObject: toQueryParams(args['query']) });
    return this.send(() => this.http.get<unknown>(`/api/${path}`, { params, observe: 'response' }), false);
  }

  private async write(args: Record<string, unknown>, confirm: ConfirmWrite): Promise<ToolRun> {
    const request = toWriteRequest(args);
    if (request === null) {
      return this.forbidden();
    }
    if (!(await confirm(request))) {
      return { content: json({ declined: true, note: 'user declined' }), outcome: 'declined', wrote: false };
    }
    return this.send(
      () => this.http.request<unknown>(request.method, `/api/${request.path}`, { body: request.body ?? undefined, observe: 'response' }),
      true,
    );
  }

  private async open(args: Record<string, unknown>): Promise<ToolRun> {
    const path = guardPagePath(args['path']);
    if (path === null) {
      return this.forbidden();
    }
    await this.router.navigateByUrl(path);
    return { content: json({ opened: true }), outcome: 'ok', wrote: false };
  }

  private async send(request: () => Observable<HttpResponse<unknown>>, isWrite: boolean): Promise<ToolRun> {
    try {
      const res = await firstValueFrom(request());
      return { content: json({ status: res.status, data: res.body }), outcome: 'ok', wrote: isWrite };
    } catch (e: unknown) {
      if (e instanceof HttpErrorResponse) {
        return { content: json({ status: e.status, error: errorMessage(e) }), outcome: 'error', wrote: false };
      }
      throw e;
    }
  }

  private forbidden(): ToolRun {
    return { content: json({ error: 'forbidden_path' }), outcome: 'error', wrote: false };
  }
}
