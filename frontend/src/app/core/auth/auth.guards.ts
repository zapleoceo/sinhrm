import { inject } from '@angular/core';
import { CanActivateChildFn, CanActivateFn, Router } from '@angular/router';
import { AuthService } from './auth.service';
import { UserRole } from './auth.model';
import { moduleForUrl } from './module-map';
import { isWindowSlot } from '../windows/window-context';

/** Signed-in users only; guests go to /login. */
export const authGuard: CanActivateFn = async () => {
  const auth = inject(AuthService);
  const router = inject(Router);
  await auth.load();
  return auth.isLoggedIn() ? true : router.createUrlTree(['/login']);
};

/** Users with any of the given roles only; others go to the start page (guests to /login). */
export function roleGuard(...roles: UserRole[]): CanActivateFn {
  return async () => {
    const auth = inject(AuthService);
    const router = inject(Router);
    await auth.load();
    if (!auth.isLoggedIn()) {
      return router.createUrlTree(['/login']);
    }
    return roles.some((role) => auth.hasRole(role)) ? true : router.createUrlTree(['/']);
  };
}

/** /login is for guests; a signed-in user is sent to the start page. */
export const guestGuard: CanActivateFn = async () => {
  const auth = inject(AuthService);
  const router = inject(Router);
  await auth.load();
  return auth.isLoggedIn() ? router.createUrlTree(['/']) : true;
};

/**
 * Pages of a switched-off (or role-restricted) module open the friendly "Розділ вимкнено" page instead.
 * The API enforces the same rule (403 module_disabled / module_forbidden); this only avoids a broken page.
 */
export const moduleGuard: CanActivateChildFn = async (route) => {
  const auth = inject(AuthService);
  const router = inject(Router);
  await auth.load();
  const windowRoot = route.pathFromRoot.find((snapshot) => isWindowSlot(snapshot.outlet));
  if (windowRoot) {
    const routeData = route.routeConfig?.data;
    if (routeData?.['windowRoot'] === true) return true;
    if (routeData?.['windowRoute'] !== 'tasks' && routeData?.['windowRoute'] !== 'people') return false;
    if (!Object.prototype.hasOwnProperty.call(routeData, 'module')) return false;
    const branchModule: unknown = routeData['module'];
    return branchModule === null || (typeof branchModule === 'string' && auth.hasModule(branchModule));
  }
  const primaryPath = route.pathFromRoot
    .filter((snapshot) => snapshot.outlet === 'primary')
    .flatMap((snapshot) => snapshot.url.map((segment) => segment.path))
    .join('/');
  const module = moduleForUrl(`/${primaryPath}`);
  return module === null || auth.hasModule(module) ? true : router.createUrlTree(['/module-off'], { queryParams: { module } });
};
