import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { Subject, of } from 'rxjs';
import { AuthService } from '../../core/auth/auth.service';
import { ArticleQuery, KbArticle } from './knowledge.model';
import { KnowledgePage } from './knowledge.page';
import { KnowledgeService } from './knowledge.service';

const article = (id: number, title: string): KbArticle =>
  ({ id, title, category: null, status: 'published', tags: [], votes: { up: 0, down: 0 }, updated_at: null }) as unknown as KbArticle;

describe('KnowledgePage', () => {
  let answers: Subject<KbArticle[]>[];
  let queries: ArticleQuery[];
  let el: HTMLElement;
  let render: () => void;
  let page: KnowledgePage;

  beforeEach(() => {
    answers = [];
    queries = [];
    const api = {
      categories: () => of([]),
      search: (q: ArticleQuery) => {
        queries.push(q);
        const answer = new Subject<KbArticle[]>();
        answers.push(answer);
        return answer;
      },
    };
    TestBed.configureTestingModule({
      imports: [KnowledgePage, TranslocoTestingModule.forRoot({ langs: {}, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
      providers: [provideRouter([]), { provide: KnowledgeService, useValue: api }, { provide: AuthService, useValue: { user: signal(null) } }],
    });
    const fixture = TestBed.createComponent(KnowledgePage);
    fixture.detectChanges();
    el = fixture.nativeElement as HTMLElement;
    render = () => fixture.detectChanges();
    page = fixture.componentInstance;
  });

  const titles = () => [...el.querySelectorAll('ul.list a.title')].map((a) => a.textContent?.trim());

  it('another tag cancels the search still in flight: its late answer never lands', () => {
    page['setTag']('onboarding');
    expect(queries.at(-1)).toEqual({ tag: 'onboarding' });
    expect(answers[0].observed).toBe(false);
    answers[0].next([article(1, 'old')]);
    answers[1].next([article(2, 'new')]);
    render();
    expect(titles()).toEqual(['📄 new']);
  });

  it('a failed search empties the list instead of keeping stale articles', () => {
    answers[0].next([article(1, 'A')]);
    render();
    page['setTag']('x');
    answers[1].error(new Error('down'));
    render();
    expect(titles()).toEqual([]);
    expect(el.querySelector('mat-progress-bar')).toBeNull();
  });
});
