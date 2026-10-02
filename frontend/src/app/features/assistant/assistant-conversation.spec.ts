import { HttpErrorResponse } from '@angular/common/http';
import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { Observable, of, throwError } from 'rxjs';
import { AuthService } from '../../core/auth/auth.service';
import { AssistantConversation, MAX_ROUNDS, clearAssistantHistory, POLL_INTERVAL_MS, httpErrorKey, trimHistory, turnErrorKey, unavailableKey } from './assistant-conversation';
import { ChatMessage, TurnRequest, TurnResult } from './assistant.model';
import { AssistantService } from './assistant.service';
import { AssistantToolExecutor, ToolRun } from './assistant-tools';

type TurnFn = (req: TurnRequest) => Observable<TurnResult>;

function setup(turn: TurnFn, poll: (id: number) => Observable<TurnResult> = () => of({ state: 'failed', request_id: 0 }), run?: AssistantToolExecutor['run']) {
  sessionStorage.clear();
  const requests: TurnRequest[] = [];
  const api = {
    turn: vi.fn((r: TurnRequest) => {
      requests.push(structuredClone(r));
      return turn(r);
    }),
    poll: vi.fn(poll),
    status: vi.fn(() => of({ available: true, reason: null, mcp_url: 'https://x/mcp' })),
  };
  const tools = { run: vi.fn(run ?? ((): Promise<ToolRun> => Promise.resolve({ content: '{"status":200,"data":[]}', outcome: 'ok', wrote: false }))) };
  TestBed.configureTestingModule({
    providers: [
      provideRouter([]),
      { provide: AssistantService, useValue: api },
      { provide: AssistantToolExecutor, useValue: tools },
      { provide: AuthService, useValue: { user: signal({ id: 5 }) } },
    ],
  });
  const conv = TestBed.inject(AssistantConversation);
  TestBed.tick();
  return { conv, api, tools, requests };
}

const done = (assistant: TurnResult['assistant'], extra: Partial<TurnResult> = {}): TurnResult => ({ state: 'done', request_id: 1, assistant, ...extra });
const callMsg = (id: string, name: string): TurnResult['assistant'] => ({ role: 'assistant', content: null, tool_calls: [{ id, type: 'function', function: { name, arguments: '{}' } }] });

describe('trimHistory', () => {
  it('keeps short histories and starts long ones at a user message', () => {
    const h: ChatMessage[] = [
      { role: 'user', content: 'a' },
      { role: 'assistant', content: null, tool_calls: [] },
      { role: 'tool', tool_call_id: 't', content: '{}' },
      { role: 'assistant', content: 'x' },
      { role: 'user', content: 'b' },
      { role: 'assistant', content: 'y' },
    ];
    expect(trimHistory(h, 10)).toEqual(h);
    const t = trimHistory(h, 4);
    expect(t[0]).toEqual({ role: 'user', content: 'b' });
    const noUser: ChatMessage[] = [
      { role: 'assistant', content: null, tool_calls: [] },
      { role: 'tool', tool_call_id: '1', content: '{}' },
      { role: 'tool', tool_call_id: '2', content: '{}' },
      { role: 'assistant', content: 'z' },
    ];
    expect(trimHistory([{ role: 'user', content: 'q' }, ...noUser], 3)[0].role).not.toBe('tool');
  });

  it('maps error codes to i18n keys', () => {
    expect(turnErrorKey('ai_provider_http_500')).toBe('assistant.errors.ai_provider');
    expect(turnErrorKey('ai_budget_exceeded')).toBe('assistant.errors.ai_budget_exceeded');
    expect(turnErrorKey('weird')).toBe('assistant.errors.generic');
    expect(httpErrorKey(new HttpErrorResponse({ status: 429 }))).toBe('assistant.errors.throttled');
    expect(httpErrorKey(new HttpErrorResponse({ status: 503, error: { code: 'ai_disabled' } }))).toBe('assistant.errors.ai_disabled');
    expect(unavailableKey('ai_not_configured')).toBe('assistant.unavailable.ai_not_configured');
    expect(unavailableKey(null)).toBe('assistant.unavailable.generic');
  });
});

describe('AssistantConversation loop', () => {
  it('a plain answer: one POST, final text shown, mood think → talk, history persisted', async () => {
    const { conv, api, requests } = setup(() => of(done({ role: 'assistant', content: 'Привіт!' })));
    const moods: string[] = [];
    const send = conv.send('Хто на офері?');
    moods.push(conv.mood().mood);
    await send;
    moods.push(conv.mood().mood);
    expect(api.turn).toHaveBeenCalledTimes(1);
    expect(requests[0].messages).toEqual([{ role: 'user', content: 'Хто на офері?' }]);
    expect(requests[0].page.path).toBe('/');
    expect(conv.transcript()).toEqual([
      { role: 'user', text: 'Хто на офері?' },
      { role: 'assistant', text: 'Привіт!' },
    ]);
    expect(moods).toEqual(['think', 'talk']);
    expect(JSON.parse(sessionStorage.getItem('sinhrm.assistant.history.5') ?? '[]')).toHaveLength(2);
  });

  it('server-only tool round → appends assistant + server results and POSTs again', async () => {
    const answers: TurnResult[] = [
      done(callMsg('s1', 'search'), { server_results: [{ role: 'tool', tool_call_id: 's1', content: '{"n":3}' }] }),
      done({ role: 'assistant', content: 'Троє кандидатів.' }),
    ];
    const { conv, api, requests } = setup(() => of(answers.shift()!));
    await conv.send('Скільки?');
    expect(api.turn).toHaveBeenCalledTimes(2);
    expect(requests[1].messages.map((m) => m.role)).toEqual(['user', 'assistant', 'tool']);
    expect(conv.transcript().at(-1)?.text).toBe('Троє кандидатів.');
  });

  it('client api_write waits for the confirmation card, then continues', async () => {
    const answers: TurnResult[] = [
      done(callMsg('c1', 'api_write'), { client_calls: [{ id: 'c1', name: 'api_write', arguments: { method: 'POST', path: 'x', summary: 'Зробити' } }] }),
      done({ role: 'assistant', content: 'Готово!' }),
    ];
    const run = async (_call: unknown, confirm: (r: { method: 'POST'; path: string; body: null; summary: string }) => Promise<boolean>): Promise<ToolRun> => {
      const ok = await confirm({ method: 'POST', path: 'x', body: null, summary: 'Зробити' });
      return ok ? { content: '{"status":201}', outcome: 'ok', wrote: true } : { content: '{"declined":true}', outcome: 'declined', wrote: false };
    };
    const { conv, api, requests } = setup(() => of(answers.shift()!), undefined, run as AssistantToolExecutor['run']);
    const sending = conv.send('Зроби');
    await new Promise((r) => setTimeout(r));
    expect(conv.pendingWrite()?.summary).toBe('Зробити');
    expect(conv.mood().mood).toBe('point');
    expect(api.turn).toHaveBeenCalledTimes(1);
    conv.confirmWrite(true);
    await sending;
    expect(conv.pendingWrite()).toBeNull();
    expect(api.turn).toHaveBeenCalledTimes(2);
    expect(requests[1].messages.at(-1)).toEqual({ role: 'tool', tool_call_id: 'c1', content: '{"status":201}' });
    expect(conv.transcript().at(-1)?.text).toBe('Готово!');
  });

  it('declining sends the declined tool result back', async () => {
    const answers: TurnResult[] = [
      done(callMsg('c1', 'api_write'), { client_calls: [{ id: 'c1', name: 'api_write', arguments: {} }] }),
      done({ role: 'assistant', content: 'Гаразд, не робитиму.' }),
    ];
    const run = async (_c: unknown, confirm: (r: never) => Promise<boolean>): Promise<ToolRun> =>
      (await confirm({} as never)) ? { content: 'ok', outcome: 'ok', wrote: true } : { content: '{"declined":true,"note":"user declined"}', outcome: 'declined', wrote: false };
    const { conv, requests } = setup(() => of(answers.shift()!), undefined, run as AssistantToolExecutor['run']);
    const sending = conv.send('Зроби');
    await new Promise((r) => setTimeout(r));
    conv.confirmWrite(false);
    await sending;
    expect(requests[1].messages.at(-1)).toEqual({ role: 'tool', tool_call_id: 'c1', content: '{"declined":true,"note":"user declined"}' });
  });

  it('pending → polls every 2.5 s until done', async () => {
    vi.useFakeTimers();
    try {
      const polls: TurnResult[] = [{ state: 'pending', request_id: 7 }, done({ role: 'assistant', content: 'Є!' })];
      const { conv, api } = setup(
        () => of({ state: 'pending', request_id: 7 }),
        () => of(polls.shift()!),
      );
      const sending = conv.send('Довге питання');
      await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS);
      expect(api.poll).toHaveBeenCalledTimes(1);
      expect(api.poll).toHaveBeenCalledWith(7);
      await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS);
      await sending;
      expect(api.poll).toHaveBeenCalledTimes(2);
      expect(conv.transcript().at(-1)?.text).toBe('Є!');
    } finally {
      vi.useRealTimers();
    }
  });

  it('gives up after MAX_ROUNDS round trips', async () => {
    const { conv, api } = setup(() => of(done(callMsg('s', 'x'), { server_results: [{ role: 'tool', tool_call_id: 's', content: '{}' }] })));
    await conv.send('Цикл');
    expect(api.turn).toHaveBeenCalledTimes(MAX_ROUNDS);
    expect(conv.errorKey()).toBe('assistant.errors.lost');
    expect(conv.mood().mood).toBe('shrug');
  });

  it('failed turn and 429 show localized errors', async () => {
    const failed = setup(() => of({ state: 'failed', request_id: 1, error: 'ai_budget_exceeded' }));
    await failed.conv.send('?');
    expect(failed.conv.errorKey()).toBe('assistant.errors.ai_budget_exceeded');
    TestBed.resetTestingModule();
    const limited = setup(() => throwError(() => new HttpErrorResponse({ status: 429 })));
    await limited.conv.send('?');
    expect(limited.conv.errorKey()).toBe('assistant.errors.throttled');
    expect(limited.conv.busy()).toBe(false);
  });

  it('«Нова розмова» clears history and storage', async () => {
    const { conv } = setup(() => of(done({ role: 'assistant', content: 'ok' })));
    await conv.send('hi');
    conv.reset();
    expect(conv.history()).toEqual([]);
    expect(sessionStorage.getItem('sinhrm.assistant.history.5')).toBe('[]');
  });
});

describe('assistant history and the signed-in user', () => {
  function withUser(initial: { id: number } | null) {
    sessionStorage.clear();
    const user = signal<{ id: number } | null>(initial);
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        { provide: AssistantService, useValue: { turn: vi.fn(() => of(done({ role: 'assistant', content: 'ok' }))), poll: vi.fn(), status: vi.fn() } },
        { provide: AssistantToolExecutor, useValue: { run: vi.fn() } },
        { provide: AuthService, useValue: { user } },
      ],
    });
    const conv = TestBed.inject(AssistantConversation);
    TestBed.tick();
    return { conv, user };
  }

  it('logout removes the saved chat (it holds tool answers) from sessionStorage and from memory', async () => {
    const { conv, user } = withUser({ id: 5 });
    await conv.send('hi');
    expect(sessionStorage.getItem('sinhrm.assistant.history.5')).not.toBeNull();
    user.set(null);
    TestBed.tick();
    expect(sessionStorage.getItem('sinhrm.assistant.history.5')).toBeNull();
    expect(conv.history()).toEqual([]);
  });

  it('another user in the same tab does not see, and drops, the previous chat', async () => {
    const { conv, user } = withUser({ id: 5 });
    await conv.send('hi');
    user.set({ id: 6 });
    TestBed.tick();
    expect(sessionStorage.getItem('sinhrm.assistant.history.5')).toBeNull();
    expect(conv.history()).toEqual([]);
  });

  it('a page reload (session not known yet, then the same user) keeps the chat', () => {
    sessionStorage.clear();
    sessionStorage.setItem('sinhrm.assistant.history.5', JSON.stringify([{ role: 'user', content: 'hi' }]));
    const user = signal<{ id: number } | null>(null);
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        { provide: AssistantService, useValue: {} },
        { provide: AssistantToolExecutor, useValue: {} },
        { provide: AuthService, useValue: { user } },
      ],
    });
    const conv = TestBed.inject(AssistantConversation);
    TestBed.tick();
    user.set({ id: 5 });
    TestBed.tick();
    expect(conv.history()).toEqual([{ role: 'user', content: 'hi' }]);
  });

  it('clearAssistantHistory keeps one user or removes all, leaving other keys alone', () => {
    sessionStorage.clear();
    sessionStorage.setItem('sinhrm.assistant.history.5', '[]');
    sessionStorage.setItem('sinhrm.assistant.history.6', '[]');
    sessionStorage.setItem('other', 'x');
    clearAssistantHistory(6);
    expect(sessionStorage.getItem('sinhrm.assistant.history.5')).toBeNull();
    expect(sessionStorage.getItem('sinhrm.assistant.history.6')).toBe('[]');
    clearAssistantHistory();
    expect(sessionStorage.getItem('sinhrm.assistant.history.6')).toBeNull();
    expect(sessionStorage.getItem('other')).toBe('x');
  });
});
