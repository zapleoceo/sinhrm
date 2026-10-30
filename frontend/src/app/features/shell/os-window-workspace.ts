import { DOCUMENT } from '@angular/common';
import { ChangeDetectionStrategy, Component, DestroyRef, ElementRef, afterNextRender, inject, input, signal, viewChild } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { WindowSlot, WINDOW_SLOTS } from '../../core/windows/window-context';
import { WindowNavigation } from '../../core/windows/window-navigation';
import { AuthService } from '../../core/auth/auth.service';
import { WindowBounds, WindowGeometry, clampWindowGeometry, defaultWindowGeometry, moveWindowGeometry, resizeWindowGeometry } from './window-geometry';
import { WindowGeometryStore } from './window-geometry.store';

type GestureMode = 'move' | 'resize';

interface ActiveGesture {
  readonly pointerId: number;
  readonly slot: WindowSlot;
  readonly mode: GestureMode;
  readonly originX: number;
  readonly originY: number;
  readonly start: WindowGeometry;
  readonly target: HTMLElement;
  readonly lostCapture: EventListener;
}

/** Window chrome owns the named outlets while the shell continues to own the primary route. */
@Component({
  selector: 'app-os-window-workspace',
  imports: [RouterOutlet, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <section #layer class="window-layer" [attr.aria-label]="'osWindow.windows' | transloco"
             [style.left.px]="originX()" [style.width.px]="bounds().width"
             (focusin)="focusFromKeyboard($event)" (pointermove)="moveGesture($event)" (pointerup)="endGesture($event)" (pointercancel)="cancelGesture($event)">
      @let views = navigation.windows();
      @for (slot of slots; track slot) {
        @let view = views[slot];
        @let geometry = geometryFor(slot);
        <article class="os-window" [class.open]="view.open" [class.active]="view.active"
                 [class.minimized]="view.minimized" [class.maximized]="view.maximized"
                 [class.window-os1]="slot === 'os1'" [class.window-os2]="slot === 'os2'"
                 [attr.data-testid]="'os-window-' + slot"
                 [style.left.px]="view.maximized || view.minimized || narrow() ? null : geometry.x"
                 [style.top.px]="view.maximized || view.minimized || narrow() ? null : geometry.y"
                 [style.width.px]="view.maximized || view.minimized || narrow() ? null : geometry.width"
                 [style.height.px]="view.maximized || view.minimized || narrow() ? null : geometry.height"
                 [class.mobile-inactive]="narrow() && !view.active && !view.minimized"
                 [class.desktop-hidden]="navigation.primaryFocused() && !view.minimized"
                 [attr.aria-hidden]="!view.open || view.minimized || (narrow() && !view.active && !view.minimized) || (navigation.primaryFocused() && !view.minimized) ? 'true' : null"
                 [attr.inert]="view.open && (view.minimized || (narrow() && !view.active) || navigation.primaryFocused()) ? '' : null">
          @if (view.open) {
            <header class="window-titlebar" tabindex="0" [attr.aria-label]="'osWindow.move' | transloco" [attr.data-testid]="'window-titlebar-' + slot"
                    (pointerdown)="startMove($event, slot)" (click)="focusWindow(slot)"
                    (keydown.enter)="focusWindow(slot)" (keydown.space)="focusWindow(slot)"
                    (keydown)="moveByKeyboard($event, slot)">
              <div class="window-controls">
                <button type="button" class="window-control window-control-close" [attr.aria-label]="'osWindow.close' | transloco" [attr.title]="'osWindow.close' | transloco" (click)="$event.stopPropagation(); navigation.close(slot)"><svg aria-hidden="true" viewBox="0 0 10 10"><path d="m2 2 6 6M8 2 2 8" /></svg></button>
                @if (view.minimized) {
                  <button type="button" class="window-control window-control-restore" [attr.aria-label]="'osWindow.restore' | transloco" [attr.title]="'osWindow.restore' | transloco" (click)="$event.stopPropagation(); navigation.restore(slot)"><svg aria-hidden="true" viewBox="0 0 10 10"><path d="M3 6V3h4v4H4m-1-1v2h4V6" /></svg></button>
                } @else {
                  <button type="button" class="window-control window-control-minimize" [attr.aria-label]="'osWindow.minimize' | transloco" [attr.title]="'osWindow.minimize' | transloco" (click)="$event.stopPropagation(); navigation.minimize(slot)"><svg aria-hidden="true" viewBox="0 0 10 10"><path d="M2 7h6" /></svg></button>
                  <button type="button" class="window-control window-control-maximize" [attr.aria-label]="'osWindow.maximize' | transloco" [attr.title]="'osWindow.maximize' | transloco" (click)="$event.stopPropagation(); navigation.toggleMaximize(slot)"><svg aria-hidden="true" viewBox="0 0 10 10"><path d="M2.5 2.5h5v5h-5z" /></svg></button>
                }
              </div>
              <h2>{{ view.titleKey | transloco }}</h2>
              <div class="window-utilities">
                @if (!view.minimized && !view.maximized && !narrow()) {
                  <button type="button" class="window-reset" [attr.aria-label]="'osWindow.resetGeometry' | transloco" [attr.title]="'osWindow.resetGeometry' | transloco"
                          (click)="$event.stopPropagation(); resetGeometry(slot)"><svg aria-hidden="true" viewBox="0 0 16 16"><path d="M3 7V3h4M3 3l4 4m6 2v4H9m4 0L9 9" /></svg></button>
                }
              </div>
            </header>
          }
          @if (view.open && !view.minimized && !view.maximized && !narrow()) {
            <button class="window-resize" type="button" [attr.data-testid]="'window-resize-' + slot"
                    [attr.aria-label]="'osWindow.resize' | transloco"
                    (pointerdown)="startResize($event, slot)"
                    (keydown)="resizeByKeyboard($event, slot)">
              <span aria-hidden="true"></span>
            </button>
          }
          <div class="window-body" [hidden]="!view.open || view.minimized || (narrow() && !view.active)"
               [attr.inert]="!view.open || view.minimized || (narrow() && !view.active) ? '' : null"
               [attr.aria-hidden]="!view.open || view.minimized || (narrow() && !view.active) ? 'true' : null"
               (pointerdown)="focusWindow(slot)">
            <router-outlet [name]="slot" />
          </div>
        </article>
      }
    </section>
  `,
  styleUrl: './os-window-workspace.scss',
})
export class OsWindowWorkspace {
  private readonly document = inject(DOCUMENT);
  private readonly destroyRef = inject(DestroyRef);
  private readonly layer = viewChild.required<ElementRef<HTMLElement>>('layer');
  protected readonly navigation = inject(WindowNavigation);
  private readonly auth = inject(AuthService);
  private readonly geometryStore = inject(WindowGeometryStore);
  protected readonly slots = WINDOW_SLOTS;
  readonly narrow = input.required<boolean>();
  protected readonly bounds = signal<WindowBounds>({ width: 1280, height: 720 });
  private readonly geometries = signal<Partial<Record<WindowSlot, WindowGeometry>>>({});
  private restoredGeometryUser: number | null | undefined;
  protected readonly originX = signal(0);
  private readonly focusing = new Set<WindowSlot>();
  private gesture: ActiveGesture | null = null;

  constructor() {
    afterNextRender(() => {
      const layer = this.layer().nativeElement;
      const content = layer.parentElement;
      const view = this.document.defaultView;
      const measure = (): void => {
        const contentRect = content?.getBoundingClientRect();
        const layerRect = layer.getBoundingClientRect();
        const width = content?.clientWidth || view?.innerWidth || layer.clientWidth || 1280;
        const nextBounds = {
          width,
          height: layer.clientHeight || Math.max(0, (view?.innerHeight ?? 832) - 112),
        };
        this.originX.set(contentRect?.left ?? layerRect.left);
        this.bounds.set(nextBounds);
        const userId = this.auth.user()?.id ?? null;
        const stored = userId !== this.restoredGeometryUser && userId !== null ? this.geometryStore.read(userId) : {};
        if (userId !== this.restoredGeometryUser) this.restoredGeometryUser = userId;
        this.geometries.update((current) => {
          const next: Partial<Record<WindowSlot, WindowGeometry>> = {};
          for (const slot of this.slots) {
            next[slot] = clampWindowGeometry(stored[slot] ?? current[slot] ?? defaultWindowGeometry(slot, nextBounds), nextBounds);
          }
          return next;
        });
      };
      measure();
      const ResizeObserverCtor = view?.ResizeObserver;
      const observer = ResizeObserverCtor ? new ResizeObserverCtor(measure) : null;
      if (content) observer?.observe(content);
      view?.addEventListener('resize', measure);
      view?.addEventListener('scroll', measure, true);
      this.destroyRef.onDestroy(() => {
        observer?.disconnect();
        view?.removeEventListener('resize', measure);
        view?.removeEventListener('scroll', measure, true);
        if (this.gesture) this.clearGesture(this.gesture, true);
      });
    });
  }

  protected geometryFor(slot: WindowSlot): WindowGeometry {
    return this.geometries()[slot] ?? defaultWindowGeometry(slot, this.bounds());
  }

  protected focusFromKeyboard(event: FocusEvent): void {
    const target = event.target;
    if (!(target instanceof Element)) return;
    const article = target.closest<HTMLElement>('article.os-window');
    if (!article || !this.layer().nativeElement.contains(article)
      || !article.classList.contains('open')
      || article.classList.contains('minimized')
      || article.inert
      || article.getAttribute('aria-hidden') === 'true') return;
    const testId = article.dataset['testid'];
    const slot = this.slots.find((candidate) => testId === `os-window-${candidate}`);
    if (slot) this.focusWindow(slot);
  }

  protected focusWindow(slot: WindowSlot): void {
    if (this.navigation.activeSlot() === slot || this.focusing.has(slot)) return;
    this.focusing.add(slot);
    void this.navigation.focus(slot).finally(() => this.focusing.delete(slot));
  }

  protected startMove(event: PointerEvent, slot: WindowSlot): void {
    if (this.narrow() || !this.canEditGeometry(slot) || event.button !== 0 || (event.target instanceof Element && event.target.closest('button'))) return;
    this.startGesture(event, slot, 'move');
  }

  protected startResize(event: PointerEvent, slot: WindowSlot): void {
    if (this.narrow() || !this.canEditGeometry(slot) || event.button !== 0) return;
    event.stopPropagation();
    this.startGesture(event, slot, 'resize');
  }

  protected moveGesture(event: PointerEvent): void {
    const gesture = this.gesture;
    if (!gesture || gesture.pointerId !== event.pointerId) return;
    if (!this.canEditGeometry(gesture.slot)) {
      this.clearGesture(gesture, true);
      return;
    }
    const deltaX = event.clientX - gesture.originX;
    const deltaY = event.clientY - gesture.originY;
    const next = gesture.mode === 'move'
      ? moveWindowGeometry(gesture.start, this.bounds(), deltaX, deltaY)
      : resizeWindowGeometry(gesture.start, this.bounds(), deltaX, deltaY);
    this.geometries.update((current) => ({ ...current, [gesture.slot]: next }));
  }

  protected endGesture(event: PointerEvent): void {
    const gesture = this.gesture;
    if (!gesture || gesture.pointerId !== event.pointerId) return;
    if (this.canEditGeometry(gesture.slot)) this.applyGesturePosition(gesture, event.clientX, event.clientY);
    this.clearGesture(gesture, true);
    if (this.canEditGeometry(gesture.slot)) this.persistGeometry(gesture.slot);
  }

  protected cancelGesture(event: PointerEvent): void {
    const gesture = this.gesture;
    if (!gesture || gesture.pointerId !== event.pointerId) return;
    this.clearGesture(gesture, event.type !== 'lostpointercapture');
  }

  protected moveByKeyboard(event: KeyboardEvent, slot: WindowSlot): void {
    if (this.narrow() || !this.canEditGeometry(slot) || event.target !== event.currentTarget) return;
    const delta = this.arrowDelta(event);
    if (!delta) return;
    event.preventDefault();
    const current = this.geometryFor(slot);
    this.geometries.update((state) => ({ ...state, [slot]: moveWindowGeometry(current, this.bounds(), delta.x, delta.y) }));
    this.persistGeometry(slot);
  }

  protected resizeByKeyboard(event: KeyboardEvent, slot: WindowSlot): void {
    if (this.narrow() || !this.canEditGeometry(slot)) return;
    const delta = this.arrowDelta(event);
    if (!delta) return;
    event.preventDefault();
    event.stopPropagation();
    const current = this.geometryFor(slot);
    this.geometries.update((state) => ({ ...state, [slot]: resizeWindowGeometry(current, this.bounds(), delta.x, delta.y) }));
    this.persistGeometry(slot);
  }

  protected resetGeometry(slot: WindowSlot): void {
    if (!this.canEditGeometry(slot)) return;
    this.geometries.update((state) => ({ ...state, [slot]: defaultWindowGeometry(slot, this.bounds()) }));
    this.persistGeometry(slot);
  }

  private applyGesturePosition(gesture: ActiveGesture, clientX: number, clientY: number): void {
    const deltaX = clientX - gesture.originX;
    const deltaY = clientY - gesture.originY;
    const next = gesture.mode === 'move'
      ? moveWindowGeometry(gesture.start, this.bounds(), deltaX, deltaY)
      : resizeWindowGeometry(gesture.start, this.bounds(), deltaX, deltaY);
    this.geometries.update((current) => ({ ...current, [gesture.slot]: next }));
  }

  private clearGesture(gesture: ActiveGesture, releaseCapture: boolean): void {
    this.gesture = null;
    gesture.target.removeEventListener('lostpointercapture', gesture.lostCapture);
    if (!releaseCapture) return;
    try {
      gesture.target.releasePointerCapture(gesture.pointerId);
    } catch {
      // The pointer may already have been released by the browser.
    }
  }

  private startGesture(event: PointerEvent, slot: WindowSlot, mode: GestureMode): void {
    if (this.gesture) return;
    event.preventDefault();
    this.focusWindow(slot);
    const target = event.currentTarget as HTMLElement;
    const lostCapture: EventListener = (captureEvent) => this.cancelGesture(captureEvent as PointerEvent);
    this.gesture = {
      pointerId: event.pointerId,
      slot,
      mode,
      originX: event.clientX,
      originY: event.clientY,
      start: this.geometryFor(slot),
      target,
      lostCapture,
    };
    target.addEventListener('lostpointercapture', lostCapture);
    try {
      target.setPointerCapture(event.pointerId);
    } catch {
      // Synthetic events and browsers without pointer capture still use the workspace pointer listeners.
    }
  }

  private canEditGeometry(slot: WindowSlot): boolean {
    const view = this.navigation.windows()[slot];
    return view.open && !view.minimized && !view.maximized;
  }

  private persistGeometry(slot: WindowSlot): void {
    const userId = this.auth.user()?.id;
    if (userId !== undefined) this.geometryStore.save(userId, slot, this.geometryFor(slot));
  }

  private arrowDelta(event: KeyboardEvent): { x: number; y: number } | null {
    const step = event.shiftKey ? 48 : 16;
    switch (event.key) {
      case 'ArrowLeft': return { x: -step, y: 0 };
      case 'ArrowRight': return { x: step, y: 0 };
      case 'ArrowUp': return { x: 0, y: -step };
      case 'ArrowDown': return { x: 0, y: step };
      default: return null;
    }
  }
}
