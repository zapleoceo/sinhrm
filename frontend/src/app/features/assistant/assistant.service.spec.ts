import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { AssistantStatus, QuipsResult, TurnResult } from './assistant.model';
import { AssistantService } from './assistant.service';

describe('AssistantService', () => {
  it('unwraps { data } of status, turn and quips; quips go with situation and locale', () => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    const api = TestBed.inject(AssistantService);
    const http = TestBed.inject(HttpTestingController);

    const status = { available: true } as unknown as AssistantStatus;
    let gotStatus: AssistantStatus | undefined;
    api.status().subscribe((s) => (gotStatus = s));
    http.expectOne({ method: 'GET', url: '/api/assistant/status' }).flush({ data: status });
    expect(gotStatus).toEqual(status);

    const turn = { state: 'done' } as unknown as TurnResult;
    let gotTurn: TurnResult | undefined;
    api.turn({ messages: [] } as never).subscribe((t) => (gotTurn = t));
    http.expectOne({ method: 'POST', url: '/api/assistant/turn' }).flush({ data: turn });
    expect(gotTurn).toEqual(turn);

    const quips = { quips: ['hi'] } as unknown as QuipsResult;
    let gotQuips: QuipsResult | undefined;
    api.quips('idle', 'uk').subscribe((q) => (gotQuips = q));
    const req = http.expectOne((r) => r.url === '/api/assistant/quips');
    expect(req.request.params.get('situation')).toBe('idle');
    expect(req.request.params.get('locale')).toBe('uk');
    req.flush({ data: quips });
    expect(gotQuips).toEqual(quips);
    http.verify();
  });
});
