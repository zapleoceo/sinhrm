import { ChangeDetectionStrategy, Component, OnDestroy, inject } from '@angular/core';
import { By } from '@angular/platform-browser';
import { provideLocationMocks } from '@angular/common/testing';
import { ActivatedRoute, Router, RouterOutlet, Routes, provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { TestBed } from '@angular/core/testing';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { signal } from '@angular/core';
import { AuthService } from '../../core/auth/auth.service';
import { WindowNavigation } from '../../core/windows/window-navigation';
import { windowOutletRoute } from '../../core/windows/window-route';
import { OsWindowWorkspace } from './os-window-workspace';

const created: GeometryPage[] = [];
const destroyed: number[] = [];
let nextId = 0;

@Component({
  selector: 'app-geometry-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: '<h1>Routed page {{ id }}</h1>',
})
class GeometryPage implements OnDestroy {
  readonly id = ++nextId;
  readonly route = inject(ActivatedRoute);

  constructor() {
    created.push(this);
  }

  ngOnDestroy(): void {
    destroyed.push(this.id);
  }
}

@Component({
  selector: 'app-geometry-shell',
  imports: [OsWindowWorkspace, RouterOutlet],
  providers: [WindowNavigation],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: '<app-os-window-workspace [narrow]="false" /><router-outlet />',
})
class GeometryShell {
  readonly navigation = inject(WindowNavigation);
}

const routes: Routes = [
  {
    path: '', component: GeometryShell, children: [
      { path: '', pathMatch: 'full', component: GeometryPage },
      windowOutletRoute('os1', [{ path: 'tasks', component: GeometryPage, data: { windowRoute: 'tasks', module: null } }]),
      windowOutletRoute('os2', [{ path: 'people', component: GeometryPage, data: { windowRoute: 'people', module: null } }]),
    ],
  },
];

function pointer(type: string, id: number, x: number, y: number): MouseEvent {
  const event = new MouseEvent(type, { bubbles: true, cancelable: true, button: 0, clientX: x, clientY: y });
  Object.defineProperty(event, 'pointerId', { value: id });
  return event;
}

describe('OsWindowWorkspace geometry', () => {
  let harness: RouterTestingHarness;
  let router: Router;
  let shell: GeometryShell;

  beforeEach(async () => {
    created.length = 0;
    destroyed.length = 0;
    nextId = 0;
    Object.defineProperty(window, 'innerHeight', { configurable: true, value: 1000 });
    TestBed.configureTestingModule({
      imports: [GeometryShell, GeometryPage, TranslocoTestingModule.forRoot({ langs: {}, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
      providers: [
        provideRouter(routes),
        provideLocationMocks(),
        { provide: AuthService, useValue: { user: signal({ id: 1, modules: [], roles: ['employee'], active_role: 'employee' }), hasModule: () => true } },
      ],
    });
    harness = await RouterTestingHarness.create();
    await harness.navigateByUrl('/');
    router = TestBed.inject(Router);
    shell = harness.fixture.debugElement.query(By.directive(GeometryShell)).componentInstance as GeometryShell;
  });

  it('moves and resizes a live routed page without route navigation or instance replacement', async () => {
    await shell.navigation.open('os1', 'tasks');
    harness.fixture.detectChanges();
    const page = created.at(-1);
    expect(page).toBeDefined();
    const url = router.url;
    const layer = harness.fixture.nativeElement.querySelector('.window-layer') as HTMLElement;
    const article = layer.querySelector('[data-testid="os-window-os1"]') as HTMLElement;
    const titlebar = article.querySelector('[data-testid="window-titlebar-os1"]') as HTMLElement;
    const originalLeft = Number.parseFloat(article.style.left);
    const originalTop = Number.parseFloat(article.style.top);

    titlebar.dispatchEvent(pointer('pointerdown', 11, 100, 100));
    layer.dispatchEvent(pointer('pointermove', 11, 160, 140));
    layer.dispatchEvent(pointer('pointerup', 11, 170, 150));
    harness.fixture.detectChanges();
    expect(Number.parseFloat(article.style.left)).toBe(originalLeft + 70);
    expect(Number.parseFloat(article.style.top)).toBe(originalTop + 50);
    expect(router.url).toBe(url);
    expect(created).toHaveLength(2); // primary page and one routed window page
    expect(destroyed).toEqual([]);

    const originalWidth = Number.parseFloat(article.style.width);
    const originalHeight = Number.parseFloat(article.style.height);
    const resize = article.querySelector('[data-testid="window-resize-os1"]') as HTMLButtonElement;
    resize.dispatchEvent(pointer('pointerdown', 12, 300, 300));
    layer.dispatchEvent(pointer('pointermove', 12, 380, 360));
    layer.dispatchEvent(pointer('pointerup', 12, 380, 360));
    harness.fixture.detectChanges();
    expect(Number.parseFloat(article.style.width)).toBe(originalWidth + 80);
    expect(Number.parseFloat(article.style.height)).toBe(originalHeight + 60);
    expect(router.url).toBe(url);
    expect(created.at(-1)).toBe(page);
    expect(destroyed).toEqual([]);

    const geometryBeforeModeChange = {
      left: article.style.left,
      top: article.style.top,
      width: article.style.width,
      height: article.style.height,
    };
    await shell.navigation.toggleMaximize('os1');
    harness.fixture.detectChanges();
    expect(article.classList.contains('maximized')).toBe(true);
    await shell.navigation.toggleMaximize('os1');
    harness.fixture.detectChanges();
    expect(article.style.left).toBe(geometryBeforeModeChange.left);
    expect(article.style.top).toBe(geometryBeforeModeChange.top);
    expect(article.style.width).toBe(geometryBeforeModeChange.width);
    expect(article.style.height).toBe(geometryBeforeModeChange.height);
    await shell.navigation.minimize('os1');
    harness.fixture.detectChanges();
    await shell.navigation.restore('os1');
    harness.fixture.detectChanges();
    expect(article.style.left).toBe(geometryBeforeModeChange.left);
    expect(article.style.top).toBe(geometryBeforeModeChange.top);
    expect(article.style.width).toBe(geometryBeforeModeChange.width);
    expect(article.style.height).toBe(geometryBeforeModeChange.height);
    expect(created.at(-1)).toBe(page);
    expect(destroyed).toEqual([]);
  });

  it('focuses an inactive retained window through DOM focusin and ignores hidden windows', async () => {
    await shell.navigation.open('os1', 'tasks');
    await shell.navigation.open('os2', 'people');
    harness.fixture.detectChanges();
    const tasksPage = created.find((page) => page.route.snapshot.data['windowRoute'] === 'tasks');
    const peoplePage = created.find((page) => page.route.snapshot.data['windowRoute'] === 'people');
    expect(tasksPage).toBeDefined();
    expect(peoplePage).toBeDefined();
    expect(shell.navigation.activeSlot()).toBe('os2');

    const workspace = harness.fixture.nativeElement.querySelector('.window-layer') as HTMLElement;
    const tasksArticle = workspace.querySelector('[data-testid="os-window-os1"]') as HTMLElement;
    const tasksTitlebar = tasksArticle.querySelector('[data-testid="window-titlebar-os1"]') as HTMLElement;
    const tasksQueryBefore = shell.navigation.queryForWindow('os1').keys.map((key) => [key, shell.navigation.queryForWindow('os1').getAll(key)]);
    const focus = vi.spyOn(shell.navigation, 'focus');

    tasksTitlebar.focus();
    await harness.fixture.whenStable();
    harness.fixture.detectChanges();
    expect(shell.navigation.activeSlot()).toBe('os1');
    expect(focus).toHaveBeenCalledTimes(1);
    expect(focus).toHaveBeenCalledWith('os1');
    expect(router.parseUrl(router.url).root.children['os1']).toBeDefined();
    expect(router.parseUrl(router.url).root.children['os2']).toBeDefined();
    const tasksQueryAfter = shell.navigation.queryForWindow('os1').keys.map((key) => [key, shell.navigation.queryForWindow('os1').getAll(key)]);
    expect(tasksQueryAfter).toEqual(tasksQueryBefore);
    expect(created).toContain(tasksPage);
    expect(created).toContain(peoplePage);
    expect(destroyed).toEqual([]);

    tasksTitlebar.blur();
    tasksTitlebar.focus();
    await harness.fixture.whenStable();
    expect(focus).toHaveBeenCalledTimes(1); // The active slot does not trigger another navigation.

    await shell.navigation.minimize('os2');
    harness.fixture.detectChanges();
    const peopleArticle = workspace.querySelector('[data-testid="os-window-os2"]') as HTMLElement;
    peopleArticle.dispatchEvent(new FocusEvent('focusin', { bubbles: true }));
    await harness.fixture.whenStable();
    expect(focus).toHaveBeenCalledTimes(1); // Hidden/inert content cannot steal focus.
    expect(created).toContain(peoplePage);
    expect(destroyed).toEqual([]);
  });

  it('moves from the keyboard and resets a slot geometry without touching the routed page', async () => {
    await shell.navigation.open('os1', 'tasks');
    harness.fixture.detectChanges();
    const page = created.at(-1);
    const article = harness.fixture.nativeElement.querySelector('[data-testid="os-window-os1"]') as HTMLElement;
    const titlebar = article.querySelector('[data-testid="window-titlebar-os1"]') as HTMLElement;
    const originalLeft = Number.parseFloat(article.style.left);
    titlebar.dispatchEvent(new KeyboardEvent('keydown', { bubbles: true, key: 'ArrowRight' }));
    harness.fixture.detectChanges();
    expect(Number.parseFloat(article.style.left)).toBe(originalLeft + 16);

    const originalWidth = Number.parseFloat(article.style.width);
    (article.querySelector('.window-resize') as HTMLButtonElement).dispatchEvent(new KeyboardEvent('keydown', { bubbles: true, key: 'ArrowRight' }));
    harness.fixture.detectChanges();
    expect(Number.parseFloat(article.style.width)).toBe(originalWidth + 16);

    (article.querySelector('.window-reset') as HTMLButtonElement).click();
    harness.fixture.detectChanges();
    expect(Number.parseFloat(article.style.left)).toBe(originalLeft);
    expect(Number.parseFloat(article.style.width)).toBe(originalWidth);
    expect(created.at(-1)).toBe(page);
    expect(destroyed).toEqual([]);
  });

  it('clears pointer cancel and capture loss without applying stale later pointer movement', async () => {
    await shell.navigation.open('os1', 'tasks');
    harness.fixture.detectChanges();
    const article = harness.fixture.nativeElement.querySelector('[data-testid="os-window-os1"]') as HTMLElement;
    const titlebar = article.querySelector('[data-testid="window-titlebar-os1"]') as HTMLElement;
    const layer = harness.fixture.nativeElement.querySelector('.window-layer') as HTMLElement;
    const left = Number.parseFloat(article.style.left);
    const top = Number.parseFloat(article.style.top);

    titlebar.dispatchEvent(pointer('pointerdown', 21, 100, 100));
    layer.dispatchEvent(pointer('pointermove', 21, 130, 120));
    layer.dispatchEvent(pointer('pointercancel', 21, 130, 120));
    layer.dispatchEvent(pointer('pointermove', 21, 300, 280));
    harness.fixture.detectChanges();
    expect(Number.parseFloat(article.style.left)).toBe(left + 30);
    expect(Number.parseFloat(article.style.top)).toBe(top + 20);

    titlebar.dispatchEvent(pointer('pointerdown', 22, 100, 100));
    layer.dispatchEvent(pointer('pointermove', 22, 140, 130));
    let lostCaptureEvents = 0;
    let lostPointerId = -1;
    titlebar.addEventListener('lostpointercapture', (event) => {
      lostCaptureEvents++;
      lostPointerId = (event as PointerEvent).pointerId;
    });
    titlebar.dispatchEvent(pointer('lostpointercapture', 22, 140, 130));
    expect(lostCaptureEvents).toBe(1);
    expect(lostPointerId).toBe(22);
    layer.dispatchEvent(pointer('pointermove', 22, 340, 330));
    harness.fixture.detectChanges();
    expect(Number.parseFloat(article.style.left)).toBe(left + 70);
    expect(Number.parseFloat(article.style.top)).toBe(top + 50);
  });

  it('preserves the first active pointer and removes its capture listener on completion and destroy', async () => {
    await shell.navigation.open('os1', 'tasks');
    harness.fixture.detectChanges();
    const article = harness.fixture.nativeElement.querySelector('[data-testid="os-window-os1"]') as HTMLElement;
    const titlebar = article.querySelector('[data-testid="window-titlebar-os1"]') as HTMLElement;
    const layer = harness.fixture.nativeElement.querySelector('.window-layer') as HTMLElement;
    const captured = vi.fn<(pointerId: number) => void>();
    const released = vi.fn<(pointerId: number) => void>();
    Object.defineProperty(titlebar, 'setPointerCapture', { configurable: true, value: captured });
    Object.defineProperty(titlebar, 'releasePointerCapture', { configurable: true, value: released });
    const addListener = vi.spyOn(titlebar, 'addEventListener');
    const removeListener = vi.spyOn(titlebar, 'removeEventListener');
    const lostCaptureAdds = (): number => addListener.mock.calls.filter(([type]) => type === 'lostpointercapture').length;
    const lostCaptureRemovals = (): number => removeListener.mock.calls.filter(([type]) => type === 'lostpointercapture').length;
    const left = Number.parseFloat(article.style.left);
    const top = Number.parseFloat(article.style.top);

    titlebar.dispatchEvent(pointer('pointerdown', 41, 100, 100));
    titlebar.dispatchEvent(pointer('pointerdown', 42, 200, 200));
    expect(captured.mock.calls.map(([id]) => id)).toEqual([41]);
    expect(lostCaptureAdds()).toBe(1);

    layer.dispatchEvent(pointer('pointermove', 41, 125, 120));
    layer.dispatchEvent(pointer('pointermove', 42, 500, 500));
    layer.dispatchEvent(pointer('pointerup', 41, 130, 130));
    harness.fixture.detectChanges();
    expect(Number.parseFloat(article.style.left)).toBe(left + 30);
    expect(Number.parseFloat(article.style.top)).toBe(top + 30);
    expect(released.mock.calls.map(([id]) => id)).toEqual([41]);
    expect(lostCaptureRemovals()).toBe(1);

    const completedLeft = article.style.left;
    titlebar.dispatchEvent(pointer('lostpointercapture', 41, 130, 130));
    layer.dispatchEvent(pointer('pointermove', 41, 600, 600));
    harness.fixture.detectChanges();
    expect(article.style.left).toBe(completedLeft);

    titlebar.dispatchEvent(pointer('pointerdown', 43, 100, 100));
    expect(lostCaptureAdds()).toBe(2);
    harness.fixture.destroy();
    expect(released.mock.calls.map(([id]) => id)).toEqual([41, 43]);
    expect(lostCaptureRemovals()).toBe(2);
  });

  it('does not change restore geometry through pointer or keyboard input while minimized or maximized', async () => {
    await shell.navigation.open('os1', 'tasks');
    harness.fixture.detectChanges();
    const article = harness.fixture.nativeElement.querySelector('[data-testid="os-window-os1"]') as HTMLElement;
    const titlebar = article.querySelector('[data-testid="window-titlebar-os1"]') as HTMLElement;
    const layer = harness.fixture.nativeElement.querySelector('.window-layer') as HTMLElement;
    const original = { left: article.style.left, top: article.style.top, width: article.style.width, height: article.style.height };

    await shell.navigation.minimize('os1');
    harness.fixture.detectChanges();
    titlebar.dispatchEvent(pointer('pointerdown', 31, 100, 100));
    layer.dispatchEvent(pointer('pointermove', 31, 300, 300));
    layer.dispatchEvent(pointer('pointerup', 31, 300, 300));
    titlebar.dispatchEvent(new KeyboardEvent('keydown', { bubbles: true, key: 'ArrowRight' }));
    await shell.navigation.restore('os1');
    harness.fixture.detectChanges();
    expect(article.style.left).toBe(original.left);
    expect(article.style.top).toBe(original.top);

    await shell.navigation.toggleMaximize('os1');
    harness.fixture.detectChanges();
    titlebar.dispatchEvent(pointer('pointerdown', 32, 100, 100));
    layer.dispatchEvent(pointer('pointermove', 32, 300, 300));
    layer.dispatchEvent(pointer('pointerup', 32, 300, 300));
    titlebar.dispatchEvent(new KeyboardEvent('keydown', { bubbles: true, key: 'ArrowDown' }));
    await shell.navigation.toggleMaximize('os1');
    harness.fixture.detectChanges();
    expect(article.style.left).toBe(original.left);
    expect(article.style.top).toBe(original.top);
    expect(article.style.width).toBe(original.width);
    expect(article.style.height).toBe(original.height);
  });
});
