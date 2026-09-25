import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { KeyValuePipe } from '@angular/common';
import { HealthService } from '../../core/api/health.service';

@Component({
  selector: 'app-status-page',
  imports: [KeyValuePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="status">
      <h1>SinHRM</h1>
      @if (report(); as r) {
        <p [class.ok]="r.ok" [class.fail]="!r.ok">API: {{ r.ok ? 'OK' : 'недоступний' }} · v{{ r.version }}</p>
        <ul>
          @for (c of r.checks | keyvalue; track c.key) {
            <li>{{ c.key }}: {{ c.value.ok ? '✓' : '✗' }}</li>
          }
        </ul>
      } @else {
        <p>Перевірка…</p>
      }
    </main>
  `,
  styles: `
    .status { max-width: 40rem; margin: 4rem auto; padding: 0 1rem; font-family: var(--mat-sys-body-large-font, sans-serif); }
    .ok { color: #15803d; }
    .fail { color: #b91c1c; }
  `,
})
export class StatusPage {
  protected readonly report = toSignal(inject(HealthService).check());
}
