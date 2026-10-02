import { TestBed } from '@angular/core/testing';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { of } from 'rxjs';
import { ErrorsPage } from './errors.page';
import { ErrorGroup, ErrorsService } from './errors.service';

const group: ErrorGroup = {
  id: 1,
  count: 4,
  source: 'server',
  file: null,
  line: null,
  route: null,
  last_user_id: null,
  resolved_at: null,
  exception_class: 'App\\Modules\\People\\Exceptions\\PeopleExceptionWithAVeryLongName',
  message: 'Boom',
  resolved: false,
  first_seen_at: '2026-10-01T10:00:00Z',
  last_seen_at: '2026-10-01T11:00:00Z',
};

describe('ErrorsPage', () => {
  it('keeps long exception class names inside the card on phones (no horizontal page scroll)', () => {
    TestBed.configureTestingModule({
      imports: [ErrorsPage, TranslocoTestingModule.forRoot({ langs: { uk: {} }, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
      providers: [{ provide: ErrorsService, useValue: { list: () => of([group]) } }],
    });
    const fixture = TestBed.createComponent(ErrorsPage);
    fixture.detectChanges();
    const root = fixture.nativeElement as HTMLElement;
    const name = root.querySelector<HTMLElement>('summary strong');
    expect(name?.textContent).toContain('PeopleExceptionWithAVeryLongName');
    // The list column may shrink below the content width and the class name wraps anywhere.
    expect(getComputedStyle(root.querySelector('.groups')!).gridTemplateColumns).toBe('minmax(0, 1fr)');
    expect(getComputedStyle(name!).overflowWrap).toBe('anywhere');
  });
});
