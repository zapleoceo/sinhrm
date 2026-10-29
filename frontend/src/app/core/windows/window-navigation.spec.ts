import { provideLocationMocks } from '@angular/common/testing';
import { ChangeDetectionStrategy, Component, inject, InjectionToken, OnDestroy, signal } from '@angular/core';
import { By } from '@angular/platform-browser';
import { ActivatedRoute, CanActivateFn, CanDeactivateFn, NavigationStart, Router, RouterLink, RouterOutlet, Routes, convertToParamMap, provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { TestBed } from '@angular/core/testing';
import { AuthService } from '../auth/auth.service';
import { moduleGuard } from '../auth/auth.guards';
import { TableUrlState } from '../ui/table/table-url-state';
import { TABLE_QUERY_ADAPTER, TableQueryAdapter } from '../ui/table/table-query-adapter';
import { isWindowControlOnlyNavigation, WindowNavigation } from './window-navigation';
import { windowOutletRoute } from './window-route';
import { WINDOW_ROUTE_REGISTRY } from './window-context';
import { WindowQueryState } from './window-query-state';
import { decodeWindowQuery, encodeWindowQuery } from './window-query-codec';

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
  template: '<router-outlet name="os1" /><router-outlet name="os2" /><router-outlet />',
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
  providers: [TableUrlState, { provide: TABLE_QUERY_ADAPTER, useClass: WindowQueryState }],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h1>Window page {{ id }}</h1>
    <a id="registered-link" routerLink="/people" target="_blank">People list</a>
    <a id="unsupported-link" routerLink="/people/org-chart">Org chart</a>
  `,
})
class WindowProbe implements OnDestroy {
  readonly id = ++nextId;
  readonly router = inject(Router);
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
    return allowLeave;
  }

  ngOnDestroy(): void {
    destroyed.push(this.id);
  }
}

const windowPageRoutes: Routes = [
  {
    path: 'tasks', title: 'titles.tasks', component: WindowProbe,
    data: { windowRoute: 'tasks', module: null }, canActivate: [rootRouterGuard], canDeactivate: [canLeave],
    providers: [{ provide: ROUTE_ENV_ROUTER, useFactory: () => inject(Router) }],
  },
  {
    path: 'people', title: 'titles.people', component: WindowProbe,
    data: { windowRoute: 'people', module: 'people' }, canActivate: [rootRouterGuard], canDeactivate: [canLeave],
    providers: [{ provide: ROUTE_ENV_ROUTER, useFactory: () => inject(Router) }],
  },
];

const testRoutes: Routes = [
  {
    path: '', component: WindowProbeShell, canActivateChild: [moduleGuard],
    children: [
      { path: '', pathMatch: 'full', component: WindowProbeHome },
      windowOutletRoute('os1', windowPageRoutes),
      windowOutletRoute('os2', windowPageRoutes),
    ],
  },
];

describe('fixed-slot OS routing', () => {
  let harness: RouterTestingHarness;
  let rootRouter: Router;
  let shell: WindowProbeShell;

  beforeEach(async () => {
    peopleEnabled.set(true);
    authUser.set({ id: 1, modules: ['people'], roles: ['admin'], active_role: 'admin' });
    allowLeave = true;
    nextId = 0;
    created.length = 0;
    destroyed.length = 0;
    guardRouters.length = 0;
    TestBed.configureTestingModule({
      imports: [WindowProbeShell, WindowProbeHome, WindowProbe],
      providers: [
        provideRouter(testRoutes),
        provideLocationMocks(),
        {
          provide: AuthService,
          useValue: {
            load: vi.fn(async () => undefined),
            user: authUser.asReadonly(),
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
    expect(anchor.href).toContain('os1:window/people');
    expect(anchor.href.match(/os1:/g)).toHaveLength(1);
    const unsupported = harness.fixture.nativeElement.querySelector('#unsupported-link') as HTMLAnchorElement;
    expect(unsupported.getAttribute('href')).toContain('/people/org-chart');
    expect(unsupported.getAttribute('href')).toContain('os1:window/tasks');

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
    expect(destroyed).toEqual([page?.id]);
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
    expect(rootRouter.url).toContain(';wq_name=Window;wq_sort=name;wq_dir=asc');
    await people?.table.write({ page: 2 }, true);
    expect(rootRouter.url).toContain(';wq_name=Window;wq_sort=name;wq_dir=asc;wq_page=2');
    const definition = WINDOW_ROUTE_REGISTRY.find((route) => route.id === 'people');
    expect(definition).toBeDefined();
    const merged = shell.navigation.createWindowTree('os1', definition!, { page: 3 }, { queryParamsHandling: 'merge', queryParams: { perPage: 20 } });
    const preserved = shell.navigation.createWindowTree('os1', definition!, { name: 'Ignored' }, { queryParamsHandling: 'preserve', queryParams: { sort: 'ignored' } });
    expect(rootRouter.serializeUrl(merged!)).toContain('wq_name=Window;wq_sort=name;wq_dir=asc;wq_page=3;wq_perPage=20');
    expect(rootRouter.serializeUrl(preserved!)).toContain('wq_name=Window;wq_sort=name;wq_dir=asc;wq_page=2');
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
    expect(destroyed).toContain(people?.id);

    peopleEnabled.set(true);
    await shell.navigation.open('os1', 'people');
    harness.fixture.detectChanges();
    const narrowedPeople = created.at(-1);
    authUser.set({ id: 1, modules: [], roles: ['admin'], active_role: 'admin' });
    await harness.fixture.whenStable();
    expect(rootRouter.url).not.toContain('os1:window/people');
    expect(destroyed).toContain(narrowedPeople?.id);

    authUser.set({ id: 1, modules: ['people'], roles: ['admin'], active_role: 'admin' });
    await shell.navigation.open('os1', 'people');
    harness.fixture.detectChanges();
    const rolePeople = created.at(-1);
    authUser.set({ id: 1, modules: ['people'], roles: ['employee'], active_role: 'employee' });
    await harness.fixture.whenStable();
    expect(rootRouter.url).not.toContain('os1:window/people');
    expect(rootRouter.url).not.toContain('os2:window/tasks');
    expect(destroyed).toContain(rolePeople?.id);
    expect(destroyed).toContain(tasks?.id);

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
    expect(destroyed).toContain(replacementPeople?.id);
    expect(destroyed).toContain(replacementTasks?.id);
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
});

describe('window query codec and route registration', () => {
  it('bounds names and values to the documented table query namespace', () => {
    const params = convertToParamMap({ wq_name: 'Alex', wq_id: '77', wq_sort: 'x'.repeat(300) });
    const decoded = decodeWindowQuery(params);
    expect(decoded.get('name')).toBe('Alex');
    expect(decoded.get('id')).toBeNull();
    expect(decoded.get('sort')).toBeNull();
    expect(encodeWindowQuery(convertToParamMap({}), { id: '77' })).toBeNull();
    expect(encodeWindowQuery(convertToParamMap({}), { name: 'x'.repeat(300) })).toBeNull();
  });

  it('uses only the two audited real route definitions in each named outlet', async () => {
    const { routes } = await import('../../app.routes');
    const shellRoute = routes.find((route) => route.path === '' && route.children);
    const primaryTasks = shellRoute?.children?.find((route) => route.path === 'tasks');
    const primaryPeople = shellRoute?.children?.find((route) => route.path === 'people');
    const os1 = shellRoute?.children?.find((route) => route.outlet === 'os1');
    const os2 = shellRoute?.children?.find((route) => route.outlet === 'os2');
    expect(os1?.children).toEqual([primaryTasks, primaryPeople]);
    expect(os2?.children).toEqual([primaryTasks, primaryPeople]);
    expect(os1?.children?.map((route) => route.path)).toEqual(['tasks', 'people']);
    expect(WINDOW_ROUTE_REGISTRY.map((route) => route.path)).toEqual(['tasks', 'people']);
  });
});
