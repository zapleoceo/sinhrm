import { provideNativeDateAdapter } from '@angular/material/core';
import { of } from 'rxjs';
import { TablePage } from '../../../testing/table';
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
  let page: TablePage;

  beforeEach(async () => {
    page = await TablePage.open('time/approvals', TimeApprovalsPage, '/time/approvals?sort=overtime&dir=desc', [{ provide: TimeService, useValue: { approvals: () => of(APPROVALS) } }, provideNativeDateAdapter()]);
  });

  it('applies the URL sort (numbers) and switches to the employee A→Z on a title click', async () => {
    expect(page.th('time.week.overtime').getAttribute('aria-sort')).toBe('descending');
    expect(page.column(0)).toEqual(['Мельник', 'Шевченко', 'Антоненко']);
    await page.sort('time.approvals.employee');
    expect(page.params.get('sort')).toBe('employee');
    expect(page.params.get('dir')).toBe('asc');
    expect(page.column(0)).toEqual(['Антоненко', 'Мельник', 'Шевченко']);
  });

  it('week (date range) and worked (number range) filters', async () => {
    await page.navigate('/time/approvals?week_from=2026-09-20&worked_to=41');
    expect(page.column(0)).toEqual(['Антоненко']);
  });
});

describe('TimeTeamPage: sortable / filterable headers, kept when the week changes', () => {
  let page: TablePage;
  let weeks: string[];

  beforeEach(async () => {
    weeks = [];
    const api = { team: (w: string) => (weeks.push(w), of(TEAM)) };
    page = await TablePage.open('time/team', TimeTeamPage, '/time/team?week=2026-09-28&status=draft', [{ provide: TimeService, useValue: api }, provideNativeDateAdapter()]);
  });

  it('filters by status from the URL; «missing» sorts as a number', async () => {
    expect(weeks).toEqual(['2026-09-28']);
    expect(page.column(0)).toEqual(['Шевченко']);
    await page.navigate('/time/team?week=2026-09-28&sort=missing&dir=desc');
    expect(page.column(0)).toEqual(['Мельник', 'Шевченко', 'Антоненко']);
  });

  it('the next week keeps the sort and filters in the URL', async () => {
    await page.sort('time.week.missing');
    (page.root.querySelectorAll<HTMLButtonElement>('header button[mat-icon-button]')[1]).click();
    await page.settle();
    expect(page.params.get('week')).toBe('2026-10-05');
    expect(page.params.get('sort')).toBe('missing');
    expect(page.params.get('status')).toBe('draft');
    expect(weeks.at(-1)).toBe('2026-10-05');
  });
});
