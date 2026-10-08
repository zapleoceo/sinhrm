import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  ElementRef,
  afterNextRender,
  computed,
  effect,
  inject,
  input,
  signal,
  untracked,
  viewChild,
} from '@angular/core';
import {
  MatAutocompleteModule,
  MatAutocompleteSelectedEvent,
} from '@angular/material/autocomplete';
import { MatButtonModule } from '@angular/material/button';
import { MatButtonToggleModule } from '@angular/material/button-toggle';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSelectModule } from '@angular/material/select';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { MatTooltipModule } from '@angular/material/tooltip';
import { RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { countNodes, expandedToDepth, initials } from '../org-tree';
import { OrgNode } from '../people.model';
import { PeopleService } from '../people.service';
import {
  CARD_H,
  CARD_W,
  LaidNode,
  Orientation,
  Transform,
  centerOn,
  departmentHue,
  distinctRefs,
  filterForest,
  findNode,
  fitTransform,
  layoutForest,
  neighbour,
  pathTo,
  searchPeople,
  visibleNodes,
  zoomAt,
} from './org-layout';
import { OrgChartAction, OrgChartControls, OrgChartLegend } from './org-chart-controls';
import { OrgPersonPanel } from './org-person-panel';
import { withMember } from '../../../core/ui/with-member';
import { NotifyService } from '../../../core/ui/notify.service';
import { exportOrgChart, readExportColors } from './org-export';
import { orgKeyCommand, outsideViewport } from './org-keys';


/**
 * Interactive org chart: tidy tree (d3-hierarchy layout, own HTML/SVG rendering), pan & zoom (wheel, drag, pinch),
 * expand/collapse, search with path highlight, branch/department filters, side panel, keyboard navigation,
 * PNG/SVG export. Only cards inside the viewport are rendered, so 300+ people stay smooth.
 * ?root=<id> shows one subtree (from a profile); "My team" asks the API for the caller's own subtree.
 */
@Component({
  selector: 'app-org-chart-page',
  imports: [
    MatAutocompleteModule,
    MatButtonModule,
    MatButtonToggleModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatProgressBarModule,
    MatSelectModule,
    MatSlideToggleModule,
    MatTooltipModule,
    OrgChartControls,
    OrgChartLegend,
    OrgPersonPanel,
    RouterLink,
    TranslocoPipe,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="page-head">
      <div>
        <h1>{{ 'people.orgChart.title' | transloco }}</h1>
        <p class="muted">{{ 'people.orgChart.subtitle' | transloco: { n: total() } }}</p>
      </div>
      <a mat-stroked-button routerLink="/people"
        ><mat-icon>groups</mat-icon>{{ 'people.directory.title' | transloco }}</a
      >
    </header>

    <div class="toolbar">
      <mat-form-field class="search" subscriptSizing="dynamic">
        <mat-icon matPrefix>search</mat-icon>
        <input
          matInput
          type="search"
          #q
          [placeholder]="'people.orgChart.find' | transloco"
          [value]="term()"
          (input)="term.set(q.value)"
          [matAutocomplete]="auto"
        />
        <mat-autocomplete #auto (optionSelected)="pick($event)">
          @for (m of matches(); track m.id) {
            <mat-option [value]="m.id">
              {{ m.full_name }}
              <small class="muted">· {{ m.position?.name }}</small>
            </mat-option>
          }
        </mat-autocomplete>
      </mat-form-field>

      @if (branches().length > 1) {
        <mat-form-field subscriptSizing="dynamic" class="sel">
          <mat-select
            [value]="branchId()"
            (valueChange)="branchId.set($event)"
            [placeholder]="'people.orgChart.allBranches' | transloco"
          >
            <mat-option [value]="null">{{ 'people.orgChart.allBranches' | transloco }}</mat-option>
            @for (b of branches(); track b.id) {
              <mat-option [value]="b.id">{{ b.name }}</mat-option>
            }
          </mat-select>
        </mat-form-field>
      }
      @if (departments().length > 1) {
        <mat-form-field subscriptSizing="dynamic" class="sel">
          <mat-select
            [value]="departmentId()"
            (valueChange)="departmentId.set($event)"
            [placeholder]="'people.orgChart.allDepartments' | transloco"
          >
            <mat-option [value]="null">{{
              'people.orgChart.allDepartments' | transloco
            }}</mat-option>
            @for (d of departments(); track d.id) {
              <mat-option [value]="d.id">{{ d.name }}</mat-option>
            }
          </mat-select>
        </mat-form-field>
      }

      <mat-slide-toggle [checked]="mine()" (change)="mine.set($event.checked)">{{
        'people.orgChart.myTeam' | transloco
      }}</mat-slide-toggle>
      @if (root()) {
        <a mat-button routerLink="/people/org-chart">{{ 'people.orgChart.all' | transloco }}</a>
      }
    </div>

    <section class="stage-wrap" [class.with-panel]="selected()">
      <div
        #viewport
        class="viewport"
        tabindex="-1"
        [class.dragging]="dragging()"
        role="tree"
        [attr.aria-label]="'people.orgChart.title' | transloco"
        (wheel)="onWheel($event)"
        (pointerdown)="onPointerDown($event)"
        (pointermove)="onPointerMove($event)"
        (pointerup)="onPointerUp($event)"
        (pointercancel)="onPointerUp($event)"
        (keydown)="onKey($event)"
      >
        @if (loading()) {
          <mat-progress-bar class="loading" mode="indeterminate" />
        }
        @if (failed()) {
          <div class="state">
            <p>{{ 'people.loadError' | transloco }}</p>
            <button mat-stroked-button type="button" (click)="load()">
              {{ 'common.retry' | transloco }}
            </button>
          </div>
        } @else if (!loading() && layout().nodes.length === 0) {
          <p class="state muted">{{ 'people.orgChart.empty' | transloco }}</p>
        }

        <div class="stage" [class.smooth]="smooth()" [style.transform]="stageTransform()">
          <svg class="links" aria-hidden="true" [attr.width]="1" [attr.height]="1">
            @for (l of layout().links; track l.to) {
              <path [attr.d]="l.d" [class.on-path]="path().has(l.to)" />
            }
          </svg>
          @for (n of rendered(); track n.node.id) {
            <div
              class="card"
              role="treeitem"
              [id]="'org-node-' + n.node.id"
              [style.left.px]="n.x"
              [style.top.px]="n.y"
              [style.--h]="hueOf(n)"
              [class.on-path]="path().has(n.node.id)"
              [class.hit]="hitId() === n.node.id"
              [class.me]="myId() === n.node.id"
              [class.selected]="selected()?.id === n.node.id"
              [attr.tabindex]="focusId() === n.node.id ? 0 : -1"
              [attr.aria-level]="n.depth + 1"
              [attr.aria-posinset]="n.pos"
              [attr.aria-setsize]="n.size"
              [attr.aria-expanded]="n.hasChildren ? n.open : null"
              [attr.aria-selected]="selected()?.id === n.node.id"
              [attr.aria-label]="ariaLabel(n)"
              (click)="select(n.node.id)"
              (keydown.enter)="select(n.node.id)"
              (focus)="focusId.set(n.node.id)"
            >
              <span class="avatar" aria-hidden="true">
                @if (n.node.avatar_url; as src) {
                  <img [src]="src" alt="" loading="lazy" />
                } @else {
                  {{ initialsOf(n.node.full_name) }}
                }
              </span>
              <span class="text">
                <span class="name">{{ n.node.full_name }}</span>
                <span class="pos">{{ n.node.position?.name ?? '—' }}</span>
                @if (n.node.department; as d) {
                  <span class="dept">{{ d.name }}</span>
                }
              </span>
              @if (n.headcount) {
                <span
                  class="count"
                  [matTooltip]="'people.orgChart.headcount' | transloco: { n: n.headcount }"
                >
                  <mat-icon>groups</mat-icon>{{ n.headcount }}
                </span>
              }
              @if (n.hasChildren) {
                <button
                  type="button"
                  class="toggle"
                  tabindex="-1"
                  [class.open]="n.open"
                  [attr.aria-label]="'people.orgChart.toggle' | transloco"
                  (click)="toggle(n.node.id); $event.stopPropagation()"
                  (pointerdown)="$event.stopPropagation()"
                >
                  @if (n.open) {
                    <mat-icon>remove</mat-icon>
                  } @else {
                    {{ n.node.reports.length }}
                  }
                </button>
              }
            </div>
          }
        </div>

        @if (legend().length) {
          <app-org-chart-legend
            [items]="legend()"
            [active]="departmentId()"
            (picked)="toggleDepartment($event)"
            (pointerdown)="$event.stopPropagation()"
          />
        }

        <app-org-chart-controls
          [orientation]="orientation()"
          (action)="onControl($event)"
          (pointerdown)="$event.stopPropagation()"
        />
      </div>

      @if (selected(); as s) {
        <app-org-person-panel
          [node]="s"
          [manager]="managerOf(s.id)"
          (closed)="closePanel()"
          (navigate)="locate($event)"
        />
      }
    </section>
    <p class="hint muted">{{ 'people.orgChart.keyboardHint' | transloco }}</p>
  `,
  styles: `
    :host {
      display: flex;
      flex-direction: column;
      min-height: 0;
    }
    .toolbar {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 0.75rem;
      margin-bottom: 0.75rem;
    }
    .search {
      flex: 1 1 16rem;
    }
    .sel {
      width: 12rem;
    }
    .stage-wrap {
      position: relative;
      display: flex;
      height: calc(100dvh - 15rem);
      min-height: 26rem;
      border: var(--app-border-w) solid var(--app-border);
      border-radius: var(--app-radius);
      overflow: hidden;
      background:
        radial-gradient(circle, var(--app-track) 1px, transparent 1.2px) 0 0 / 22px 22px,
        var(--app-canvas);
    }
    .viewport {
      position: relative;
      flex: 1;
      overflow: hidden;
      touch-action: none;
      cursor: grab;
      outline: none;
      user-select: none;
    }
    .viewport.dragging {
      cursor: grabbing;
    }
    .loading {
      position: absolute;
      inset: 0 0 auto;
      z-index: 3;
    }
    .state {
      position: absolute;
      inset: 40% 0 auto;
      text-align: center;
    }
    .stage {
      position: absolute;
      left: 0;
      top: 0;
      transform-origin: 0 0;
      will-change: transform;
    }
    .stage.smooth {
      transition: transform 450ms cubic-bezier(0.2, 0.8, 0.2, 1);
    }
    .links {
      position: absolute;
      left: 0;
      top: 0;
      overflow: visible;
    }
    .links path {
      fill: none;
      stroke: var(--app-muted);
      stroke-opacity: 0.45;
      stroke-width: 1.5;
      transition: stroke 200ms;
    }
    .links path.on-path {
      stroke: var(--app-accent);
      stroke-opacity: 1;
      stroke-width: 3;
    }

    .card {
      --dept: light-dark(hsl(var(--h, 215) 58% 42%), hsl(var(--h, 215) 70% 70%));
      position: absolute;
      width: 232px;
      height: 84px; /* CARD_W × CARD_H */
      box-sizing: border-box;
      display: flex;
      align-items: center;
      gap: 0.7rem;
      padding: 0 0.9rem 0 1rem;
      cursor: pointer;
      border-radius: var(--app-radius);
      border: var(--app-border-w) solid var(--app-border);
      background: var(--app-card);
      transition:
        left 350ms cubic-bezier(0.2, 0.8, 0.2, 1),
        top 350ms cubic-bezier(0.2, 0.8, 0.2, 1),
        transform var(--app-fast),
        border-color var(--app-fast);
      animation: pop 260ms ease-out;
    }
    .card::before {
      content: '';
      position: absolute;
      left: -1.5px;
      top: 16px;
      bottom: 16px;
      width: 4px;
      border-radius: 0 2px 2px 0;
      background: var(--dept);
    }
    .card:hover {
      transform: translateY(-1px);
      border-color: var(--dept);
    }
    .card:focus-visible {
      outline: 2px solid var(--app-focus-ring);
      outline-offset: 2px;
    }
    .card.on-path {
      border-color: var(--app-accent);
    }
    .card.selected {
      border-color: var(--mat-sys-primary);
      box-shadow: inset 0 0 0 1px var(--mat-sys-primary); /* 2.5px line, not a shadow */
    }
    /* Search hit: a static teal ring + the one «pop» (no pulsing glow — effects budget). */
    .card.hit {
      outline: 2px dashed var(--app-accent);
      outline-offset: 3px;
    }
    .card.me .avatar {
      box-shadow:
        0 0 0 2px var(--app-card),
        0 0 0 4px var(--app-accent);
    }
    .avatar {
      display: grid;
      place-items: center;
      width: 2.6rem;
      height: 2.6rem;
      box-sizing: border-box;
      border-radius: 50%;
      flex: none;
      overflow: hidden;
      font: 700 0.8rem/1 var(--app-font-text);
      border: 2.5px solid var(--dept);
      background: var(--app-card);
      color: var(--dept);
    }
    .avatar img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }
    .text {
      display: flex;
      flex-direction: column;
      min-width: 0;
      flex: 1;
      line-height: 1.25;
    }
    .text > span {
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .name {
      font-weight: 700;
      font-size: 0.875rem;
    }
    .pos {
      font-size: 0.8rem;
      color: var(--app-muted);
    }
    .dept {
      font-size: 0.72rem;
      color: var(--dept);
    }
    .count {
      position: absolute;
      top: 6px;
      right: 8px;
      display: inline-flex;
      align-items: center;
      gap: 2px;
      font: 500 0.7rem/1 var(--app-font-mono);
      color: var(--app-muted);
    }
    .count mat-icon {
      width: 14px;
      height: 14px;
      font-size: 14px;
    }
    .toggle {
      position: absolute;
      left: 50%;
      bottom: -12px;
      translate: -50% 0;
      min-width: 24px;
      height: 24px;
      padding: 0 6px;
      display: inline-grid;
      place-items: center;
      border-radius: 999px;
      cursor: pointer;
      font: 600 0.72rem/1 inherit;
      border: var(--app-border-w) solid var(--app-border);
      background: var(--app-card);
      color: var(--app-muted);
      font-family: var(--app-font-mono);
    }
    /* 24px visual, 44px hit area for touch. */
    .toggle::after {
      content: '';
      position: absolute;
      inset: -10px;
    }
    .toggle:hover {
      color: var(--app-brand-text);
      border-color: var(--mat-sys-primary);
    }
    .toggle mat-icon {
      width: 16px;
      height: 16px;
      font-size: 16px;
    }
    :host(.horizontal) .toggle {
      left: auto;
      right: -12px;
      bottom: auto;
      top: 50%;
      translate: 0 -50%;
    }

    .hint {
      margin: 0.5rem 0 0;
      font-size: 0.75rem;
    }

    @keyframes pop {
      from {
        opacity: 0;
        transform: scale(0.94);
      }
    }

    @media (max-width: 720px) {
      .sel {
        width: calc(50% - 0.4rem);
      }
      .stage-wrap {
        height: calc(100dvh - 17rem);
      }
      .hint {
        display: none;
      }
    }
    @media (prefers-reduced-motion: reduce) {
      .card,
      .stage.smooth {
        transition: none;
        animation: none;
      }
    }
  `,
  host: { '[class.horizontal]': 'orientation() === "horizontal"' },
})
export class OrgChartPage {
  /** ?root=<employee id> (withComponentInputBinding). */
  readonly root = input<string | undefined>(undefined);

  private readonly api = inject(PeopleService);
  private readonly notify = inject(NotifyService);
  private readonly i18n = inject(TranslocoService);
  private readonly viewportRef = viewChild.required<ElementRef<HTMLElement>>('viewport');

  protected readonly nodes = signal<OrgNode[]>([]);
  protected readonly loading = signal(false);
  protected readonly failed = signal(false);
  protected readonly term = signal('');
  protected readonly mine = signal(false);
  protected readonly branchId = signal<number | null>(null);
  protected readonly departmentId = signal<number | null>(null);
  protected readonly open = signal(new Set<number>());
  protected readonly orientation = signal<Orientation>('vertical');
  protected readonly transform = signal<Transform>({ x: 0, y: 0, k: 1 });
  protected readonly size = signal({ w: 0, h: 0 });
  protected readonly smooth = signal(false);
  protected readonly dragging = signal(false);
  protected readonly selectedId = signal<number | null>(null);
  protected readonly focusId = signal<number | null>(null);
  protected readonly hitId = signal<number | null>(null);
  protected readonly myId = signal<number | null>(null);
  protected readonly path = signal(new Set<number>());

  protected readonly forest = computed(() =>
    filterForest(this.nodes(), { branchId: this.branchId(), departmentId: this.departmentId() }),
  );
  protected readonly layout = computed(() =>
    layoutForest(this.forest(), this.open(), this.orientation()),
  );
  protected readonly rendered = computed(() => {
    const { w, h } = this.size();
    return visibleNodes(this.layout().nodes, this.transform(), w, h);
  });
  protected readonly total = computed(() => countNodes(this.nodes()));
  protected readonly matches = computed(() => searchPeople(this.forest(), this.term()));
  protected readonly branches = computed(() => distinctRefs(this.nodes(), 'branch'));
  protected readonly departments = computed(() => distinctRefs(this.nodes(), 'department'));
  protected readonly legend = computed(() =>
    distinctRefs(this.forest(), 'department').map((d) => ({ ...d, hue: departmentHue(d.id) })),
  );
  protected readonly selected = computed(() => {
    const id = this.selectedId();
    return id === null ? null : findNode(this.nodes(), id);
  });
  protected readonly stageTransform = computed(() => {
    const t = this.transform();
    return `translate(${t.x}px, ${t.y}px) scale(${t.k})`;
  });

  private readonly pointers = new Map<number, { x: number; y: number }>();
  private dragMoved = false;
  private smoothTimer: ReturnType<typeof setTimeout> | undefined;
  private readonly pendingFit = signal(false);

  constructor() {
    effect(() => {
      this.mine();
      this.root();
      this.load();
    });
    // Filters change the tree shape: refit.
    effect(() => {
      this.branchId();
      this.departmentId();
      this.pendingFit.set(true);
    });
    // Fit once the viewport has a size and the tree is laid out (data and ResizeObserver arrive in any order).
    effect(() => {
      this.size(); // re-run when the viewport gets its size
      if (this.pendingFit() && this.layout().nodes.length > 0) {
        untracked(() => this.fit(false));
      }
    });
    afterNextRender(() => {
      const el = this.viewportRef().nativeElement;
      const ro = new ResizeObserver(() => {
        this.size.set({ w: el.clientWidth, h: el.clientHeight });
      });
      ro.observe(el);
      this.destroyRef.onDestroy(() => ro.disconnect());
    });
  }

  private readonly destroyRef = inject(DestroyRef);

  load(): void {
    this.loading.set(true);
    this.failed.set(false);
    const root = this.root();
    this.api.orgChart({ mine: this.mine(), root_id: root ? Number(root) : undefined }).subscribe({
      next: (nodes) => {
        this.nodes.set(nodes);
        // Two levels visible by default (roots open): deep branches stay collapsed so big companies render fast.
        this.open.set(expandedToDepth(nodes, 0));
        this.focusId.set(nodes[0]?.id ?? null);
        this.loading.set(false);
        this.pendingFit.set(true);
      },
      error: () => {
        this.failed.set(true);
        this.loading.set(false);
      },
    });
  }

  /** Current viewport size, read from the element (ResizeObserver can lag while the tab is hidden). */
  private measure(): { w: number; h: number } {
    const el = this.viewportRef().nativeElement;
    const size = { w: el.clientWidth, h: el.clientHeight };
    const cur = this.size();
    if (cur.w !== size.w || cur.h !== size.h) {
      this.size.set(size);
    }
    return size;
  }

  // ---- view -------------------------------------------------------------------------------------------------

  protected fit(animate = true): void {
    const { w, h } = this.measure();
    if (w === 0) {
      this.pendingFit.set(true);
      return;
    }
    this.pendingFit.set(false);
    this.move(fitTransform(this.layout().bounds, w, h), animate);
  }

  protected zoomBy(factor: number): void {
    const { w, h } = this.measure();
    this.move(zoomAt(this.transform(), factor, w / 2, h / 2), true);
  }

  protected setOrientation(o: Orientation): void {
    this.orientation.set(o);
    this.fit();
  }

  private move(t: Transform, animate: boolean): void {
    clearTimeout(this.smoothTimer);
    this.smooth.set(animate);
    this.transform.set(t);
    if (animate) {
      this.smoothTimer = setTimeout(() => this.smooth.set(false), 480);
    }
  }

  /** Pans so a node is centred (keeps zoom, but not smaller than 0.7 so the card is readable). */
  private panTo(id: number): void {
    const n = this.layout().nodes.find((x) => x.node.id === id);
    if (!n) {
      return;
    }
    const { w, h } = this.measure();
    const k = Math.max(this.transform().k, 0.7);
    this.move(centerOn(n.x + CARD_W / 2, n.y + CARD_H / 2, w, h, k), true);
  }

  protected onControl(a: OrgChartAction): void {
    switch (a) {
      case 'zoomIn':
        return this.zoomBy(1.25);
      case 'zoomOut':
        return this.zoomBy(0.8);
      case 'fit':
        return this.fit();
      case 'orientation':
        return this.setOrientation(this.orientation() === 'vertical' ? 'horizontal' : 'vertical');
      case 'expand':
        return this.expandAll();
      case 'collapse':
        return this.collapseAll();
      case 'me':
        return this.showMe();
      default:
        return this.exportAs(a);
    }
  }

  // ---- pointer: drag to pan, pinch/wheel to zoom ----------------------------------------------------------

  protected onWheel(e: WheelEvent): void {
    e.preventDefault();
    const rect = this.viewportRef().nativeElement.getBoundingClientRect();
    const factor = Math.exp(-e.deltaY * (e.ctrlKey ? 0.01 : 0.0015));
    this.move(zoomAt(this.transform(), factor, e.clientX - rect.left, e.clientY - rect.top), false);
  }

  protected onPointerDown(e: PointerEvent): void {
    if (e.button !== 0) {
      return;
    }
    this.pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
    this.dragMoved = false;
  }

  protected onPointerMove(e: PointerEvent): void {
    const prev = this.pointers.get(e.pointerId);
    if (!prev) {
      return;
    }
    const cur = { x: e.clientX, y: e.clientY };
    if (this.pointers.size === 2) {
      const [a, b] = [...this.pointers.entries()];
      const other = a[0] === e.pointerId ? b[1] : a[1];
      const before = Math.hypot(prev.x - other.x, prev.y - other.y);
      const after = Math.hypot(cur.x - other.x, cur.y - other.y);
      const rect = this.viewportRef().nativeElement.getBoundingClientRect();
      if (before > 0) {
        this.move(
          zoomAt(
            this.transform(),
            after / before,
            (cur.x + other.x) / 2 - rect.left,
            (cur.y + other.y) / 2 - rect.top,
          ),
          false,
        );
      }
      this.dragMoved = true;
    } else {
      const dx = cur.x - prev.x;
      const dy = cur.y - prev.y;
      if (!this.dragMoved && Math.hypot(dx, dy) < 4) {
        return; // a click, not a drag
      }
      if (!this.dragMoved) {
        this.dragMoved = true;
        this.dragging.set(true);
        this.viewportRef().nativeElement.setPointerCapture(e.pointerId);
      }
      const t = this.transform();
      this.move({ ...t, x: t.x + dx, y: t.y + dy }, false);
    }
    this.pointers.set(e.pointerId, cur);
  }

  protected onPointerUp(e: PointerEvent): void {
    this.pointers.delete(e.pointerId);
    if (this.pointers.size === 0) {
      this.dragging.set(false);
    }
  }

  // ---- tree actions -------------------------------------------------------------------------------------------

  protected toggle(id: number): void {
    this.open.update((s) => withMember(s, id, !s.has(id)));
  }

  protected expandAll(): void {
    this.open.set(expandedToDepth(this.nodes(), Number.MAX_SAFE_INTEGER));
    this.pendingFit.set(true);
  }

  protected collapseAll(): void {
    this.open.set(new Set());
    this.pendingFit.set(true);
  }

  protected select(id: number): void {
    if (this.dragMoved) {
      return; // end of a drag that started on a card
    }
    this.selectedId.set(id);
    this.focusId.set(id);
  }

  protected closePanel(): void {
    const id = this.selectedId();
    this.selectedId.set(null);
    if (id !== null) {
      this.focusNode(id);
    }
  }

  protected toggleDepartment(id: number): void {
    this.departmentId.update((cur) => (cur === id ? null : id));
  }

  /** Opens every manager above `id`, highlights the path to the root, pans there and selects the person. */
  protected locate(id: number): void {
    let ids = pathTo(this.forest(), id);
    if (ids.length === 0 && (this.branchId() !== null || this.departmentId() !== null)) {
      // Hidden by a filter: drop filters so the person can be shown.
      this.branchId.set(null);
      this.departmentId.set(null);
      ids = pathTo(this.nodes(), id);
    }
    if (ids.length === 0) {
      this.notify.show('people.orgChart.notInChart', { duration: 3000 });
      return;
    }
    this.open.update((s) => new Set([...s, ...ids.slice(0, -1)]));
    this.path.set(new Set(ids));
    this.selectedId.set(id);
    this.focusId.set(id);
    this.hitId.set(id);
    setTimeout(() => this.hitId.set(null), 2600);
    // Wait for the panel to open (the viewport narrows) before centring.
    setTimeout(() => this.panTo(id), 60);
  }

  protected pick(e: MatAutocompleteSelectedEvent): void {
    const id = Number(e.option.value);
    this.term.set('');
    this.locate(id);
  }

  protected showMe(): void {
    const known = this.myId();
    if (known !== null) {
      this.locate(known);
      return;
    }
    this.api.me().subscribe({
      next: (me) => {
        this.myId.set(me.id);
        this.locate(me.id);
      },
      error: () =>
        this.notify.show('people.orgChart.notInChart', { duration: 3000 }),
    });
  }

  protected managerOf(id: number): OrgNode | null {
    const ids = pathTo(this.nodes(), id);
    return ids.length > 1 ? findNode(this.nodes(), ids[ids.length - 2]) : null;
  }

  // ---- keyboard -----------------------------------------------------------------------------------------------

  protected onKey(e: KeyboardEvent): void {
    const target = e.target as HTMLElement;
    if (!target.classList.contains('card') && target !== this.viewportRef().nativeElement) {
      return; // keys inside controls/legend keep their native behaviour
    }
    const current = this.focusId() ?? this.layout().nodes[0]?.node.id ?? null;
    if (current === null) {
      return;
    }
    const action = orgKeyCommand(e.key);
    if (!action) {
      return;
    }
    if (action.prevent) {
      e.preventDefault();
    }
    const { command } = action;
    switch (command.kind) {
      case 'move': {
        const next = neighbour(this.layout(), current, command.key, this.orientation());
        if (next !== null) {
          this.focusNode(next);
        }
        return;
      }
      case 'select':
        return this.selectedId.set(current);
      case 'toggle':
        return this.toggle(current);
      case 'zoom':
        return this.zoomBy(command.factor);
      case 'fit':
        return this.fit();
    }
  }

  /** Moves keyboard focus to a card, panning first when it is outside the viewport (cards there aren't rendered). */
  private focusNode(id: number): void {
    this.focusId.set(id);
    const n = this.layout().nodes.find((x) => x.node.id === id);
    if (!n) {
      return;
    }
    const { w, h } = this.measure();
    if (outsideViewport(n, this.transform(), w, h)) {
      this.panTo(id);
    }
    setTimeout(() => document.getElementById(`org-node-${id}`)?.focus({ preventScroll: true }));
  }

  // ---- export -------------------------------------------------------------------------------------------------

  /** PNG or SVG of the whole chart in the colours on screen (org-export.ts). */
  protected exportAs(kind: 'png' | 'svg'): void {
    exportOrgChart(this.layout(), readExportColors(this.viewportRef().nativeElement), kind);
  }

  // ---- template helpers -----------------------------------------------------------------------------------

  protected hueOf(n: LaidNode): number | null {
    return departmentHue(n.node.department?.id);
  }

  protected initialsOf(name: string): string {
    return initials(name);
  }

  protected ariaLabel(n: LaidNode): string {
    const parts = [n.node.full_name, n.node.position?.name, n.node.department?.name].filter(
      Boolean,
    );
    if (n.headcount) {
      parts.push(this.i18n.translate('people.orgChart.headcount', { n: n.headcount }));
    }
    return parts.join(', ');
  }
}
