import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { map } from 'rxjs';

/** "Розділ вимкнено": where moduleGuard sends pages of a switched-off or role-restricted module. */
@Component({
  selector: 'app-module-off-page',
  imports: [MatButtonModule, MatIconModule, RouterLink, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <section class="off">
      <mat-icon aria-hidden="true">block</mat-icon>
      <h1>{{ 'modules.off.title' | transloco }}</h1>
      @if (module(); as key) {
        <p class="module">{{ 'modules.names.' + key | transloco }}</p>
      }
      <p class="muted">{{ 'modules.off.text' | transloco }}</p>
      <a mat-flat-button routerLink="/">{{ 'modules.off.home' | transloco }}</a>
    </section>
  `,
  styles: `
    .off { display: flex; flex-direction: column; align-items: center; text-align: center; gap: 0.5rem; padding: 3rem 1rem; }
    .off mat-icon { font-size: 48px; width: 48px; height: 48px; opacity: 0.6; }
    h1 { font: var(--mat-sys-headline-small); margin: 0; }
    .module { font-weight: 500; margin: 0; }
  `,
})
export class ModuleOffPage {
  protected readonly module = toSignal(inject(ActivatedRoute).queryParamMap.pipe(map((q) => q.get('module'))));
}
