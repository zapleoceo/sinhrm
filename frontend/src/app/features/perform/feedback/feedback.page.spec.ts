import { TestBed } from '@angular/core/testing';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { Subject } from 'rxjs';
import { Feedback, FeedbackBox } from '../perform.model';
import { PerformService } from '../perform.service';
import { FeedbackPage } from './feedback.page';

const feedback = (id: number, text: string): Feedback => ({
  id,
  from: { id: 1, full_name: 'A' },
  to: { id: 2, full_name: 'B' },
  type: 'praise',
  text,
  visibility: 'private_to_recipient',
  request_id: null,
  answered_at: null,
  created_at: null,
  can_answer: false,
}) as Feedback;

describe('FeedbackPage', () => {
  it('another box cancels the request still in flight: its late answer never lands', async () => {
    const answers = new Map<FeedbackBox, Subject<Feedback[]>>();
    const api = {
      feedback: (box: FeedbackBox) => {
        const answer = new Subject<Feedback[]>();
        answers.set(box, answer);
        return answer;
      },
    };
    TestBed.configureTestingModule({
      imports: [FeedbackPage, TranslocoTestingModule.forRoot({ langs: {}, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
      providers: [{ provide: PerformService, useValue: api }],
    });
    const fixture = TestBed.createComponent(FeedbackPage);
    fixture.detectChanges();
    const el = fixture.nativeElement as HTMLElement;
    expect(el.querySelector('mat-progress-bar')).not.toBeNull();

    fixture.componentInstance['setBox']('given');
    expect(answers.get('received')!.observed).toBe(false);
    answers.get('received')!.next([feedback(1, 'old')]);
    answers.get('given')!.next([feedback(2, 'new')]);
    fixture.detectChanges();
    expect([...el.querySelectorAll('ul.list > li.panel p')].map((p) => p.textContent)).toEqual(['new']);
    expect(el.querySelector('mat-progress-bar')).toBeNull();
  });
});
