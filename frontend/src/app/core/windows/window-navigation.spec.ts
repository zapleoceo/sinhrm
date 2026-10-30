import { provideLocationMocks } from '@angular/common/testing';
import { Location } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, inject, InjectionToken, input, OnDestroy, signal } from '@angular/core';
import { By } from '@angular/platform-browser';
import { ActivatedRoute, CanActivateFn, CanDeactivateFn, NavigationStart, Router, RouterLink, RouterOutlet, Routes, convertToParamMap, provideRouter, withComponentInputBinding } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { TestBed } from '@angular/core/testing';
import { AuthService } from '../auth/auth.service';
import { moduleGuard, roleGuard } from '../auth/auth.guards';
import { TableUrlState } from '../ui/table/table-url-state';
import { TABLE_QUERY_ADAPTER, TableQueryAdapter } from '../ui/table/table-query-adapter';
import { isWindowControlOnlyNavigation, WindowNavigation } from './window-navigation';
import { windowOutletRoute } from './window-route';
import { isWindowSlot, windowRouteDefinitions } from './window-context';
import { WindowQueryState } from './window-query-state';
import { decodeWindowQuery, encodeWindowQuery } from './window-query-codec';
import { windowAwareQueryParamMap } from './window-aware-query';
import { workspaceRestoreGuard } from './workspace-restore.guard';
import { WorkspaceSnapshotStore } from './workspace-snapshot.store';
import { WorkspaceHistoryRestorer } from './workspace-history-restorer';
import { validateWorkspaceSnapshot } from './workspace-url';

const peopleEnabled = signal(true);
const authUser = signal<{ id: number; modules: string[]; roles: string[]; active_role: string | null } | null>({
  id: 1, modules: ['people'], roles: ['admin'], active_role: 'admin',
});
let allowLeave = true;
const created: WindowProbe[] = [];
const destroyed: number[] = [];
let nextId = 0;
const guardRouters: Router[] = [];
const ROUTE_ENV_ROUTER = new InjectionToken<Router>('route environment root router');

const rootRouterGuard: CanActivateFn = () => {
  const router = inject(Router);
  guardRouters.push(router);
  return true;
};

const canLeave: CanDeactivateFn<WindowProbe> = (page) => page?.canLeave() ?? true;

@Component({
  selector: 'app-window-probe-shell',
  imports: [RouterOutlet],
  providers: [WindowNavigation],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: '<router-outlet name="os1" /><router-outlet name="os2" /><router-outlet name="os3" /><router-outlet name="os4" /><router-outlet />',
})
class WindowProbeShell {
  readonly navigation = inject(WindowNavigation);
}

@Component({
  selector: 'app-window-probe-home',
  imports: [],
  providers: [TableUrlState, { provide: TABLE_QUERY_ADAPTER, useClass: WindowQueryState }],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: '<h1>Primary</h1>',
})
class WindowProbeHome {
  readonly table = inject(TABLE_QUERY_ADAPTER) as TableQueryAdapter;
  readonly urlState = inject(TableUrlState);
  readonly applied = signal(0);
  readonly lastValue = signal('');

  constructor() {
    inject(TableUrlState).watch((params) => params.get('name') ?? '', (value) => {
      this.applied.update((count) => count + 1);
      this.lastValue.set(value);
    });
  }
}

@Component({
  selector: 'app-window-probe-page',
  imports: [RouterLink],
  providers: [TableUrlState],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h1>Window page {{ id() }}</h1>
    <a id="registered-link" routerLink="/people" target="_blank">People list</a>
    <a id="dynamic-link" [routerLink]="['/people', 70]" [queryParams]="{ root: '70', saved: '91' }" fragment="member">Dynamic detail</a>
    <a id="relative-link" [routerLink]="['./70']" fragment="relative">Relative detail</a>
    <a id="unsupported-link" routerLink="/people/org-chart">Org chart</a>
  `,
})
class WindowProbe implements OnDestroy {
  readonly instanceId = ++nextId;
  readonly id = input<string | undefined>();
  private readonly query = windowAwareQueryParamMap();
  readonly root = computed(() => this.query().get('root'));
  readonly saved = computed(() => this.query().get('saved'));
  readonly router = inject(Router);
  readonly routeEnvironmentNavigation = inject(WindowNavigation);
  readonly routeEnvironmentRouter = inject(ROUTE_ENV_ROUTER);
  readonly route = inject(ActivatedRoute);
  readonly table = inject(TABLE_QUERY_ADAPTER) as TableQueryAdapter;
  readonly urlState = inject(TableUrlState);
  readonly applied = signal(0);
  readonly lastValue = signal('');

  constructor() {
    created.push(this);
    inject(TableUrlState).watch((params) => params.get('name') ?? '', (value) => {
      this.applied.update((count) => count + 1);
      this.lastValue.set(value);
    });
  }

  canLeave(): boolean {
    const slot = this.route.pathFromRoot.find((route) => isWindowSlot(route.outlet))?.outlet;
    return (isWindowSlot(slot) && !this.routeEnvironmentNavigation.isAuthorizedSlot(slot)) || allowLeave;
  }

  ngOnDestroy(): void {
    destroyed.push(this.instanceId);
  }
}

const windowPageRoutes: Routes = [
  {
    path: 'tasks', title: 'titles.tasks', component: WindowProbe, canMatch: [workspaceRestoreGuard],
    data: { windowRoute: 'tasks', module: null }, canActivate: [rootRouterGuard], canDeactivate: [canLeave],
    providers: [{ provide: ROUTE_ENV_ROUTER, useFactory: () => inject(Router) }],
  },
  {
    path: 'people', title: 'titles.people', component: WindowProbe, canMatch: [workspaceRestoreGuard],
    data: { windowRoute: 'people', module: 'people' }, canActivate: [rootRouterGuard], canDeactivate: [canLeave],
    providers: [{ provide: ROUTE_ENV_ROUTER, useFactory: () => inject(Router) }],
  },
  {
    path: 'people/:id', title: 'titles.profile', component: WindowProbe, canMatch: [workspaceRestoreGuard],
    data: { windowRoute: 'people/:id', module: 'people' }, canActivate: [rootRouterGuard], canDeactivate: [canLeave],
    providers: [{ provide: ROUTE_ENV_ROUTER, useFactory: () => inject(Router) }],
  },
  {
    path: 'time', title: 'titles.time', component: WindowProbe, canMatch: [workspaceRestoreGuard],
    data: { windowRoute: 'time', module: null }, canActivate: [rootRouterGuard], canDeactivate: [canLeave],
    providers: [{ provide: ROUTE_ENV_ROUTER, useFactory: () => inject(Router) }],
  },
  {
    path: 'time/:id', title: 'titles.time-detail', component: WindowProbe, canMatch: [workspaceRestoreGuard],
    data: { windowRoute: 'time/:id', module: null }, canActivate: [rootRouterGuard], canDeactivate: [canLeave],
    providers: [{ provide: ROUTE_ENV_ROUTER, useFactory: () => inject(Router) }],
  },
  ...['calendar', 'recruiting', 'reports'].map((path) => ({
    path, title: `titles.${path}`, component: WindowProbe,
    canMatch: [workspaceRestoreGuard],
    data: { windowRoute: path, module: null }, canActivate: [rootRouterGuard], canDeactivate: [canLeave],
    providers: [{ provide: ROUTE_ENV_ROUTER, useFactory: () => inject(Router) }],
  })),
  {
    path: 'restricted', title: 'titles.tasks', component: WindowProbe,
    data: { windowRoute: 'restricted', module: null }, canMatch: [workspaceRestoreGuard], canActivate: [roleGuard('admin')], canDeactivate: [canLeave],
    providers: [{ provide: ROUTE_ENV_ROUTER, useFactory: () => inject(Router) }],
  },
];

const testRoutes: Routes = [
  {
    path: '', component: WindowProbeShell, canMatch: [workspaceRestoreGuard], canActivateChild: [moduleGuard],
    children: [
      { path: '', pathMatch: 'full', component: WindowProbeHome, canMatch: [workspaceRestoreGuard] },
      { path: 'tasks', component: WindowProbeHome, canMatch: [workspaceRestoreGuard] },
      { path: 'people', component: WindowProbeHome, canMatch: [workspaceRestoreGuard] },
      { path: 'settings/appearance', component: WindowProbeHome },
      { path: 'restricted', component: WindowProbeHome, canMatch: [workspaceRestoreGuard] },
      { path: 'module-off', component: WindowProbeHome },
      windowOutletRoute('os1', windowPageRoutes),
      windowOutletRoute('os2', windowPageRoutes),
      windowOutletRoute('os3', windowPageRoutes),
      windowOutletRoute('os4', windowPageRoutes),
    ],
  },
];

describe('fixed-slot OS routing', () => {
  let harness: RouterTestingHarness;
  let rootRouter: Router;
  let shell: WindowProbeShell;

  beforeEach(async () => {
    peopleEnabled.set(true);
    sessionStorage.clear();
    authUser.set({ id: 1, modules: ['people'], roles: ['admin'], active_role: 'admin' });
    allowLeave = true;
    nextId = 0;
    created.length = 0;
    destroyed.length = 0;
    guardRouters.length = 0;
    TestBed.configureTestingModule({
      imports: [WindowProbeShell, WindowProbeHome, WindowProbe],
      providers: [
        provideRouter(testRoutes, withComponentInputBinding()),
        provideLocationMocks(),
        {
          provide: AuthService,
          useValue: {
            load: vi.fn(async () => undefined),
            user: authUser.asReadonly(),
            isLoggedIn: () => authUser() !== null,
            hasRole: (role: string) => authUser()?.roles.includes(role) ?? false,
            hasModule: (module: string) => module !== 'people' || (peopleEnabled() && authUser()?.modules.includes(module) === true),
          },
        },
      ],
    });
    harness = await RouterTestingHarness.create();
    await harness.navigateByUrl('/');
    rootRouter = TestBed.inject(Router);
    shell = harness.fixture.debugElement.query(By.directive(WindowProbeShell)).componentInstance as WindowProbeShell;
  });

  it('opens actual pages in both named outlets and scopes RouterLink hrefs without double wrapping', async () => {
    await rootRouter.navigateByUrl('/?tenant=keep');
    await shell.navigation.open('os1', 'tasks');
    harness.fixture.detectChanges();
    expect(rootRouter.url).toContain('os1:window/tasks');
    const tasks = created.find((page) => page.route.snapshot.routeConfig?.data?.['windowRoute'] === 'tasks');
    expect(tasks).toBeDefined();
    expect(tasks?.route.outlet).toBe('primary');
    expect(tasks?.route.pathFromRoot.some((route) => route.outlet === 'os1')).toBe(true);
    expect(tasks?.router).not.toBe(rootRouter);
    expect(tasks?.routeEnvironmentRouter).toBe(rootRouter);
    expect(guardRouters.every((router) => router === rootRouter)).toBe(true);

    const anchor = harness.fixture.nativeElement.querySelector('#registered-link') as HTMLAnchorElement;
    expect(new URL(anchor.href).pathname).toBe('/people');
    expect(anchor.href).not.toContain('os1:window');
    const orgChart = harness.fixture.nativeElement.querySelector('#unsupported-link') as HTMLAnchorElement;
    expect(new URL(orgChart.href).pathname).toBe('/people/org-chart');

    const prepared = tasks?.router.createUrlTree(['/people']);
    expect(prepared).toBeDefined();
    expect(rootRouter.serializeUrl(prepared!)).toContain('os1:window/people');
    await tasks?.router.navigateByUrl(prepared!);
    expect(rootRouter.url.match(/os1:/g)).toHaveLength(1);
    expect(rootRouter.url).toContain('os1:window/people');
    expect(destroyed).toHaveLength(1);

    await shell.navigation.open('os2', 'tasks');
    harness.fixture.detectChanges();
    expect(rootRouter.url).toContain('os1:window/people');
    expect(rootRouter.url).toContain('os2:window/tasks');
    expect(created.filter((page) => page.route.outlet === 'primary')).toHaveLength(3);
    const relative = harness.fixture.nativeElement.querySelector('#relative-link') as HTMLAnchorElement;
    expect(new URL(relative.href).pathname).toBe('/people/70');
    expect(relative.href).toContain('#relative');

    const dynamic = harness.fixture.nativeElement.querySelector('#dynamic-link') as HTMLAnchorElement;
    expect(new URL(dynamic.href).pathname).toBe('/people/70');
    expect(dynamic.href).toContain('root=70');
    expect(dynamic.href).not.toContain('wq_root');
    expect(dynamic.href).toContain('#member');
    await dynamic.click();
    await harness.fixture.whenStable();
    expect(rootRouter.url).toContain('os1:window/people/70');
    expect(rootRouter.url).toContain('wq_root=70');
    expect(rootRouter.url).toContain('#member');
    const detail = created.at(-1);
    expect(detail?.id()).toBe('70');
    expect(detail?.root()).toBe('70');
    expect(detail?.saved()).toBe('91');
    expect(rootRouter.parseUrl(rootRouter.url).queryParams['tenant']).toBe('keep');
  });

  it('uses a clean canonical URL for an opened window and restores its internal route on browser Back', async () => {
    const location = TestBed.inject(Location);
    await shell.navigation.open('os1', 'tasks');
    const taskUrl = rootRouter.url;
    expect(taskUrl).toContain('os1:window/tasks');
    expect(location.path()).toBe('/tasks');
    expect(location.getState()).toMatchObject({
      sinhrmWorkspace: { version: 1, userId: 1, internalUrl: taskUrl, visibleUrl: '/tasks' },
    });

    await shell.navigation.open('os2', 'people');
    expect(location.path()).toBe('/people');
    location.back();
    TestBed.inject(WorkspaceHistoryRestorer).restoreHistoryEntry(
      new NavigationStart(100, location.path(), 'popstate', location.getState() as NavigationStart['restoredState']),
    );
    await harness.fixture.whenStable();
    harness.fixture.detectChanges();

    expect(rootRouter.url).toContain('os1:window/tasks');
    expect(rootRouter.url).not.toContain('os2:window/people');
    expect(shell.navigation.activeSlot()).toBe('os1');
  });

  it('restores the bounded window filter on Back when the canonical pathname stays the same', async () => {
    const location = TestBed.inject(Location);
    await shell.navigation.open('os1', 'people');
    await shell.navigation.updateWindowQuery('os1', { name: 'A' }, false);
    expect(location.path()).toBe('/people?name=A');
    await shell.navigation.updateWindowQuery('os1', { name: 'B' }, false);
    expect(location.path()).toBe('/people?name=B');

    location.back();
    TestBed.inject(WorkspaceHistoryRestorer).restoreHistoryEntry(
      new NavigationStart(100, location.path(), 'popstate', location.getState() as NavigationStart['restoredState']),
    );
    await harness.fixture.whenStable();
    harness.fixture.detectChanges();

    expect(rootRouter.url).toContain('wq_name=A');
    expect(shell.navigation.queryForWindow('os1').get('name')).toBe('A');
    expect(location.path()).toBe('/people?name=A');
  });

  it('restores a primary route filter with its open window roster and rejects credential-like query keys', async () => {
    const location = TestBed.inject(Location);
    await shell.navigation.open('os1', 'tasks');
    await shell.navigation.navigatePrimary('/people');
    await rootRouter.navigate([], { queryParams: { name: 'Primary' }, queryParamsHandling: 'merge' });
    expect(location.path()).toBe('/people?name=Primary');
    expect(shell.navigation.primaryFocused()).toBe(true);
    expect(shell.navigation.windows().os1.open).toBe(true);

    const snapshot = TestBed.inject(WorkspaceSnapshotStore).readHistory(location.getState(), 1)!;
    const hasModule = (module: string) => module !== 'people' || peopleEnabled();
    expect(snapshot).toBeDefined();
    expect(snapshot.visibleUrl).toBe('/people?name=Primary');
    expect(validateWorkspaceSnapshot(rootRouter, snapshot, 1, hasModule)).toBe(true);

    await shell.navigation.close('os1');
    await rootRouter.navigateByUrl(snapshot.internalUrl);
    harness.fixture.detectChanges();
    expect(rootRouter.parseUrl(rootRouter.url).queryParams['name']).toBe('Primary');
    expect(shell.navigation.windows().os1.open).toBe(true);
    expect(shell.navigation.windows().os1.open).toBe(true);
    expect(shell.navigation.primaryFocused()).toBe(true);

    const credentialSnapshot = {
      ...snapshot,
      internalUrl: snapshot.internalUrl.replace('name=Primary', 'authToken=secret'),
      visibleUrl: '/people?authToken=secret',
    };
    expect(credentialSnapshot.internalUrl).toContain('authToken=secret');
    expect(validateWorkspaceSnapshot(rootRouter, credentialSnapshot, 1, hasModule)).toBe(false);
  });

  it('does not queue a workspace restore after the authenticated user changes', async () => {
    const location = TestBed.inject(Location);
    await shell.navigation.open('os1', 'tasks');
    const state = location.getState() as NavigationStart['restoredState'];
    const restorer = TestBed.inject(WorkspaceHistoryRestorer);
    const navigate = vi.spyOn(rootRouter, 'navigateByUrl');
    restorer.restoreHistoryEntry(new NavigationStart(101, '/tasks', 'popstate', state));
    authUser.set({ id: 2, modules: ['people'], roles: ['admin'], active_role: 'admin' });
    await Promise.resolve();

    expect(navigate).not.toHaveBeenCalled();
  });

  it('does not persist workspace history or a session snapshot for credential or oversized route queries', async () => {
    const location = TestBed.inject(Location);
    await rootRouter.navigateByUrl('/?authToken=secret');
    await shell.navigation.open('os1', 'tasks');

    expect(rootRouter.url).toContain('authToken=secret');
    expect(TestBed.inject(WorkspaceSnapshotStore).readHistory(location.getState(), 1)).toBeNull();
    expect(sessionStorage.getItem('sinhrm.workspace.v1.1')).toBeNull();

    await shell.navigation.close('os1');
    await rootRouter.navigateByUrl(`/?filter=${'x'.repeat(1025)}`);
    await shell.navigation.open('os1', 'tasks');

    expect(rootRouter.url).toContain(`filter=${'x'.repeat(1025)}`);
    expect(TestBed.inject(WorkspaceSnapshotStore).readHistory(location.getState(), 1)).toBeNull();
    expect(sessionStorage.getItem('sinhrm.workspace.v1.1')).toBeNull();
  });

  it('classifies focus-state query changes without hiding content route or query changes', async () => {
    await shell.navigation.open('os1', 'tasks');
    const openedUrl = rootRouter.url;
    await shell.navigation.focusPrimary();
    const focusedPrimaryUrl = rootRouter.url;
    expect(isWindowControlOnlyNavigation(rootRouter, openedUrl, focusedPrimaryUrl)).toBe(true);

    const changedContentQuery = rootRouter.serializeUrl(rootRouter.createUrlTree([], {
      queryParams: { search: 'active' }, queryParamsHandling: 'merge',
    }));
    expect(isWindowControlOnlyNavigation(rootRouter, focusedPrimaryUrl, changedContentQuery)).toBe(false);
    expect(isWindowControlOnlyNavigation(rootRouter, focusedPrimaryUrl, focusedPrimaryUrl.replace('window/tasks', 'window/people'))).toBe(false);
  });

  it('applies canonical Router query handling to the window namespace and never imports primary filters', async () => {
    await rootRouter.navigateByUrl('/?name=Primary');
    await shell.navigation.open('os1', 'people');
    harness.fixture.detectChanges();
    const page = created.at(-1)!;
    await page.table.write({ name: 'Window' }, false);
    expect(rootRouter.url).toContain('wq_name=Window');
    expect(rootRouter.parseUrl(rootRouter.url).queryParams['name']).toBe('Primary');

    const reset = page.router.createUrlTree(['/people']);
    await page.router.navigateByUrl(reset);
    expect(rootRouter.url).not.toContain('wq_name=');
    expect(rootRouter.parseUrl(rootRouter.url).queryParams['name']).toBe('Primary');

    await page.table.write({ name: 'Window' }, false);
    await page.router.navigate(['/people'], { queryParams: { sort: 'id' }, queryParamsHandling: 'merge' });
    expect(rootRouter.url).toContain('wq_name=Window');
    expect(rootRouter.url).toContain('wq_sort=id');
    expect(rootRouter.url).not.toContain('wq_name=Primary');

    const preserved = page.router.createUrlTree(['/people'], {
      queryParams: { name: 'Ignored', sort: 'ignored' },
      queryParamsHandling: 'preserve',
    });
    await page.router.navigateByUrl(preserved);
    expect(rootRouter.url).toContain('wq_name=Window');
    expect(rootRouter.url).toContain('wq_sort=id');
    expect(rootRouter.url).not.toContain('ignored');

    await page.router.navigate(['/people'], { queryParams: { name: null }, queryParamsHandling: 'merge' });
    expect(rootRouter.url).not.toContain('wq_name=');
    expect(rootRouter.url).toContain('wq_sort=id');
    expect(rootRouter.parseUrl(rootRouter.url).queryParams['name']).toBe('Primary');
    const snapshot = TestBed.inject(WorkspaceSnapshotStore).readHistory(TestBed.inject(Location).getState(), 1);
    expect(snapshot?.internalUrl).toContain('name=Primary');
    expect(snapshot?.internalUrl).toContain('wq_sort=id');
    expect(snapshot?.visibleUrl).toBe('/people?sort=id');
  });

  it('keeps query-only and relative commands in the current window when primary is a catalog route', async () => {
    await rootRouter.navigateByUrl('/people?name=Primary');
    await shell.navigation.open('os1', 'time');
    harness.fixture.detectChanges();
    const time = created.at(-1)!;

    await time.router.navigate([], { queryParams: { week: '2026-10-26' }, queryParamsHandling: 'merge' });
    expect(rootRouter.url).toContain('/people');
    expect(rootRouter.url).toContain('os1:window/time');
    expect(rootRouter.url).toContain('wq_week=2026-10-26');
    expect(rootRouter.parseUrl(rootRouter.url).queryParams['name']).toBe('Primary');

    harness.fixture.detectChanges();
    const relative = harness.fixture.nativeElement.querySelector('#relative-link') as HTMLAnchorElement;
    expect(new URL(relative.href).pathname).toBe('/time/70');
    expect(relative.href).toContain('week=2026-10-26');
    expect(relative.href).not.toContain('os1:window');
    await relative.click();
    await harness.fixture.whenStable();
    expect(rootRouter.url).toContain('/people');
    expect(rootRouter.url).toContain('os1:window/time;wq_week=2026-10-26/70');
    expect(rootRouter.parseUrl(rootRouter.url).queryParams['name']).toBe('Primary');

    const detail = created.at(-1)!;
    const canonicalPeople = detail.router.createUrlTree(['/people']);
    expect(rootRouter.serializeUrl(canonicalPeople)).toContain('os1:window/people');
    expect(rootRouter.serializeUrl(canonicalPeople)).not.toContain('os1:window/time');
    await detail.router.navigateByUrl(canonicalPeople);
    expect(rootRouter.url).toContain('/people');
    expect(rootRouter.url).toContain('os1:window/people');
    expect(rootRouter.url).not.toContain('os1:window/time');
    expect(rootRouter.parseUrl(rootRouter.url).queryParams['name']).toBe('Primary');
  });

  it('stops host query callbacks when a child page is replaced repeatedly in the same window', async () => {
    await shell.navigation.open('os1', 'tasks');
    harness.fixture.detectChanges();
    const tasks = created.at(-1)!;
    await tasks.table.write({ name: 'Task' }, false);
    await tasks.router.navigate(['/people']);
    await harness.fixture.whenStable();
    harness.fixture.detectChanges();
    const people = created.at(-1)!;
    const taskEmissions = tasks.applied();
    await people.table.write({ name: 'Person' }, false);
    expect(tasks.applied()).toBe(taskEmissions);

    await people.router.navigate(['/calendar']);
    await harness.fixture.whenStable();
    harness.fixture.detectChanges();
    const calendar = created.at(-1)!;
    const peopleEmissions = people.applied();
    const taskEmissionsAfterReplace = tasks.applied();
    await calendar.table.write({ name: 'Calendar' }, false);
    expect(people.applied()).toBe(peopleEmissions);
    expect(tasks.applied()).toBe(taskEmissionsAfterReplace);
    expect(calendar.lastValue()).toBe('Calendar');
  });

  it('keeps minimized routes alive and closes through canDeactivate', async () => {
    await shell.navigation.open('os1', 'tasks');
    harness.fixture.detectChanges();
    const page = created.at(-1);
    expect(page).toBeDefined();

    await shell.navigation.focusPrimary();
    expect(shell.navigation.activeSlot()).toBeNull();
    expect(shell.navigation.windows().os1.open).toBe(true);
    await shell.navigation.open('os2', 'tasks');
    expect(shell.navigation.activeSlot()).toBe('os1');
    expect(created).toHaveLength(1);

    await shell.navigation.minimize('os1');
    expect(destroyed).toHaveLength(0);
    expect(shell.navigation.windows().os1.minimized).toBe(true);
    const pageCount = created.length;
    await shell.navigation.open('os2', 'tasks');
    expect(shell.navigation.windows().os1.minimized).toBe(false);
    expect(shell.navigation.activeSlot()).toBe('os1');
    expect(created).toHaveLength(pageCount);
    await shell.navigation.focusPrimary();
    expect(shell.navigation.activeSlot()).toBeNull();
    await shell.navigation.restore('os1');
    expect(shell.navigation.activeSlot()).toBe('os1');

    allowLeave = false;
    expect(await shell.navigation.close('os1')).toBe(false);
    expect(rootRouter.url).toContain('os1:window/tasks');
    expect(destroyed).toHaveLength(0);

    allowLeave = true;
    expect(await shell.navigation.close('os1')).toBe(true);
    expect(rootRouter.url).not.toContain('os1:window/tasks');
    expect(destroyed).toEqual([page?.instanceId]);
  });

  it('shows Desktop without destroying a dirty retained window and still honors canceled close', async () => {
    await shell.navigation.open('os1', 'tasks');
    harness.fixture.detectChanges();
    const retained = created.at(-1);
    expect(retained).toBeDefined();
    expect(rootRouter.url).toContain('os1:window/tasks');

    allowLeave = false;
    expect(await shell.navigation.focusPrimary()).toBe(true);
    harness.fixture.detectChanges();
    expect(shell.navigation.primaryFocused()).toBe(true);
    expect(rootRouter.url).toContain('os1:window/tasks');
    expect(created).toContain(retained);
    expect(destroyed).not.toContain(retained?.instanceId);

    expect(await shell.navigation.close('os1')).toBe(false);
    expect(rootRouter.url).toContain('os1:window/tasks');
    expect(destroyed).not.toContain(retained?.instanceId);
  });

  it('rejects a direct disabled-module auxiliary URL while the primary route is core', async () => {
    peopleEnabled.set(false);
    const directUrl = rootRouter.parseUrl('/(os1:window/people)');
    const result = await rootRouter.navigateByUrl(directUrl);
    expect(result).toBe(false);
    expect(rootRouter.url).toBe('/');
    expect(created).toHaveLength(0);
    expect(await shell.navigation.open('os1', 'people')).toBe(false);
    expect(rootRouter.url).toBe('/');
  });

  it('does not restore a disabled module snapshot and lets moduleGuard redirect the canonical URL', async () => {
    const location = TestBed.inject(Location);
    await shell.navigation.open('os1', 'people');
    const savedState = location.getState() as NavigationStart['restoredState'];
    await shell.navigation.close('os1');
    peopleEnabled.set(false);
    location.go('/people', '', savedState);
    TestBed.inject(WorkspaceHistoryRestorer).restoreHistoryEntry(
      new NavigationStart(100, location.path(), 'popstate', savedState),
    );
    await rootRouter.navigateByUrl('/people');

    expect(rootRouter.url).toBe('/module-off?module=people');
    expect(rootRouter.url).not.toContain('os1:window');
  });

  it('replays page role guards before mounting a restored workspace window', async () => {
    const location = TestBed.inject(Location);
    await shell.navigation.open('os1', 'restricted');
    await shell.navigation.open('os2', 'tasks');
    authUser.set({ id: 1, modules: ['people'], roles: ['employee'], active_role: 'employee' });
    location.back();
    TestBed.inject(WorkspaceHistoryRestorer).restoreHistoryEntry(
      new NavigationStart(100, location.path(), 'popstate', location.getState() as NavigationStart['restoredState']),
    );
    await harness.fixture.whenStable();

    expect(rootRouter.url).toBe('/');
    expect(rootRouter.url).not.toContain('os1:window');
  });

  it('accepts legacy named-outlet URLs and replaces the address bar with their canonical route', async () => {
    const location = TestBed.inject(Location);
    await rootRouter.navigateByUrl('/(os1:window/tasks)');
    expect(rootRouter.url).toContain('os1:window/tasks');
    expect(location.path()).toBe('/tasks');
    expect(location.getState()).toMatchObject({ sinhrmWorkspace: { userId: 1, visibleUrl: '/tasks' } });
  });

  it('keeps auxiliary table state in matrix params and ignores primary query changes', async () => {
    await shell.navigation.open('os1', 'people');
    harness.fixture.detectChanges();
    const people = created.at(-1);
    const home = harness.fixture.debugElement.query(By.directive(WindowProbeHome)).componentInstance as WindowProbeHome;
    expect(people).toBeDefined();

    await people?.table.write({ name: 'Window' }, false);
    expect(rootRouter.url).toContain(';wq_name=Window');
    expect(people?.lastValue()).toBe('Window');
    await people?.table.write({ sort: 'name', dir: 'asc' }, true);
    for (const value of [';wq_name=Window', ';wq_sort=name', ';wq_dir=asc']) expect(rootRouter.url).toContain(value);
    await people?.table.write({ page: 2 }, true);
    for (const value of [';wq_name=Window', ';wq_sort=name', ';wq_dir=asc', ';wq_page=2']) expect(rootRouter.url).toContain(value);
    const { routes } = await import('../../app.routes');
    const shellRoute = routes.find((route) => route.path === '' && route.children);
    const definition = windowRouteDefinitions(shellRoute?.children ?? []).find((route) => route.id === 'people');
    expect(definition).toBeDefined();
    const merged = shell.navigation.createWindowTree('os1', definition!, { page: 3 }, { queryParamsHandling: 'merge', queryParams: { perPage: 20 } });
    const preserved = shell.navigation.createWindowTree('os1', definition!, { name: 'Ignored' }, { queryParamsHandling: 'preserve', queryParams: { sort: 'ignored' } });
    for (const value of ['wq_name=Window', 'wq_sort=name', 'wq_dir=asc', 'wq_page=3', 'wq_perPage=20']) expect(rootRouter.serializeUrl(merged!)).toContain(value);
    for (const value of ['wq_name=Window', 'wq_sort=name', 'wq_dir=asc', 'wq_page=2']) expect(rootRouter.serializeUrl(preserved!)).toContain(value);
    expect(rootRouter.serializeUrl(preserved!)).not.toContain('ignored');
    await people?.table.write({ name: null }, true);
    expect(rootRouter.url).not.toContain('wq_name=');
    expect(rootRouter.url).toContain('wq_sort=name');
    const peopleEmissions = people?.applied();
    await home.table.write({ name: 'Primary' }, false);
    expect(rootRouter.parseUrl(rootRouter.url).queryParams['name']).toBe('Primary');
    expect(rootRouter.url).toContain(';wq_sort=name');
    expect(rootRouter.url).toContain(';wq_page=2');
    expect(people?.lastValue()).toBe('');
    expect(people?.applied()).toBe(peopleEmissions);

    await people?.router.navigate([], { queryParams: { root: '81' }, queryParamsHandling: 'merge' });
    expect(rootRouter.url).toContain(';wq_root=81');
    expect(rootRouter.parseUrl(rootRouter.url).queryParams['name']).toBe('Primary');
    expect(people?.root()).toBe('81');
  });

  it('rejects an oversized window query instead of silently navigating the canonical route in primary', async () => {
    await shell.navigation.open('os1', 'people');
    expect(() => shell.navigation.createUrlTreeFromCommands('os1', ['/people'], { queryParams: { search: 'x'.repeat(1100) } })).toThrow(/per-window URL limits/);
    expect(rootRouter.url).toContain('os1:window/people');
  });

  it('preserves queued window edits during focus and other-slot writes, but drops them when its route changes', async () => {
    await shell.navigation.open('os1', 'people');
    harness.fixture.detectChanges();
    const people = created.at(-1);
    await shell.navigation.open('os2', 'tasks');
    harness.fixture.detectChanges();
    const tasks = created.at(-1);
    expect(people).toBeDefined();
    expect(tasks).toBeDefined();

    people?.urlState.live(() => people.urlState.setFilter('name', 'Queued'), { delay: 60_000 });
    expect(people?.urlState.writing()).not.toBeNull();
    expect(people?.table.shouldDropPendingEdit(new NavigationStart(1, rootRouter.url, 'imperative'))).toBe(false);
    expect(people?.table.shouldDropPendingEdit(new NavigationStart(2, rootRouter.url, 'popstate'))).toBe(true);
    await shell.navigation.focus('os2');
    expect(people?.urlState.writing()).not.toBeNull();
    await shell.navigation.minimize('os2');
    expect(people?.urlState.writing()).not.toBeNull();
    await shell.navigation.restore('os2');
    expect(people?.urlState.writing()).not.toBeNull();
    tasks?.urlState.update({ name: 'Other slot' }, { paging: true });
    tasks?.urlState.flush();
    await harness.fixture.whenStable();
    expect(people?.urlState.writing()).not.toBeNull();
    people?.urlState.flush();
    await harness.fixture.whenStable();
    expect(rootRouter.url).toContain('wq_name=Queued');
    expect(rootRouter.url).toContain('wq_name=Other%20slot');

    people?.urlState.live(() => people.urlState.setFilter('name', 'Discarded'), { delay: 60_000 });
    const oldPeopleState = people?.urlState;
    await rootRouter.navigateByUrl('/');
    expect(oldPeopleState?.writing()).toBeNull();
    expect(rootRouter.url).toBe('/');
  });

  it('removes retained routes when module access or identity changes', async () => {
    await shell.navigation.open('os1', 'people');
    harness.fixture.detectChanges();
    const people = created.at(-1);
    await shell.navigation.open('os2', 'tasks');
    harness.fixture.detectChanges();
    const tasks = created.at(-1);

    peopleEnabled.set(false);
    authUser.set({ id: 1, modules: ['people'], roles: ['admin'], active_role: 'admin' });
    await harness.fixture.whenStable();
    expect(rootRouter.url).not.toContain('os1:window/people');
    expect(rootRouter.url).toContain('os2:window/tasks');
    expect(destroyed).toContain(people?.instanceId);

    peopleEnabled.set(true);
    await shell.navigation.open('os1', 'people');
    harness.fixture.detectChanges();
    const narrowedPeople = created.at(-1);
    authUser.set({ id: 1, modules: [], roles: ['admin'], active_role: 'admin' });
    await harness.fixture.whenStable();
    expect(rootRouter.url).not.toContain('os1:window/people');
    expect(destroyed).toContain(narrowedPeople?.instanceId);

    authUser.set({ id: 1, modules: ['people'], roles: ['admin'], active_role: 'admin' });
    await shell.navigation.open('os1', 'people');
    harness.fixture.detectChanges();
    const rolePeople = created.at(-1);
    allowLeave = false; // Simulate a dirty editor that would normally reject user initiated close.
    authUser.set({ id: 1, modules: ['people'], roles: ['employee'], active_role: 'employee' });
    await harness.fixture.whenStable();
    expect(rootRouter.url).not.toContain('os1:window/people');
    expect(rootRouter.url).not.toContain('os2:window/tasks');
    expect(destroyed).toContain(rolePeople?.instanceId);
    expect(destroyed).toContain(tasks?.instanceId);
    expect(await shell.navigation.focus('os1')).toBe(false);

    allowLeave = true;
    await shell.navigation.open('os1', 'people');
    harness.fixture.detectChanges();
    const replacementPeople = created.at(-1);
    await shell.navigation.open('os2', 'tasks');
    harness.fixture.detectChanges();
    const replacementTasks = created.at(-1);
    authUser.set({ id: 2, modules: ['people'], roles: ['employee'], active_role: 'employee' });
    expect(await shell.navigation.restore('os1')).toBe(false);
    expect(shell.navigation.windows().os1.open).toBe(false);
    await harness.fixture.whenStable();
    expect(rootRouter.url).not.toContain('os1:window/people');
    expect(rootRouter.url).not.toContain('os2:window/tasks');
    expect(destroyed).toContain(replacementPeople?.instanceId);
    expect(destroyed).toContain(replacementTasks?.instanceId);
  });

  it('Back restores the prior slot set and active window from the URL', async () => {
    await shell.navigation.open('os1', 'tasks');
    const previousUrl = rootRouter.url;
    await shell.navigation.open('os2', 'people');
    expect(shell.navigation.windows().os2.active).toBe(true);

    // Replay the complete previous URL, including the named outlet and active-slot state.
    await rootRouter.navigateByUrl(previousUrl);
    harness.fixture.detectChanges();

    expect(rootRouter.url).toBe(previousUrl);
    expect(rootRouter.url).toContain('os1:window/tasks');
    expect(rootRouter.url).not.toContain('os2:window/people');
    expect(shell.navigation.activeSlot()).toBe('os1');
  });

  it('mounts four independent windows and refuses a fifth without evicting dirty views', async () => {
    const routeIds = ['tasks', 'people', 'calendar', 'recruiting'] as const;
    for (let index = 0; index < routeIds.length; index++) {
      await shell.navigation.open(`os${index + 1}` as 'os1' | 'os2' | 'os3' | 'os4', routeIds[index]);
    }
    expect(Object.values(shell.navigation.windows()).every((window) => window.open)).toBe(true);
    expect(shell.navigation.slotFor('reports')).toBeNull();
    expect(await shell.navigation.openAvailable('reports')).toBe(false);
    expect(rootRouter.url).toContain('os1:window/tasks');
    expect(rootRouter.url).toContain('os2:window/people');
    expect(rootRouter.url).toContain('os3:window/calendar');
    expect(rootRouter.url).toContain('os4:window/recruiting');
  });
});

describe('window query codec and route registration', () => {
  it('bounds names and values to the documented table query namespace', () => {
    const params = convertToParamMap({ wq_name: 'Alex', wq_id: '77', wq_sort: 'x'.repeat(1200) });
    const decoded = decodeWindowQuery(params);
    expect(decoded.get('name')).toBe('Alex');
    expect(decoded.get('id')).toBeNull();
    expect(decoded.get('sort')).toBeNull();
    expect(encodeWindowQuery(convertToParamMap({}), { id: '77' })).toBeNull();
    expect(encodeWindowQuery(convertToParamMap({}), { name: 'x'.repeat(1200) })).toBeNull();
  });

  it('reuses every authenticated route definition in each named outlet and excludes primary-only pages', async () => {
    const { routes } = await import('../../app.routes');
    const shellRoute = routes.find((route) => route.path === '' && route.children);
    const workspace = windowRouteDefinitions(shellRoute?.children ?? []).map((definition) => definition.route);
    for (const slot of ['os1', 'os2', 'os3', 'os4']) {
      expect(shellRoute?.children?.find((route) => route.outlet === slot)?.children).toEqual(workspace);
    }
    expect(workspace.length).toBeGreaterThan(49);
    expect(workspace.some((route) => route.path === 'admin/integrations')).toBe(true);
    expect(workspace.some((route) => route.path === 'module-off')).toBe(false);
    expect(workspace.some((route) => route.path === '')).toBe(false);
  });
});
