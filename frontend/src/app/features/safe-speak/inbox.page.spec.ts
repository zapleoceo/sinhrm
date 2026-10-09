import { HttpErrorResponse } from '@angular/common/http';
import { TestBed } from '@angular/core/testing';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { Subject, of, throwError } from 'rxjs';
import { SafeSpeakInboxPage } from './inbox.page';
import { HandledReport, ReportStatus } from './safe-speak.model';
import { SafeSpeakService } from './safe-speak.service';
import { NotifyService } from '../../core/ui/notify.service';

const report = (id: number, subject: string): HandledReport =>
  ({ id, subject, category: 'other', status: 'new', updated_on: '2026-10-01' }) as unknown as HandledReport;

describe('SafeSpeakInboxPage', () => {
  it('another status cancels the request still in flight: its late answer never lands', () => {
    const answers: Subject<HandledReport[]>[] = [];
    const statuses: (ReportStatus | undefined)[] = [];
    const api = {
      inbox: (status?: ReportStatus) => {
        statuses.push(status);
        const answer = new Subject<HandledReport[]>();
        answers.push(answer);
        return answer;
      },
    };
    TestBed.configureTestingModule({
      imports: [SafeSpeakInboxPage, TranslocoTestingModule.forRoot({ langs: {}, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
      providers: [{ provide: SafeSpeakService, useValue: api }],
    });
    const fixture = TestBed.createComponent(SafeSpeakInboxPage);
    fixture.detectChanges();
    const el = fixture.nativeElement as HTMLElement;

    fixture.componentInstance['filter']('closed');
    expect(statuses).toEqual([undefined, 'closed']);
    expect(answers[0].observed).toBe(false);
    answers[0].next([report(1, 'old')]);
    answers[1].next([report(2, 'new')]);
    fixture.detectChanges();
    expect([...el.querySelectorAll('ul.list strong')].map((s) => s.textContent?.trim())).toEqual(['#2 new']);
    expect(el.querySelector('mat-progress-bar')).toBeNull();
  });
});

describe('SafeSpeakInboxPage — handling a report', () => {
  const full = (status: ReportStatus, extra: Record<string, unknown> = {}): HandledReport =>
    ({
      id: 7,
      subject: 'Тиск керівника',
      category: 'harassment',
      status,
      created_on: '2026-10-01',
      updated_on: '2026-10-02',
      messages: [{ author: 'reporter', body: 'Опис ситуації', created_on: '2026-10-01' }],
      ...extra,
    }) as HandledReport;

  function setup(detail: HandledReport) {
    const api = {
      inbox: vi.fn(() => of([report(7, 'Тиск керівника')])),
      get: vi.fn(() => of(detail)),
      answer: vi.fn((_id: number, body: string) =>
        of({ ...detail, status: 'in_review' as const, updated_on: '2026-10-03', messages: [...(detail.messages ?? []), { author: 'handler' as const, body, created_on: '2026-10-03' }] }),
      ),
      setStatus: vi.fn((_id: number, status: ReportStatus) => of({ ...detail, status })),
    };
    const notify = { show: vi.fn() };
    TestBed.configureTestingModule({
      imports: [SafeSpeakInboxPage, TranslocoTestingModule.forRoot({ langs: {}, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
      providers: [
        { provide: SafeSpeakService, useValue: api },
        { provide: NotifyService, useValue: notify },
      ],
    });
    const fixture = TestBed.createComponent(SafeSpeakInboxPage);
    fixture.detectChanges();
    const el = fixture.nativeElement as HTMLElement;
    (el.querySelector('ul.list button') as HTMLButtonElement).click();
    fixture.detectChanges();
    return { fixture, el, api, notify };
  }

  it('the thread names the side (reporter / handler) and a date only — nothing that identifies the reporter', () => {
    // Fields a careless API change could add must never reach the screen.
    const { el } = setup(full('new', { user_id: 42, email: 'someone@sinhrm.test', ip: '10.0.0.1', created_at: '2026-10-01T09:41:00Z' }));
    const view = el.querySelector('section.view') as HTMLElement;
    expect(view.querySelector('h2')?.textContent?.trim()).toBe('#7 Тиск керівника');
    expect(view.querySelector('article.msg')?.getAttribute('data-author')).toBe('reporter');
    for (const leak of ['42', 'someone@sinhrm.test', '10.0.0.1', '09:41']) {
      expect(view.textContent).not.toContain(leak);
    }
  });

  it('an answer goes to the API, the field is cleared and the list row gets the new status', () => {
    const { fixture, el, api } = setup(full('new'));
    fixture.componentInstance['text'].set('Дякуємо, розглядаємо');
    fixture.detectChanges();
    (el.querySelector('form.reply button[type="submit"]') as HTMLButtonElement).click();
    fixture.detectChanges();
    expect(api.answer).toHaveBeenCalledWith(7, 'Дякуємо, розглядаємо');
    expect(fixture.componentInstance['text']()).toBe('');
    expect([...el.querySelectorAll('section.view article.msg')].map((m) => m.getAttribute('data-author'))).toEqual(['reporter', 'handler']);
    expect(el.querySelector('ul.list .app-pill')?.getAttribute('data-tone')).toBe('warn');
  });

  it('a closed report has no answer form; an empty answer cannot be sent', () => {
    const open = setup(full('in_review'));
    const send = open.el.querySelector('form.reply button[type="submit"]') as HTMLButtonElement;
    expect(send.disabled).toBe(true);
    TestBed.resetTestingModule();
    const closed = setup(full('closed'));
    expect(closed.el.querySelector('form.reply')).toBeNull();
  });

  it('a refusal (no handler flag) is shown as a notice, the selection is not replaced', () => {
    const { fixture, api, notify } = setup(full('new'));
    api.setStatus.mockReturnValueOnce(throwError(() => new HttpErrorResponse({ status: 403 })));
    fixture.componentInstance['setStatus']('closed');
    expect(notify.show).toHaveBeenCalledTimes(1);
    expect(fixture.componentInstance['selected']()?.status).toBe('new');
  });
});
