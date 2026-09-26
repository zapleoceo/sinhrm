import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { RecruitingService } from '../recruiting/recruiting.service';
import { Screening } from '../recruiting/recruiting.model';
import { AiStatus, AiTestResult, usagePercent } from './ai.model';
import { AiService, aiCodeKey, aiErrorKey } from './ai.service';

const STATUS: AiStatus = {
  provider: 'ai_broker',
  capability: 'chat:sales',
  capabilities: { script_evaluation: 'chat:sales', mail_classification: 'chat:fast', candidate_screening: 'chat:sales' },
  model: null,
  configured: true,
  available: true,
  purposes: { script_evaluation: null, mail_classification: null, candidate_screening: 'ai_purpose_disabled' },
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
  prompt_version: 'screening.v3',
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
    expect(aiErrorKey(new HttpErrorResponse({ status: 429, error: { code: 'ai_budget_exceeded' } }))).toBe('ai.errors.ai_budget_exceeded');
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
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
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
      data: { status: 'done', error: null, reply: 'готово', request_id: 1, model: 'm', tokens_in: 1, tokens_out: 1, tokens_cached: 0, cost_usd: 0 },
    });
    expect(status?.capabilities.mail_classification).toBe('chat:fast');
    expect(result?.reply).toBe('готово');
  });

  it('lists and starts screenings', () => {
    const api = TestBed.inject(RecruitingService);
    let list: Screening[] = [];
    let started: Screening | undefined;
    api.screenings(5).subscribe((l) => (list = l));
    http.expectOne({ method: 'GET', url: '/api/candidates/5/screenings' }).flush({ data: [SCREENING] });
    api.screen(7).subscribe((s) => (started = s));
    http.expectOne({ method: 'POST', url: '/api/applications/7/screening' }).flush({ data: { ...SCREENING, status: 'pending' } });
    expect(list[0].verdict).toBe('fit');
    expect(started?.status).toBe('pending');
  });
});
