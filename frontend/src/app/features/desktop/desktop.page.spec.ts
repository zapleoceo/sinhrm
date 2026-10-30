import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { ComponentFixture } from '@angular/core/testing';
import { DOCUMENT } from '@angular/common';
import { Component } from '@angular/core';
import { Router, provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { of, throwError } from 'rxjs';
import { AuthService } from '../../core/auth/auth.service';
import { CurrentUser } from '../../core/auth/auth.model';
import { OverviewService } from '../overview/overview.service';
import { DesktopPage } from './desktop.page';
import { DESKTOP_LAUNCHER } from './desktop-launcher';
import { DesktopLayoutStore, desktopLayoutKey } from './desktop-layout.store';

const dashboard = {
  counts: { active: 7, stale: 2, unmatched_inbox: 0, new_today: 1 },
  stale_days: 3,
  my_tasks: { total: 4, overdue: 1, items: [{ id: 1, title: 'private task title', type: 'task', due_at: '', candidate: null }] },
  stale: [],
  funnel: [],
  touches: { days: 7, by_channel: [] },
  day_route: {
    date: '2026-10-30', interviews: 2, tasks: 1,
    items: [{ kind: 'interview' as const, id: 1, at: '2026-10-30T10:00:00Z', end: null, title: 'private interview title', candidate: { id: 2, name: 'private person name' } }],
  },
  timeoff: { out_today: [], my_approvals: { count: 0, items: [] } },
  time: { my_week: null, my_approvals: { count: 3, items: [] } },
  hiring: { my_approvals: { count: 1, items: [] } },
};

function currentUser(modules: string[] = ['recruiting', 'people', 'time-off', 'time', 'hiring-requests']): CurrentUser {
  return { id: 27, name: 'Test User', email: 'test@example.invalid', avatar_url: null, locale: 'uk', roles: ['hr_manager'], modules, status: 'active' };
}

const translations = {
  desktop: {
    eyebrow: 'Your workspace', title: 'Desktop', refresh: 'Refresh', launcher: 'Apps', launcherHint: 'Available apps', groups: { personal: 'Personal' },
    widgets: {
      heading: 'Widgets', hint: 'Arrange your cards.', count: 'Widgets: {{count}}', add: 'Add widget', drag: 'Drag {{title}}', moveUp: 'Move {{title}} up', moveDown: 'Move {{title}} down', remove: 'Remove {{title}}', size: 'Size {{title}}', sizeOption: '{{size}} size for {{title}}',
      sizes: { s: 'Small', m: 'Medium', l: 'Large' }, empty: 'Empty desktop', loading: 'Loading', loadError: 'Failed', noData: 'No data',
      summary: { title: 'Summary', active: 'Active', stale: 'Stale' }, tasks: { title: 'Tasks', open: 'Open', overdue: 'Overdue' }, today: { title: 'Today', interviews: 'Interviews', tasks: 'Tasks' }, approvals: { title: 'Approvals', timeoff: 'Time off', time: 'Timesheets', hiring: 'Hiring' },
    },
  },
  shell: { nav: { overview: 'Overview', tasks: 'Tasks', docs: 'Help', recruiting: 'Recruiting', candidates: 'Candidates', vacancies: 'Vacancies', inbox: 'Inbox', hiringRequests: 'Hiring', reports: 'Reports', people: 'People', peopleList: 'Directory', orgChart: 'Org chart', timeoff: 'Time off', teamCalendar: 'Team calendar', approvals: 'Approvals', time: 'Time', timeApprovals: 'Time approvals', timeTeam: 'Team time', myDocuments: 'Documents', workflowRuns: 'Workflows', perform: 'Performance', objectives: 'Objectives', oneOnOnes: '1:1', feedback: 'Feedback', reviews: 'Reviews', surveys: 'Surveys', mood: 'Mood', services: 'Services', desk: 'Helpdesk', knowledge: 'Knowledge', safeSpeak: 'Safe Speak', reportCatalog: 'Reports', reportBuilder: 'Builder', admin: 'Admin' }, menu: { myProfile: 'My profile' } },
};

@Component({ standalone: true, template: '' })
class TasksRouteProbe {}

describe('DesktopPage', () => {
  let authUser: ReturnType<typeof signal<CurrentUser | null>>;
  let dashboardRequest: ReturnType<typeof vi.fn>;
  let launchRoute: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    localStorage.clear();
    authUser = signal<CurrentUser | null>(currentUser());
    dashboardRequest = vi.fn(() => of(dashboard));
    launchRoute = vi.fn();
    TestBed.configureTestingModule({
      imports: [DesktopPage, TasksRouteProbe, TranslocoTestingModule.forRoot({ langs: { uk: translations }, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
      providers: [
        provideRouter([{ path: '', component: DesktopPage }, { path: 'tasks', component: TasksRouteProbe }]),
        { provide: DESKTOP_LAUNCHER, useValue: { launch: launchRoute } },
        { provide: AuthService, useValue: { user: authUser, hasModule: (module: string) => authUser()?.modules?.includes(module) ?? true } },
        { provide: OverviewService, useValue: { dashboard: dashboardRequest } },
      ],
    });
  });

  afterEach(() => {
    localStorage.clear();
    TestBed.resetTestingModule();
  });

  function render(): ComponentFixture<DesktopPage> {
    const fixture = TestBed.createComponent(DesktopPage);
    fixture.detectChanges();
    TestBed.tick();
    fixture.detectChanges();
    return fixture;
  }

  it('shows real dashboard summaries while withholding task, interview, and candidate details', () => {
    const element = render().nativeElement as HTMLElement;
    expect(element.textContent).toContain('7');
    expect(element.textContent).toContain('4');
    expect(element.textContent).toContain('2');
    expect(element.textContent).toContain('3');
    expect(element.textContent).not.toContain('private task title');
    expect(element.textContent).not.toContain('private interview title');
    expect(element.textContent).not.toContain('private person name');
    expect(element.querySelector('.widget[data-widget="tasks"] .drag-handle')?.getAttribute('aria-label')).toBe('Drag Tasks');
    expect(element.querySelector('.widget[data-widget="tasks"] h3')?.textContent).toBe('Tasks');
    expect(dashboardRequest).toHaveBeenCalledTimes(1);
    expect(element.querySelectorAll('.widget').length).toBe(3);
  });

  it('renders authorized app groups and launches registered routes through the workspace launcher', async () => {
    const fixture = render();
    const element = fixture.nativeElement as HTMLElement;
    expect(element.querySelector('.app-group[data-group="recruiting"]')).not.toBeNull();
    expect(element.querySelector('.app-group[data-group="people"]')).not.toBeNull();
    expect(element.querySelector('[data-testid="desktop-app-candidates"]')?.getAttribute('href')).toBe('/candidates');
    expect(element.querySelector('[data-testid="desktop-app-directory"]')).toBeNull();
    expect(element.querySelector('[data-testid="desktop-app-users"]')).toBeNull();

    const candidateLink = element.querySelector<HTMLAnchorElement>('[data-testid="desktop-app-candidates"]')!;
    const plain = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 });
    candidateLink.dispatchEvent(plain);
    await fixture.whenStable();
    expect(plain.defaultPrevented).toBe(true);
    expect(launchRoute).toHaveBeenCalledWith('/candidates');

    launchRoute.mockClear();
    const modified = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0, ctrlKey: true });
    candidateLink.dispatchEvent(modified);
    expect(modified.defaultPrevented).toBe(false);
    expect(launchRoute).not.toHaveBeenCalled();
  });

  it('adds, resizes, keyboard-reorders and removes widgets while saving only the per-user layout', () => {
    const fixture = render();
    const element = fixture.nativeElement as HTMLElement;
    const add = element.querySelector<HTMLButtonElement>('#desktop-widget-add-approvals');
    expect(add).not.toBeNull();
    add!.click();
    fixture.detectChanges();
    const layout = fixture.debugElement.injector.get(DesktopLayoutStore);
    expect(layout.layout().widgets.at(-1)).toEqual({ id: 'approvals', size: 'm' });
    element.querySelector<HTMLButtonElement>('.widget[data-widget="approvals"] .size-control button:last-child')?.click();
    expect(layout.layout().widgets.at(-1)?.size).toBe('l');
    element.querySelector<HTMLButtonElement>('#desktop-widget-move-approvals-up')?.click();
    expect(layout.layout().widgets.map((widget) => widget.id)).toEqual(['summary', 'tasks', 'approvals', 'today']);
    expect(JSON.parse(localStorage.getItem(desktopLayoutKey(27)) ?? 'null')).toEqual(layout.layout());
    element.querySelector<HTMLButtonElement>('#desktop-widget-remove-approvals')?.click();
    fixture.detectChanges();
    expect(layout.layout().widgets.map((widget) => widget.id)).not.toContain('approvals');
  });

  it('keeps the widget size choices visually distinct in the right rail', () => {
    const fixture = render();
    const element = fixture.nativeElement as HTMLElement;
    const padding = (id: string): number => Number.parseFloat(getComputedStyle(element.querySelector(`[data-widget="${id}"] .widget-content`)!).paddingTop);
    const smallPadding = padding('tasks');
    const mediumPadding = padding('summary');
    expect(smallPadding).toBeLessThan(mediumPadding);

    element.querySelector<HTMLButtonElement>('[data-widget="summary"] .size-control button:last-child')!.click();
    fixture.detectChanges();
    expect(element.querySelector('[data-widget="summary"]')?.classList.contains('size-l')).toBe(true);
    expect(padding('summary')).toBeGreaterThan(mediumPadding);
  });

  it('restores focus to the rendered add, move, and remove controls after layout updates', async () => {
    const fixture = render();
    const document = TestBed.inject(DOCUMENT);
    const element = fixture.nativeElement as HTMLElement;

    element.querySelector<HTMLButtonElement>('#desktop-widget-add-approvals')!.click();
    await fixture.whenStable();
    const remove = element.querySelector<HTMLButtonElement>('#desktop-widget-remove-approvals');
    expect(remove).not.toBeNull();
    expect(document.activeElement).toBe(remove);

    element.querySelector<HTMLButtonElement>('#desktop-widget-move-approvals-up')!.click();
    await fixture.whenStable();
    expect(document.activeElement).toBe(element.querySelector('#desktop-widget-move-approvals-up'));

    element.querySelector<HTMLButtonElement>('#desktop-widget-remove-approvals')!.click();
    await fixture.whenStable();
    const add = element.querySelector<HTMLButtonElement>('#desktop-widget-add-approvals');
    expect(add).not.toBeNull();
    expect(document.activeElement).toBe(add);
  });

  it('hides entries and widgets outside enabled modules and distinguishes load errors from empty data', () => {
    authUser.set(currentUser(['people']));
    dashboardRequest.mockReturnValue(throwError(() => new Error('offline')));
    const element = render().nativeElement as HTMLElement;
    expect(element.querySelector('#desktop-widget-add-summary')).toBeNull();
    expect(element.querySelector('#desktop-widget-add-approvals')).toBeNull();
    expect(element.querySelector('.app-shortcut[aria-label="Vacancies"]')).toBeNull();
    expect(element.textContent).toContain('Failed');
    expect(element.querySelector('.metric-main')).toBeNull();
    expect(element.querySelector('[role="alert"]')).not.toBeNull();
  });

  it('reloads summary data after effective-role changes and isolates layout after an account switch', () => {
    const fixture = render();
    const layout = fixture.debugElement.injector.get(DesktopLayoutStore);
    expect(dashboardRequest).toHaveBeenCalledTimes(1);
    authUser.set({ ...currentUser(['people']), roles: ['employee'] });
    TestBed.tick();
    fixture.detectChanges();
    expect(dashboardRequest).toHaveBeenCalledTimes(2);
    expect(layout.layout().widgets.map((widget) => widget.id)).toEqual(['summary', 'tasks', 'today']);
    expect(fixture.nativeElement.querySelector('#desktop-widget-add-summary')).toBeNull();
    authUser.set({ ...currentUser(['people']), roles: ['employee'], id: 42 });
    TestBed.tick();
    expect(dashboardRequest).toHaveBeenCalledTimes(3);
    expect(layout.layout().widgets.map((widget) => widget.id)).toEqual(['summary', 'tasks', 'today']);
  });

  it('keeps explicit zero values distinct from missing widget data', () => {
    dashboardRequest.mockReturnValue(of({ ...dashboard, my_tasks: { total: 0, overdue: 0, items: [] }, day_route: undefined }));
    const element = render().nativeElement as HTMLElement;
    expect(element.querySelector('[data-widget="tasks"] .metric-main')?.textContent).toContain('0');
    expect(element.querySelector('[data-widget="today"]')?.textContent).toContain('No data');
  });

  it('uses a CDK drop list with sortable cards and exposes explicit keyboard move controls', () => {
    const element = render().nativeElement as HTMLElement;
    expect(element.querySelector('.cdk-drop-list')).not.toBeNull();
    expect(element.querySelectorAll('.cdk-drag').length).toBe(3);
    expect(element.querySelector('#desktop-widget-move-tasks-up')).not.toBeNull();
    expect(element.querySelector('#desktop-widget-move-tasks-down')).not.toBeNull();
  });

  it('routes a real task metric DOM click only through the OS launcher and preserves native modified links', async () => {
    const harness = await RouterTestingHarness.create('/');
    const router = TestBed.inject(Router);
    const link = harness.fixture.nativeElement.querySelector('.metric-secondary[href="/tasks"]') as HTMLAnchorElement;
    expect(link).not.toBeNull();
    expect(link.getAttribute('href')).toBe('/tasks');
    const plain = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 });
    link.dispatchEvent(plain);
    await harness.fixture.whenStable();
    expect(plain.defaultPrevented).toBe(true);
    expect(launchRoute).toHaveBeenCalledTimes(1);
    expect(launchRoute).toHaveBeenCalledWith('/tasks');
    expect(router.url).toBe('/');

    launchRoute.mockClear();
    const modified = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0, ctrlKey: true });
    link.dispatchEvent(modified);
    expect(modified.defaultPrevented).toBe(false);
    expect(launchRoute).not.toHaveBeenCalled();

    const middle = new MouseEvent('click', { bubbles: true, cancelable: true, button: 1 });
    link.dispatchEvent(middle);
    expect(middle.defaultPrevented).toBe(false);
    expect(launchRoute).not.toHaveBeenCalled();
    expect(router.url).toBe('/');
  });
});
