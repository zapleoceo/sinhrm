import { InjectionToken, inject } from '@angular/core';
import { Router } from '@angular/router';

export interface DesktopLauncherAdapter {
  launch(route: string): Promise<boolean> | void;
}

/** Default navigation remains canonical Router navigation; the signed-in shell can open audited routes in windows. */
export const DESKTOP_LAUNCHER = new InjectionToken<DesktopLauncherAdapter>('DESKTOP_LAUNCHER', {
  providedIn: 'root',
  factory: () => {
    const router = inject(Router);
    return { launch: (route: string) => router.navigateByUrl(route) };
  },
});
