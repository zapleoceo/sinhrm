import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { AuthService } from './auth.service';
import { UserRole } from './auth.model';

/** Signed-in users only; guests go to /login. */
export const authGuard: CanActivateFn = async () => {
  const auth = inject(AuthService);
  const router = inject(Router);
  await auth.load();
  return auth.isLoggedIn() ? true : router.createUrlTree(['/login']);
};

/** Users with the given role only; others go to the start page (guests to /login). */
export function roleGuard(role: UserRole): CanActivateFn {
  return async () => {
    const auth = inject(AuthService);
    const router = inject(Router);
    await auth.load();
    if (!auth.isLoggedIn()) {
      return router.createUrlTree(['/login']);
    }
    return auth.hasRole(role) ? true : router.createUrlTree(['/']);
  };
}

/** /login is for guests; a signed-in user is sent to the start page. */
export const guestGuard: CanActivateFn = async () => {
  const auth = inject(AuthService);
  const router = inject(Router);
  await auth.load();
  return auth.isLoggedIn() ? router.createUrlTree(['/']) : true;
};
