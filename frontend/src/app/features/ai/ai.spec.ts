import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { RecruitingService } from '../recruiting/recruiting.service';
import { Screening } from '../recruiting/recruiting.model';
import {
  AI_PROMPT_PROBLEMS,
  AiStats,
  AiStatus,
  AiTestResult,
  AiTrialResult,
  errorsTooltip,
  sparklinePath,
  usagePercent,
} from './ai.model';
import { AiService, aiCodeKey, aiErrorKey, promptProblemKeys } from './ai.service';

const STATUS: AiStatus = {
  provider: 'ai_broker',
  capability: 'chat:sales',
  capabilities: {
    script_evaluation: 'chat:sales',
    mail_classification: 'chat:fast',
    candidate_screening: 'chat:sales',
  },
  model: null,
  configured: true,
  available: true,
  purposes: {
    script_evaluation: null,
    mail_classification: null,
    candidate_screening: 'ai_purpose_disabled',
  },
  auto_screening: false,
  usage: { requests: 50, cost_usd: 0.5, tokens_in: 10000, tokens_out: 2000, tokens_cached: 6000 },
  limits: { requests: 200, cost_usd: 2 },
};

const SCREENING: Screening = {
  id: 3,
  application_id: 7,
  vacancy: { id: 2, title: 'Synthetic vacancy' },
  status: 'done',
  trigger: 'manual',
  score: 82,
  verdict: 'fit',
  summary: 'Досвід збігається',
  strengths: ['a'],
  gaps: [],
  questions: [],
  error: null,
  prompt_version: 'screening.v4',
  advisory: true,
  created_at: null,
  completed_at: null,
};

describe('ai helpers', () => {
  it('maps AI error codes, including provider codes with details', () => {
    expect(aiCodeKey('ai_budget_exceeded')).toBe('ai.errors.ai_budget_exceeded');
    expect(aiCodeKey('ai_provider_http_401')).toBe('ai.errors.ai_provider');
    expect(aiCodeKey('something_else')).toBeNull();
    expect(aiCodeKey(null)).toBeNull();
    expect(
      aiErrorKey(new HttpErrorResponse({ status: 429, error: { code: 'ai_budget_exceeded' } })),
    ).toBe('ai.errors.ai_budget_exceeded');
    expect(aiErrorKey(new HttpErrorResponse({ status: 429 }))).toBe('ai.errors.throttled');
    expect(aiErrorKey(new HttpErrorResponse({ status: 500 }))).toBe('ai.errors.generic');
  });

  it('computes usage bars', () => {
    expect(usagePercent(50, 200)).toBe(25);
    expect(usagePercent(3, 2)).toBe(100);
    expect(usagePercent(1, 0)).toBe(0);
  });
});

describe('AiService and screening API', () => {
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('reads the status and runs the test prompt', () => {
    const api = TestBed.inject(AiService);
    let status: AiStatus | undefined;
    let result: AiTestResult | undefined;
    api.status().subscribe((s) => (status = s));
    http.expectOne({ method: 'GET', url: '/api/ai/status' }).flush({ data: STATUS });
    api.test().subscribe((r) => (result = r));
    http.expectOne({ method: 'POST', url: '/api/ai/test' }).flush({
      data: {
        status: 'done',
        error: null,
        reply: 'готово',
        request_id: 1,
        model: 'm',
        tokens_in: 1,
        tokens_out: 1,
        tokens_cached: 0,
        cost_usd: 0,
      },
    });
    expect(status?.capabilities.mail_classification).toBe('chat:fast');
    expect(result?.reply).toBe('готово');
  });

  it('lists and starts screenings', () => {
    const api = TestBed.inject(RecruitingService);
    let list: Screening[] = [];
    let started: Screening | undefined;
    api.screenings(5).subscribe((l) => (list = l));
    http
      .expectOne({ method: 'GET', url: '/api/candidates/5/screenings' })
      .flush({ data: [SCREENING] });
    api.screen(7).subscribe((s) => (started = s));
    http
      .expectOne({ method: 'POST', url: '/api/applications/7/screening' })
      .flush({ data: { ...SCREENING, status: 'pending' } });
    expect(list[0].verdict).toBe('fit');
    expect(started?.status).toBe('pending');
  });
});

describe('ai stats and prompt editor helpers', () => {
  it('draws a sparkline only for two or more points', () => {
    expect(sparklinePath([])).toBe('');
    expect(sparklinePath([3])).toBe('');
    expect(sparklinePath([0, 2], 60, 16)).toBe('M0.0,15.0 L60.0,1.0');
  });

  it('formats errors for the tooltip', () => {
    expect(errorsTooltip({ ai_timeout: 2, ai_invalid_output: 1 })).toBe(
      'ai_timeout: 2, ai_invalid_output: 1',
    );
    expect(errorsTooltip({})).toBe('');
  });

  it('maps prompt validation codes to i18n keys', () => {
    const e = new HttpErrorResponse({
      status: 422,
      error: { errors: { body: ['missing_role', 'volatile_data'] } },
    });
    expect(promptProblemKeys(e)).toEqual([
      'ai.prompt.problems.missing_role',
      'ai.prompt.problems.volatile_data',
    ]);
    expect(promptProblemKeys(new HttpErrorResponse({ status: 500 }))).toEqual([]);
    expect(AI_PROMPT_PROBLEMS).toContain('output_not_editable');
  });
});

describe('AiService stats and prompt API', () => {
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('reads stats for a period', () => {
    const api = TestBed.inject(AiService);
    let stats: AiStats | undefined;
    api.stats('7d').subscribe((s) => (stats = s));
    http.expectOne({ method: 'GET', url: '/api/ai/stats?period=7d' }).flush({
      data: {
        period: '7d',
        since: '2026-10-14T00:00:00+00:00',
        bucket: 'day',
        purposes: { candidate_screening: null },
      },
    });
    expect(stats?.bucket).toBe('day');
  });

  it('calls every prompt editor endpoint', () => {
    const api = TestBed.inject(AiService);
    const info = { purpose: 'candidate_screening', version: 'screening.v4' };
    let trial: AiTrialResult | undefined;
    api.prompt('candidate_screening').subscribe();
    http
      .expectOne({ method: 'GET', url: '/api/ai/prompts/candidate_screening' })
      .flush({ data: info });
    api.savePrompt('candidate_screening', 'ROLE: x').subscribe();
    const save = http.expectOne({ method: 'POST', url: '/api/ai/prompts/candidate_screening' });
    expect(save.request.body).toEqual({ body: 'ROLE: x' });
    save.flush({ data: info });
    api.activatePrompt('candidate_screening', 4).subscribe();
    http
      .expectOne({ method: 'POST', url: '/api/ai/prompts/candidate_screening/versions/4/activate' })
      .flush({ data: info });
    api.restoreBuiltinPrompt('candidate_screening').subscribe();
    http
      .expectOne({ method: 'POST', url: '/api/ai/prompts/candidate_screening/builtin' })
      .flush({ data: info });
    api.setCapability('candidate_screening', 'chat:smart').subscribe();
    const cap = http.expectOne({
      method: 'PUT',
      url: '/api/ai/prompts/candidate_screening/capability',
    });
    expect(cap.request.body).toEqual({ capability: 'chat:smart' });
    cap.flush({ data: info });
    api.tryPrompt('candidate_screening', 'ROLE: x').subscribe((t) => (trial = t));
    http.expectOne({ method: 'POST', url: '/api/ai/prompts/candidate_screening/trial' }).flush({
      data: {
        draft: {
          status: 'done',
          error: null,
          version: 'screening.v4-draft',
          data: { verdict: 'fit' },
        },
        active: {
          status: 'failed',
          error: 'ai_budget_exceeded',
          version: 'screening.v4',
          data: null,
        },
      },
    });
    expect(trial?.draft.data?.['verdict']).toBe('fit');
    expect(trial?.active.error).toBe('ai_budget_exceeded');
  });
});
