import { ChangeDetectionStrategy, Component } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { TABLE_QUERY_ADAPTER } from '../../core/ui/table/table-query-adapter';
import { WINDOW_ROUTER_PROVIDER, windowContextProvider } from '../../core/windows/window-router';
import { WindowQueryState } from '../../core/windows/window-query-state';

/** Real named-outlet boundary. Child pages keep Angular's actual ActivatedRoute and are routed normally. */
@Component({
  selector: 'app-os-window-host',
  imports: [RouterOutlet],
  providers: [
    windowContextProvider(),
    WINDOW_ROUTER_PROVIDER,
    WindowQueryState,
    { provide: TABLE_QUERY_ADAPTER, useExisting: WindowQueryState },
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: '<router-outlet />',
})
export class OsWindowHost {}
