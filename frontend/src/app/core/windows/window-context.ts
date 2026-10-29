import { InjectionToken } from '@angular/core';

export const WINDOW_SLOTS = ['os1', 'os2'] as const;
export type WindowSlot = (typeof WINDOW_SLOTS)[number];
export type WindowRouteId = 'tasks' | 'people';

export interface WindowContext {
  readonly slot: WindowSlot;
}

export const WINDOW_CONTEXT = new InjectionToken<WindowContext>('SinHRM window context');

export interface WindowRouteDefinition {
  readonly id: WindowRouteId;
  readonly path: string;
  readonly module: string | null;
  readonly titleKey: string;
}

/** Only these audited live routes may be activated in an OS window. */
export const WINDOW_ROUTE_REGISTRY: readonly WindowRouteDefinition[] = [
  { id: 'tasks', path: 'tasks', module: null, titleKey: 'titles.tasks' },
  { id: 'people', path: 'people', module: 'people', titleKey: 'titles.people' },
];

export function isWindowSlot(value: unknown): value is WindowSlot {
  return value === 'os1' || value === 'os2';
}

export function isWindowRouteId(value: unknown): value is WindowRouteId {
  return value === 'tasks' || value === 'people';
}

export function windowRouteById(id: WindowRouteId): WindowRouteDefinition {
  return WINDOW_ROUTE_REGISTRY.find((route) => route.id === id) ?? WINDOW_ROUTE_REGISTRY[0];
}

export function windowRouteByPath(path: string): WindowRouteDefinition | undefined {
  const canonical = path.replace(/^\/+|\/+$/g, '');
  return WINDOW_ROUTE_REGISTRY.find((route) => route.path === canonical);
}
