import { HttpErrorResponse } from '@angular/common/http';
import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { Observable, of, throwError } from 'rxjs';
import { AuthService } from '../../../core/auth/auth.service';
import { UserRole } from '../../../core/auth/auth.model';
import { NotifyService } from '../../../core/ui/notify.service';
import { MoodSettings, TeamMood } from '../pulse.model';
import { PulseService } from '../pulse.service';
import { MoodPage } from './mood.page';

/**
 * /pulse/mood: the team trend shows only what the API aggregated — a week below the minimum group stays a hatched
 * column with «—», comments come without names; a 403 (no reports) hides the block; settings are for HR staff only.
 */
const team = (extra: Partial<TeamMood> = {}): TeamMood => ({
  team_size: 12,
  min_group: 5,
  coverage: { answered: 9, total: 12 },
  weeks: [
    { week_start: '2026-09-21', respondents: 9, average: 3.8, distribution: { '3': 2, '4': 7 }, suppressed: false },
    { week_start: '2026-09-28', respondents: null, average: null, distribution: null, suppressed: true },
  ],
  comments: ['Багато зустрічей'],
  ...extra,
});
const settings: MoodSettings = { weekdays: [1, 3, 5], question: 'Як ви?', required: false, alert_drop: 0.5, min_group: 5 };

function render(roles: UserRole[], teamMood: Observable<TeamMood>) {
  const calls = { settings: 0 };
  const api = {
    moodToday: () => of({ ask: false, question: '', required: false, has_employee: true, today: null }),
    myMood: () => of([{ day: '2026-10-01', score: 4, comment: null }]),
    teamMood: () => teamMood,
    moodSettings: () => {
      calls.settings++;
      return of(settings);
    },
  };
  TestBed.configureTestingModule({
    imports: [MoodPage, TranslocoTestingModule.forRoot({ langs: {}, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
    providers: [
      provideRouter([]),
      { provide: PulseService, useValue: api },
      { provide: AuthService, useValue: { user: signal({ id: 1, roles }) } },
      { provide: NotifyService, useValue: { show: vi.fn() } },
    ],
  });
  const fixture = TestBed.createComponent(MoodPage);
  fixture.detectChanges();
  return { el: fixture.nativeElement as HTMLElement, calls };
}

const teamBox = (el: HTMLElement): Element | undefined =>
  [...el.querySelectorAll('section.box')].find((s) => s.querySelector('h2')?.textContent?.includes('pulse.mood.team'));

describe('MoodPage', () => {
  it('a suppressed week is a hatched column with «—», never a number; comments have no names', () => {
    const { el } = render(['employee'], of(team()));
    const box = teamBox(el);
    const weeks = [...(box?.querySelectorAll('.wk') ?? [])];
    expect(weeks.map((w) => w.querySelector('.val')?.textContent?.trim())).toEqual(['3.8', '—']);
    expect(weeks.map((w) => w.querySelector('.col')?.classList.contains('hidden'))).toEqual([false, true]);
    expect([...(box?.querySelectorAll('ul.comments li') ?? [])].map((li) => li.textContent?.trim())).toEqual(['Багато зустрічей']);
    expect(box?.textContent).toContain('pulse.mood.coverage');
  });

  it('a team below the minimum: no coverage figures, the «small team» notice instead', () => {
    const { el } = render(['employee'], of(team({ coverage: null })));
    const box = teamBox(el);
    expect(box?.textContent).toContain('pulse.mood.smallTeam');
    expect(box?.textContent).not.toContain('pulse.mood.coverage');
  });

  it('403 (no reports): the team block is not drawn at all', () => {
    const { el } = render(['employee'], throwError(() => new HttpErrorResponse({ status: 403 })));
    expect(teamBox(el)).toBeUndefined();
  });

  it('settings are asked for and shown to HR staff only', () => {
    const employee = render(['employee'], of(team()));
    expect(employee.calls.settings).toBe(0);
    expect(employee.el.textContent).not.toContain('pulse.mood.settings');
    TestBed.resetTestingModule();
    const hr = render(['hr_manager'], of(team()));
    expect(hr.calls.settings).toBe(1);
    expect(hr.el.textContent).toContain('pulse.mood.settings');
  });
});
