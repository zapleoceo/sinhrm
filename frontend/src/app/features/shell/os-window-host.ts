import { ChangeDetectionStrategy, Component } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { WINDOW_ROUTER_PROVIDER, windowContextProvider } from '../../core/windows/window-router';

/** Real named-outlet boundary. Child pages keep Angular's actual ActivatedRoute and are routed normally. */
@Component({
  selector: 'app-os-window-host',
  imports: [RouterOutlet],
  providers: [windowContextProvider(), WINDOW_ROUTER_PROVIDER],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: '<router-outlet />',
})
export class OsWindowHost {}
