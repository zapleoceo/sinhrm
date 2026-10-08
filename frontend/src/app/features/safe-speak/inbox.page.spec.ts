import { TestBed } from '@angular/core/testing';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { Subject } from 'rxjs';
import { SafeSpeakInboxPage } from './inbox.page';
import { HandledReport, ReportStatus } from './safe-speak.model';
import { SafeSpeakService } from './safe-speak.service';

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
