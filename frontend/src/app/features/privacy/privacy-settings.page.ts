import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { MatSnackBar } from '@angular/material/snack-bar';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { PrivacyService } from './privacy.service';

/** Default term offered when the rule is switched on. */
export const DEFAULT_RETENTION_MONTHS = 12;

/**
 * Personal data settings (superadmin, admin): the retention rule — rejected candidates are anonymized automatically
 * N months after their last application closed. Off by default.
 */
@Component({
  selector: 'app-privacy-settings-page',
  imports: [FormsModule, MatButtonModule, MatFormFieldModule, MatInputModule, MatSlideToggleModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="page-head">
      <h1>{{ 'privacy.settings.title' | transloco }}</h1>
    </header>
    <p class="muted">{{ 'privacy.settings.intro' | transloco }}</p>
    <mat-slide-toggle [checked]="enabled()" (change)="enabled.set($event.checked)">{{ 'privacy.settings.retention' | transloco }}</mat-slide-toggle>
    @if (enabled()) {
      <mat-form-field class="months">
        <mat-label>{{ 'privacy.settings.months' | transloco }}</mat-label>
        <input matInput type="number" min="1" max="120" [ngModel]="months()" (ngModelChange)="months.set(+$event)" />
      </mat-form-field>
      <p class="muted">{{ 'privacy.settings.retentionHint' | transloco: { months: months() } }}</p>
    }
    <div>
      <button mat-flat-button type="button" [disabled]="busy() || (enabled() && (months() < 1 || months() > 120))" (click)="save()">
        {{ 'common.save' | transloco }}
      </button>
    </div>
  `,
  styles: `
    :host { display: flex; flex-direction: column; gap: 1rem; max-width: 40rem; }
    .months { max-width: 12rem; }
  `,
})
export class PrivacySettingsPage implements OnInit {
  private readonly api = inject(PrivacyService);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);

  protected readonly enabled = signal(false);
  protected readonly months = signal(DEFAULT_RETENTION_MONTHS);
  protected readonly busy = signal(false);

  ngOnInit(): void {
    this.api.settings().subscribe((s) => {
      this.enabled.set(s.retention_rejected_months !== null);
      this.months.set(s.retention_rejected_months ?? DEFAULT_RETENTION_MONTHS);
    });
  }

  protected save(): void {
    this.busy.set(true);
    this.api.saveSettings({ retention_rejected_months: this.enabled() ? this.months() : null }).subscribe({
      next: () => {
        this.busy.set(false);
        this.snack.open(this.i18n.translate('privacy.settings.saved'), undefined, { duration: 3000 });
      },
      error: () => {
        this.busy.set(false);
        this.snack.open(this.i18n.translate('common.error'), undefined, { duration: 4000 });
      },
    });
  }
}
