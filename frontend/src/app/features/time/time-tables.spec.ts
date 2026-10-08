import { provideNativeDateAdapter } from '@angular/material/core';
import { Subject, of } from 'rxjs';
import { TablePage, clickTitle, header, openTablePage, sortCount } from '../../../testing/table-page';
import { TeamRow, TimesheetApproval } from './time.model';
import { TimeApprovalsPage } from './time-approvals.page';
import { TimeService } from './time.service';
import { TimeTeamPage } from './time-team.page';

const approval = (id: number, name: string, week: string, worked: number, overtime: number): TimesheetApproval => ({
  id,
  employee: { id, full_name: name },
  week_start: week,
  status: 'submitted',
  expected: 40,
  worked,
  overtime,
  submitted_at: null,
  entries: 5,
});
const APPROVALS = [approval(1, 'Шевченко', '2026-09-21', 42, 2), approval(2, 'Антоненко', '2026-09-28', 40, 0), approval(3, 'Мельник', '2026-09-14', 45.5, 5.5)];

const team = (id: number, name: string, status: TeamRow['status'], missing: number): TeamRow => ({
  employee: { id, full_name: name },
  timesheet_id: null,
  status,
  expected: 40,
  worked: 40 - missing,
  overtime: 0,
  missing,
  absence: 0,
});
const TEAM = [team(1, 'Шевченко', 'draft', 8), team(2, 'Антоненко', 'approved', 0), team(3, 'Мельник', 'submitted', 16)];

describe('TimeApprovalsPage: sortable / filterable headers bound to the URL', () => {
  let page: TablePage<TimeApprovalsPage>;
  const table = (sel = 'table') => page.el.querySelector(sel)!;
  const sort = async (title: string, sel = 'table') => {
    clickTitle(table(sel), title);
    await page.settle();
  };
  const navigate = async (url: string) => {
    await page.router.navigateByUrl(url);
    await page.settle();
  };
  const params = () => new URL(page.router.url, 'http://x').searchParams;
  const cells = (index: number, sel = 'table', inner?: string) =>
    [...table(sel).querySelectorAll('tbody tr')].map((tr) => (inner ? tr.children[index]?.querySelector(inner) : tr.children[index])?.textContent?.trim() ?? '');

  beforeEach(async () => {
    page = await openTablePage(TimeApprovalsPage, '/?sort=overtime&dir=desc', [{ provide: TimeService, useValue: { approvals: () => of(APPROVALS) } }, provideNativeDateAdapter()]);
  });

  it('applies the URL sort (numbers) and switches to the employee A→Z on a title click', async () => {
    expect(header(table(), 'time.week.overtime').getAttribute('aria-sort')).toBe('descending');
    expect(cells(0)).toEqual(['Мельник', 'Шевченко', 'Антоненко']);
    await sort('time.approvals.employee');
    expect(params().get('sort')).toBe('employee');
    expect(params().get('dir')).toBe('asc');
    expect(cells(0)).toEqual(['Антоненко', 'Мельник', 'Шевченко']);
  });

  it('week (date range) and worked (number range) filters', async () => {
    await navigate('/?week_from=2026-09-20&worked_to=41');
    expect(cells(0)).toEqual(['Антоненко']);
    expect(sortCount(page.fixture)).toBe(1); // what an open header filter announces
  });
});

describe('TimeTeamPage: sortable / filterable headers, kept when the week changes', () => {
  let page: TablePage<TimeTeamPage>;
  let weeks: string[];
  const table = () => page.el.querySelector('table')!;
  const names = () => [...table().querySelectorAll('tbody tr')].map((tr) => tr.children[0]?.textContent?.trim());
  const params = () => new URL(page.router.url, 'http://x').searchParams;

  beforeEach(async () => {
    weeks = [];
    const api = { team: (w: string) => (weeks.push(w), of(TEAM)) };
    page = await openTablePage(TimeTeamPage, '/?week=2026-09-28&status=draft', [{ provide: TimeService, useValue: api }, provideNativeDateAdapter()], { week: '2026-09-28' });
  });

  it('filters by status from the URL; «missing» sorts as a number', async () => {
    expect(weeks).toEqual(['2026-09-28']);
    expect(names()).toEqual(['Шевченко']);
    expect(sortCount(page.fixture)).toBe(1);
    await page.router.navigateByUrl('/?week=2026-09-28&sort=missing&dir=desc');
    await page.settle();
    expect(names()).toEqual(['Мельник', 'Шевченко', 'Антоненко']);
  });

  it('the next week keeps the sort and filters in the URL', async () => {
    clickTitle(table(), 'time.week.missing');
    await page.settle();
    page.el.querySelectorAll<HTMLButtonElement>('header button[mat-icon-button]')[1].click();
    await page.settle();
    expect(params().get('week')).toBe('2026-10-05');
    expect(params().get('sort')).toBe('missing');
    expect(params().get('status')).toBe('draft');
  });
});

describe('TimeTeamPage: only the latest week counts', () => {
  it('another week cancels the request still in flight: its late answer never lands', async () => {
    const answers = new Map<string, Subject<TeamRow[]>>();
    const teamOf = (w: string): Subject<TeamRow[]> => {
      const answer = new Subject<TeamRow[]>();
      answers.set(w, answer);
      return answer;
    };
    const page = await openTablePage(TimeTeamPage, '/', [{ provide: TimeService, useValue: { team: teamOf } }, provideNativeDateAdapter()], { week: '2026-09-28' });
    page.fixture.componentRef.setInput('week', '2026-10-05');
    await page.settle();
    expect(answers.get('2026-09-28')!.observed).toBe(false);
    answers.get('2026-10-05')!.next([TEAM[0]]);
    await page.settle();
    expect([...page.el.querySelectorAll('tbody tr')].map((tr) => tr.children[0]?.textContent?.trim())).toEqual([TEAM[0].employee.full_name]);
  });
});
