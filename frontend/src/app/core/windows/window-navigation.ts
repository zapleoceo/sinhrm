import { Injectable, computed, effect, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import {
  ActivatedRoute,
  ActivatedRouteSnapshot,
  NavigationEnd,
  ParamMap,
  Params,
  Router,
  UrlCreationOptions,
  UrlSegmentGroup,
  UrlTree,
  convertToParamMap,
} from '@angular/router';
import { filter, map, startWith, distinctUntilChanged } from 'rxjs';
import { AuthService } from '../auth/auth.service';
import { decodeWindowQuery, encodeWindowQuery } from './window-query-codec';
import { WindowRouteId, WindowRouteDefinition, WindowSlot, WINDOW_SLOTS, windowRouteById, windowRouteByPath, windowRouteDefinitions } from './window-context';

export interface WindowViewState {
  readonly slot: WindowSlot;
  readonly open: boolean;
  readonly routeId: WindowRouteId | null;
  readonly titleKey: string;
  readonly minimized: boolean;
  readonly maximized: boolean;
  readonly active: boolean;
}

const ACTIVE_PARAM = 'osActive';
const MINIMIZED_PARAM = 'osMin';
const MAXIMIZED_PARAM = 'osMax';
const PRIMARY_FOCUS_PARAM = 'osPrimary';

@Injectable()
export class WindowNavigation {
  private readonly router = inject(Router);
  private readonly shellRoute = inject(ActivatedRoute);
  private readonly auth = inject(AuthService);
  private observedSecurityScope: string | null | undefined;
  private readonly invalidating = new Set<WindowSlot>();
  private invalidationQueue: Promise<void> = Promise.resolve();
  private readonly ownTableNavigations = new Set<string>();
  private readonly slotOwners = new Map<WindowSlot, string>();
  private readonly url = toSignal(
    this.router.events.pipe(
      filter((event): event is NavigationEnd => event instanceof NavigationEnd),
      map((event) => event.urlAfterRedirects),
    ),
    { initialValue: this.router.url },
  );

  readonly windows = computed<Readonly<Record<WindowSlot, WindowViewState>>>(() => {
    this.url();
    const query = this.router.parseUrl(this.router.url).queryParams;
    const active = this.validSlot(query[ACTIVE_PARAM]);
    const minimized = this.slotList(query[MINIMIZED_PARAM]);
    const maximized = this.validSlot(query[MAXIMIZED_PARAM]);
    const open = WINDOW_SLOTS.filter((slot) => {
      const id = this.routeId(slot);
      if (id === null) {
        this.slotOwners.delete(slot);
        return false;
      }
      return this.isAuthorized(id, slot);
    });
    const activeSlot = active && open.includes(active) && !minimized.includes(active)
      ? active
      : query[PRIMARY_FOCUS_PARAM] === '1'
        ? null
        : open.find((slot) => !minimized.includes(slot)) ?? null;

    return Object.fromEntries(WINDOW_SLOTS.map((slot) => [slot, this.makeView(slot, open, minimized, maximized, activeSlot)])) as Record<WindowSlot, WindowViewState>;
  });
  readonly activeSlot = computed<WindowSlot | null>(() => {
    const windows = this.windows();
    return WINDOW_SLOTS.find((slot) => windows[slot].active) ?? null;
  });

  constructor() {
    effect(() => {
      const user = this.auth.user();
      const identity = user?.id ?? null;
      this.windows();
      const securityScope = user
        ? JSON.stringify({
          id: user.id,
          roles: [...user.roles].sort(),
          activeRole: user.active_role ?? null,
        })
        : null;
      const securityScopeChanged = this.observedSecurityScope !== undefined && securityScope !== this.observedSecurityScope;
      this.observedSecurityScope = securityScope;
      for (const slot of WINDOW_SLOTS) {
        if (!this.findOutlet(slot)) continue;
        const routeId = this.routeId(slot);
        if (identity === null || securityScopeChanged || (routeId !== null && !this.isAuthorized(routeId, slot))) this.invalidate(slot);
      }
    });
  }

  open(slot: WindowSlot, routeId: WindowRouteId): Promise<boolean> {
    const definition = this.definitionById(routeId);
    if (!definition) return Promise.resolve(false);
    const existing = WINDOW_SLOTS.find((candidate) => this.routeId(candidate) === routeId);
    if (!this.isAuthorized(routeId, existing ?? slot)) return Promise.resolve(false);
    if (existing) return this.isMinimized(existing) ? this.restore(existing) : this.focus(existing);
    const minimized = this.slotList(this.currentQuery()[MINIMIZED_PARAM]).filter((candidate) => candidate !== slot);
    const controls = this.controls({ [ACTIVE_PARAM]: slot, [MINIMIZED_PARAM]: this.encodeSlots(minimized), [MAXIMIZED_PARAM]: null });
    const tree = this.treeFor(slot, definition, {}, controls);
    return this.router.navigateByUrl(tree);
  }

  /** Query values attached to the active workspace route as bounded matrix params. */
  queryForWindow(slot: WindowSlot): ParamMap {
    const branch = this.findUrlOutlet(this.router.parseUrl(this.router.url).root, slot);
    return decodeWindowQuery(convertToParamMap(branch ? this.windowParams(branch) : {}));
  }

  queryChanges(slot: WindowSlot) {
    return this.router.events.pipe(
      filter((event): event is NavigationEnd => event instanceof NavigationEnd),
      startWith(null),
      map(() => this.queryForWindow(slot)),
      distinctUntilChanged((left, right) => left.keys.length === right.keys.length && left.keys.every((key) =>
        left.getAll(key).join('\u0000') === right.getAll(key).join('\u0000'))),
    );
  }

  visibilityChanges(slot: WindowSlot) {
    return this.router.events.pipe(
      filter((event): event is NavigationEnd => event instanceof NavigationEnd),
      startWith(null),
      map(() => this.isVisible(slot)),
      distinctUntilChanged(),
    );
  }

  isVisible(slot: WindowSlot): boolean {
    return this.isOpen(slot) && !this.isMinimized(slot);
  }

  slotFor(routeId: WindowRouteId): WindowSlot | null {
    return WINDOW_SLOTS.find((slot) => this.routeId(slot) === routeId)
      ?? WINDOW_SLOTS.find((slot) => !this.isOpen(slot))
      ?? null;
  }

  /** Open or focus a route in an existing/free slot; return false at capacity without replacing a mounted page. */
  openAvailable(routeId: WindowRouteId): Promise<boolean> {
    const existing = WINDOW_SLOTS.find((slot) => this.routeId(slot) === routeId);
    const slot = existing ?? this.slotFor(routeId);
    return slot ? this.open(slot, routeId) : Promise.resolve(false);
  }

  close(slot: WindowSlot): Promise<boolean> {
    if (!this.isOpen(slot)) return Promise.resolve(false);
    const remaining = WINDOW_SLOTS.filter((candidate) => candidate !== slot && this.isOpen(candidate));
    const minimized = this.slotList(this.currentQuery()[MINIMIZED_PARAM]).filter((candidate) => candidate !== slot);
    const currentActive = this.activeSlot();
    const controls = this.controls({
      [ACTIVE_PARAM]: currentActive === slot ? (remaining.find((candidate) => !minimized.includes(candidate)) ?? null) : currentActive,
      [MINIMIZED_PARAM]: this.encodeSlots(minimized),
      [MAXIMIZED_PARAM]: this.currentQuery()[MAXIMIZED_PARAM] === slot ? null : this.currentQuery()[MAXIMIZED_PARAM],
    });
    const tree = this.router.createUrlTree([{ outlets: { [slot]: null } }], {
      relativeTo: this.shellRoute,
      queryParams: controls,
      queryParamsHandling: 'merge',
    });
    return this.router.navigateByUrl(tree);
  }

  focus(slot: WindowSlot): Promise<boolean> {
    if (!this.isAuthorizedSlot(slot) || this.isMinimized(slot)) return Promise.resolve(false);
    return this.navigateControls({ [ACTIVE_PARAM]: slot, [PRIMARY_FOCUS_PARAM]: null });
  }

  focusPrimary(): Promise<boolean> {
    if (this.activeSlot() === null) return Promise.resolve(false);
    return this.navigateControls({ [ACTIVE_PARAM]: null, [PRIMARY_FOCUS_PARAM]: '1' });
  }

  minimize(slot: WindowSlot): Promise<boolean> {
    if (!this.isAuthorizedSlot(slot)) return Promise.resolve(false);
    const minimized = new Set(this.slotList(this.currentQuery()[MINIMIZED_PARAM]));
    minimized.add(slot);
    const active = this.activeSlot();
    const nextActive = active === slot
      ? WINDOW_SLOTS.find((candidate) => candidate !== slot && this.isOpen(candidate) && !minimized.has(candidate)) ?? null
      : active;
    return this.navigateControls({
      [MINIMIZED_PARAM]: this.encodeSlots([...minimized]),
      [ACTIVE_PARAM]: nextActive,
      [PRIMARY_FOCUS_PARAM]: nextActive === null ? '1' : null,
    });
  }

  restore(slot: WindowSlot): Promise<boolean> {
    if (!this.isAuthorizedSlot(slot)) return Promise.resolve(false);
    const minimized = this.slotList(this.currentQuery()[MINIMIZED_PARAM]).filter((candidate) => candidate !== slot);
    return this.navigateControls({ [MINIMIZED_PARAM]: this.encodeSlots(minimized), [ACTIVE_PARAM]: slot, [PRIMARY_FOCUS_PARAM]: null });
  }

  toggleMaximize(slot: WindowSlot): Promise<boolean> {
    if (!this.isAuthorizedSlot(slot) || this.isMinimized(slot)) return Promise.resolve(false);
    const maximized = this.currentQuery()[MAXIMIZED_PARAM] === slot ? null : slot;
    return this.navigateControls({ [MAXIMIZED_PARAM]: maximized, [ACTIVE_PARAM]: slot, [PRIMARY_FOCUS_PARAM]: null });
  }

  createWindowTree(slot: WindowSlot, definition: WindowRouteDefinition, query: Params = {}, options: UrlCreationOptions = {}): UrlTree | null {
    if (!this.isAuthorized(definition.id, slot)) return null;
    const routeId = this.routeId(slot);
    const currentMatrix = routeId === definition.id ? this.windowQueryMap(slot) : this.emptyQuery();
    const queryHandling = options.queryParamsHandling ?? '';
    const startingQuery = queryHandling === 'preserve' || queryHandling === 'merge' ? currentMatrix : this.emptyQuery();
    const patch = queryHandling === 'preserve' ? {} : { ...query, ...options.queryParams } as Params;
    const matrix = encodeWindowQuery(startingQuery, patch);
    if (matrix === null) return null;

    const minimized = this.slotList(this.currentQuery()[MINIMIZED_PARAM]).filter((candidate) => candidate !== slot);
    const controls = this.controls({ [ACTIVE_PARAM]: slot, [MINIMIZED_PARAM]: this.encodeSlots(minimized), [MAXIMIZED_PARAM]: null });
    return this.treeFor(slot, definition, matrix as Params, controls, options.fragment);
  }

  createCanonicalTree(slot: WindowSlot, path: string, options: UrlCreationOptions = {}): UrlTree | null {
    const definition = this.definitionByPath(path);
    return definition ? this.createWindowTree(slot, definition, options.queryParams ?? {}, options) : null;
  }

  createUrlTreeFromCommands(slot: WindowSlot, commands: Parameters<Router['createUrlTree']>[0], options?: UrlCreationOptions): UrlTree | null {
    const source = this.router.createUrlTree(commands, options);
    const hasWorkspaceBranch = this.isWorkspaceTree(source, slot);
    const absoluteCanonicalTarget = this.isAbsoluteCanonicalCommand(commands);
    if (hasWorkspaceBranch && !absoluteCanonicalTarget) {
      const currentId = this.routeId(slot);
      const definition = currentId ? this.definitionById(currentId) : undefined;
      if (commands.length === 0 && options?.queryParams && definition) {
        if (definition.primaryOnlyQueryKeys.some((key) => key in options.queryParams!)) return source;
        const updated = this.createWindowTree(slot, definition, options.queryParams, {
          ...options,
          queryParamsHandling: options.queryParamsHandling ?? 'replace',
        });
        if (!updated) throw new Error('The window route query exceeds the supported per-window URL limits.');
        return updated;
      }
      return this.preservePrimaryQuery(source);
    }
    const scoped = this.routeTreeForUrl(slot, source, options ?? { queryParams: {} });
    if (scoped) return scoped;
    if (hasWorkspaceBranch) {
      const currentId = this.routeId(slot);
      const definition = currentId ? this.definitionById(currentId) : undefined;
      if (options?.queryParams && definition && this.sameWindowBranch(source, slot)) {
        if (definition.primaryOnlyQueryKeys.some((key) => key in options.queryParams!)) return source;
        const updated = this.createWindowTree(slot, definition, options.queryParams, {
          ...options,
          queryParamsHandling: options.queryParamsHandling ?? 'replace',
        });
        if (!updated) throw new Error('The window route query exceeds the supported per-window URL limits.');
        return updated;
      }
      return source;
    }
    if (this.isWindowTarget(source) && !this.isPrimaryOnlyTarget(source)) {
      throw new Error('The window route query exceeds the supported per-window URL limits.');
    }
    return null;
  }

  private isAbsoluteCanonicalCommand(commands: Parameters<Router['createUrlTree']>[0]): boolean {
    const first = commands[0];
    return typeof first === 'string' && first.startsWith('/');
  }

  private preservePrimaryQuery(tree: UrlTree): UrlTree {
    const currentQuery = this.router.parseUrl(this.router.url).queryParams;
    return new UrlTree(tree.root, { ...currentQuery, ...tree.queryParams }, tree.fragment);
  }

  updateWindowQuery(slot: WindowSlot, patch: Params, replaceUrl: boolean): Promise<boolean> {
    const snapshot = this.findOutlet(slot);
    const routeId = this.routeId(slot);
    if (!snapshot || routeId === null || !this.isAuthorizedSlot(slot)) return Promise.resolve(false);
    const current = this.windowQueryMap(slot);
    const matrix = encodeWindowQuery(current, patch);
    if (matrix === null) return Promise.resolve(false);
    const definition = this.definitionById(routeId);
    if (!definition) return Promise.resolve(false);
    const tree = this.treeFor(slot, definition, matrix as Params, {});
    const targetUrl = this.router.serializeUrl(tree);
    this.ownTableNavigations.add(targetUrl);
    return this.router.navigateByUrl(tree, { replaceUrl }).finally(() => this.ownTableNavigations.delete(targetUrl));
  }

  shouldDropWindowTableEdit(slot: WindowSlot, targetUrl: string): boolean {
    if (this.ownTableNavigations.delete(targetUrl)) return false;
    const current = this.findUrlOutlet(this.router.parseUrl(this.router.url).root, slot);
    const target = this.findUrlOutlet(this.router.parseUrl(targetUrl).root, slot);
    if (!current || !target) return true;
    if (this.urlBranchPath(current) !== this.urlBranchPath(target)) return true;
    return JSON.stringify(this.windowParams(current)) !== JSON.stringify(this.windowParams(target));
  }

  isWorkspaceTree(tree: UrlTree, slot: WindowSlot): boolean {
    return this.hasOutlet(tree.root, slot);
  }

  routeTreeForUrl(slot: WindowSlot, tree: UrlTree, options?: UrlCreationOptions): UrlTree | null {
    const primary = tree.root.children['primary'];
    const path = primary?.segments.map((segment) => segment.path).join('/') ?? '';
    const definition = this.definitionByPath(path);
    if (!definition) return null;
    const requestedQuery: Params = { ...(options === undefined ? tree.queryParams : options.queryParams ?? {}) };
    for (const key of Object.keys(requestedQuery)) {
      if (key === ACTIVE_PARAM || key === MINIMIZED_PARAM || key === MAXIMIZED_PARAM || key === PRIMARY_FOCUS_PARAM) delete requestedQuery[key];
    }
    if (definition.primaryOnlyQueryKeys.some((key) => key in requestedQuery)) return null;
    const matrix: Params = {};
    for (const segment of primary?.segments ?? []) Object.assign(matrix, segment.parameters);
    const existing = decodeWindowQuery(this.leaf(this.findOutlet(slot))?.paramMap ?? this.router.routerState.snapshot.root.paramMap);
    const handling = options?.queryParamsHandling ?? 'replace';
    const startingQuery = handling === 'preserve' || handling === 'merge' ? existing : this.emptyQuery();
    const queryPatch = handling === 'preserve' ? {} : requestedQuery;
    const namespaced = encodeWindowQuery(startingQuery, { ...matrix, ...queryPatch });
    if (!namespaced) return null;
    const segments: (string | Params)[] = ['window', ...(primary?.segments.map((segment) => segment.path) ?? [])];
    if (Object.keys(namespaced).length) segments.push(namespaced);
    const minimized = this.slotList(this.currentQuery()[MINIMIZED_PARAM]).filter((candidate) => candidate !== slot);
    const controls = this.controls({ [ACTIVE_PARAM]: slot, [MINIMIZED_PARAM]: this.encodeSlots(minimized), [MAXIMIZED_PARAM]: null, [PRIMARY_FOCUS_PARAM]: null });
    return this.router.createUrlTree([{ outlets: { [slot]: segments } }], {
      relativeTo: this.shellRoute,
      queryParams: controls,
      queryParamsHandling: 'merge',
      fragment: tree.fragment ?? undefined,
    });
  }

  isWindowTarget(tree: UrlTree): boolean {
    const path = tree.root.children['primary']?.segments.map((segment) => segment.path).join('/') ?? '';
    return this.definitionByPath(path) !== undefined;
  }

  isPrimaryOnlyTarget(tree: UrlTree): boolean {
    const primary = tree.root.children['primary'];
    const definition = this.definitionByPath(primary?.segments.map((segment) => segment.path).join('/') ?? '');
    return !!definition?.primaryOnlyQueryKeys.some((key) => key in tree.queryParams);
  }

  private treeFor(slot: WindowSlot, definition: WindowRouteDefinition, matrix: Params, controls: Params, fragment?: string): UrlTree {
    const current = this.findOutlet(slot);
    const currentId = this.routeId(slot);
    const actualSegments: (string | Params)[] = current && currentId === definition.id
      ? this.routeSegments(slot)
      : definition.path.split('/');
    const cleanSegments: (string | Params)[] = [];
    for (const segment of actualSegments) {
      if (typeof segment !== 'object') {
        cleanSegments.push(segment);
        continue;
      }
      const retained = Object.fromEntries(Object.entries(segment).filter(([key]) => !key.startsWith('wq_')));
      if (Object.keys(retained).length) cleanSegments.push(retained);
    }
    const segments: (string | Params)[] = ['window', ...cleanSegments];
    if (Object.keys(matrix).length > 0) {
      const last = segments.length - 1;
      if (typeof segments[last] === 'object') segments[last] = { ...(segments[last] as Params), ...matrix };
      else segments.push(matrix);
    }
    return this.router.createUrlTree([{ outlets: { [slot]: segments } }], {
      relativeTo: this.shellRoute,
      queryParams: controls,
      queryParamsHandling: 'merge',
      fragment,
    });
  }

  private routeSegments(slot: WindowSlot): (string | Params)[] {
    const segments: (string | Params)[] = [];
    let current: UrlSegmentGroup | null = this.findUrlOutlet(this.router.parseUrl(this.router.url).root, slot);
    while (current) {
      for (const segment of current.segments) {
        segments.push(segment.path);
        if (Object.keys(segment.parameters).length) segments.push({ ...segment.parameters });
      }
      current = current.children['primary'] ?? null;
    }
    return segments.length > 1 ? segments.slice(1) : [];
  }

  private navigateControls(patch: Params): Promise<boolean> {
    const tree = this.router.createUrlTree([], { relativeTo: this.shellRoute, queryParams: patch, queryParamsHandling: 'merge' });
    return this.router.navigateByUrl(tree, { replaceUrl: true });
  }

  private makeView(slot: WindowSlot, open: readonly WindowSlot[], minimized: readonly WindowSlot[], maximized: WindowSlot | null, active: WindowSlot | null): WindowViewState {
    const id = this.routeId(slot);
    return {
      slot,
      open: open.includes(slot),
      routeId: id,
      titleKey: id ? this.definitionById(id)?.titleKey ?? 'shell.window.title' : 'shell.window.title',
      minimized: minimized.includes(slot),
      maximized: maximized === slot,
      active: active === slot,
    };
  }

  private isOpen(slot: WindowSlot): boolean {
    return this.findOutlet(slot) !== null;
  }

  isAuthorizedSlot(slot: WindowSlot): boolean {
    const routeId = this.routeId(slot);
    return routeId !== null && this.isAuthorized(routeId, slot);
  }

  private isAuthorized(routeId: WindowRouteId, slot: WindowSlot): boolean {
    const user = this.auth.user();
    if (!user) return false;
    const owner = this.slotOwners.get(slot);
    const scope = JSON.stringify({ id: user.id, roles: [...user.roles].sort(), activeRole: user.active_role ?? null });
    if (owner === undefined) this.slotOwners.set(slot, scope);
    else if (owner !== scope) return false;
    const module = this.definitionById(routeId)?.module;
    if (!this.definitionById(routeId)) return false;
    return module === null || (typeof module === 'string' && this.auth.hasModule(module));
  }

  private invalidate(slot: WindowSlot): void {
    if (this.invalidating.has(slot)) return;
    this.invalidating.add(slot);
    this.invalidationQueue = this.invalidationQueue
      .then(async () => {
        if (this.findOutlet(slot) && await this.close(slot)) this.slotOwners.delete(slot);
      })
      .catch(() => undefined)
      .finally(() => this.invalidating.delete(slot));
  }

  private findUrlOutlet(group: UrlTree['root'], slot: WindowSlot): UrlTree['root'] | null {
    if (Object.prototype.hasOwnProperty.call(group.children, slot)) return group.children[slot] ?? null;
    for (const child of Object.values(group.children)) {
      const found = this.findUrlOutlet(child, slot);
      if (found) return found;
    }
    return null;
  }

  private sameWindowBranch(tree: UrlTree, slot: WindowSlot): boolean {
    const current = this.findUrlOutlet(this.router.parseUrl(this.router.url).root, slot);
    const target = this.findUrlOutlet(tree.root, slot);
    return !!current && !!target && this.urlBranchPath(current) === this.urlBranchPath(target);
  }

  private urlBranchPath(group: UrlTree['root']): string {
    return [
      ...group.segments.map((segment) => segment.path),
      ...Object.values(group.children).flatMap((child) => [this.urlBranchPath(child)]),
    ].filter(Boolean).join('/');
  }

  private windowParams(group: UrlTree['root']): Readonly<Record<string, string>> {
    const params: Record<string, string> = {};
    for (const segment of group.segments) {
      for (const [key, value] of Object.entries(segment.parameters)) {
        if (key.startsWith('wq_')) params[key] = value;
      }
    }
    for (const child of Object.values(group.children)) Object.assign(params, this.windowParams(child));
    return Object.fromEntries(Object.entries(params).sort(([left], [right]) => left.localeCompare(right)));
  }

  private windowQueryMap(slot: WindowSlot): ParamMap {
    const branch = this.findUrlOutlet(this.router.parseUrl(this.router.url).root, slot);
    return decodeWindowQuery(convertToParamMap(branch ? this.windowParams(branch) : {}));
  }

  private isMinimized(slot: WindowSlot): boolean {
    return this.slotList(this.currentQuery()[MINIMIZED_PARAM]).includes(slot);
  }

  private routeId(slot: WindowSlot): WindowRouteId | null {
    const outlet = this.findOutlet(slot);
    let current = outlet;
    while (current) {
      const value = current.routeConfig?.data?.['windowRoute'];
      if (typeof value === 'string') return value;
      current = current.firstChild;
    }
    return null;
  }

  private definitions(): readonly WindowRouteDefinition[] {
    return windowRouteDefinitions(this.shellRoute.routeConfig?.children ?? []);
  }

  private definitionById(id: WindowRouteId): WindowRouteDefinition | undefined {
    return windowRouteById(this.definitions().map((definition) => definition.route), id);
  }

  private definitionByPath(path: string): WindowRouteDefinition | undefined {
    return windowRouteByPath(this.definitions().map((definition) => definition.route), path);
  }

  private findOutlet(slot: WindowSlot): ActivatedRouteSnapshot | null {
    const pending = [this.router.routerState.snapshot.root];
    while (pending.length > 0) {
      const current = pending.pop();
      if (!current) continue;
      if (current.outlet === slot) return current;
      pending.push(...current.children);
    }
    return null;
  }

  private leaf(route: ActivatedRouteSnapshot | null): ActivatedRouteSnapshot | null {
    let current = route;
    while (current?.firstChild) current = current.firstChild;
    return current;
  }

  private hasOutlet(group: UrlTree['root'], slot: WindowSlot): boolean {
    if (Object.prototype.hasOwnProperty.call(group.children, slot)) return true;
    return Object.values(group.children).some((child) => this.hasOutlet(child, slot));
  }

  private currentQuery(): Params {
    return this.router.parseUrl(this.router.url).queryParams;
  }

  private controls(patch: Params): Params {
    // Nulls must reach Router's merge handling to remove old control keys.
    return patch;
  }

  private validSlot(value: unknown): WindowSlot | null {
    return WINDOW_SLOTS.find((slot) => slot === value) ?? null;
  }

  private slotList(value: unknown): WindowSlot[] {
    if (typeof value !== 'string') return [];
    return value.split(',').filter((slot): slot is WindowSlot => this.validSlot(slot) !== null);
  }

  private encodeSlots(slots: readonly WindowSlot[]): string | null {
    const unique = [...new Set(slots)].filter((slot) => this.validSlot(slot) !== null);
    return unique.length > 0 ? unique.join(',') : null;
  }

  private emptyQuery() {
    return convertToParamMap({});
  }

}
