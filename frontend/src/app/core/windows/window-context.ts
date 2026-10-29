import { InjectionToken } from '@angular/core';
import type { Route, Routes } from '@angular/router';

export const WINDOW_SLOTS = ['os1', 'os2', 'os3', 'os4'] as const;
export type WindowSlot = (typeof WINDOW_SLOTS)[number];
export type WindowRouteId = string;

export interface WindowContext {
  readonly slot: WindowSlot;
}

export const WINDOW_CONTEXT = new InjectionToken<WindowContext>('SinHRM window context');

export interface WindowRouteDefinition {
  readonly id: WindowRouteId;
  readonly path: string;
  readonly module: string | null;
  readonly titleKey: string;
  readonly primaryOnlyQueryKeys: readonly string[];
  readonly route: Route;
}

/** Route data keys shared by the real primary and auxiliary Router branches. */
export const WINDOW_ROUTE_DATA = 'windowRoute';
export const WINDOW_MODULE_DATA = 'windowModule';
export const WINDOW_PRIMARY_ONLY_QUERY_DATA = 'windowPrimaryOnlyQueryKeys';

export function isWindowSlot(value: unknown): value is WindowSlot {
  return WINDOW_SLOTS.some((slot) => slot === value);
}

export function windowRouteDefinition(route: Route): WindowRouteDefinition | null {
  const id = route.data?.[WINDOW_ROUTE_DATA];
  const path = route.path;
  if (typeof id !== 'string' || !id || !path || path === '**') return null;
  const module = route.data?.[WINDOW_MODULE_DATA] ?? route.data?.['module'];
  const primaryOnly = route.data?.[WINDOW_PRIMARY_ONLY_QUERY_DATA];
  return {
    id,
    path,
    module: typeof module === 'string' ? module : null,
    titleKey: typeof route.title === 'string' ? route.title : 'shell.window.title',
    primaryOnlyQueryKeys: Array.isArray(primaryOnly) ? primaryOnly.filter((key): key is string => typeof key === 'string') : [],
    route,
  };
}

export function windowRouteDefinitions(routes: Routes): readonly WindowRouteDefinition[] {
  const flatten = (source: Routes): Route[] => source.flatMap((route) => [
    route,
    ...(route.children ? flatten(route.children) : []),
  ]);
  const seen = new Set<string>();
  return flatten(routes)
    .filter((route) => route.outlet === undefined || route.outlet === 'primary')
    .map(windowRouteDefinition)
    .filter((route): route is WindowRouteDefinition => {
      if (!route || seen.has(route.id)) return false;
      seen.add(route.id);
      return true;
    });
}

export function windowRouteById(routes: Routes, id: WindowRouteId): WindowRouteDefinition | undefined {
  return windowRouteDefinitions(routes).find((route) => route.id === id);
}

export function windowRouteByPath(routes: Routes, path: string): WindowRouteDefinition | undefined {
  const canonical = path.replace(/^\/+|\/+$/g, '');
  const segments = canonical ? canonical.split('/') : [];
  return windowRouteDefinitions(routes)
    .filter((route) => {
      const pattern = route.path.split('/');
      return pattern.length === segments.length && pattern.every((part, index) => part.startsWith(':') || part === segments[index]);
    })
    .sort((left, right) => staticSegmentCount(right.path) - staticSegmentCount(left.path))[0];
}

function staticSegmentCount(path: string): number {
  return path.split('/').filter((segment) => !segment.startsWith(':')).length;
}
