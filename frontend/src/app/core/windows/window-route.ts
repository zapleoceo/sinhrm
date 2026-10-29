import { Route, Routes } from '@angular/router';
import { WindowSlot } from './window-context';
import { OsWindowHost } from '../../features/shell/os-window-host';

/** The fixed named outlet wrapper preserves the same child route records used by the primary outlet. */
export function windowOutletRoute(slot: WindowSlot, children: Routes): Route {
  return {
    // Non-empty so an unopened secondary outlet cannot match an empty-path route and create a phantom window.
    path: 'window',
    outlet: slot,
    component: OsWindowHost,
    data: { windowSlot: slot, windowRoot: true },
    children,
  };
}
