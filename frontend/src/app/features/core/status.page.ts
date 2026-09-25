import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { KeyValuePipe } from '@angular/common';
import { MatIconModule } from '@angular/material/icon';
import { TranslocoPipe } from '@jsverse/transloco';
import { HealthService } from '../../core/api/health.service';

@Component({
  selector: 'app-status-page',
  imports: [KeyValuePipe, MatIconModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h1>{{ 'status.title' | transloco }}</h1>
    @if (report(); as r) {
      <p class="state" [class.ok]="r.ok" [class.fail]="!r.ok">
        <mat-icon>{{ r.ok ? 'check_circle' : 'error' }}</mat-icon>
        {{ (r.ok ? 'status.apiOk' : 'status.apiDown') | transloco }}
      </p>
      <p class="muted">{{ 'status.version' | transloco }}: {{ r.version }}</p>
      <ul>
        @for (c of r.checks | keyvalue; track c.key) {
          <li [class.ok]="c.value.ok" [class.fail]="!c.value.ok">{{ c.key }}</li>
        }
      </ul>
    } @else {
      <p class="muted">{{ 'status.checking' | transloco }}</p>
    }
  `,
  styles: `
    h1 { font: var(--mat-sys-headline-small); margin: 0 0 1.5rem; }
    .state { display: flex; align-items: center; gap: 0.5rem; font: var(--mat-sys-title-medium); }
    .ok { color: var(--app-success); }
    .fail { color: var(--app-danger); }
  `,
})
export class StatusPage {
  protected readonly report = toSignal(inject(HealthService).check());
}
