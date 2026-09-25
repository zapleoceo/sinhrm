import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, inject, input, signal } from '@angular/core';
import { FormControl, FormRecord, ReactiveFormsModule, ValidatorFn, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatChipsModule } from '@angular/material/chips';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSelectModule } from '@angular/material/select';
import { MatSnackBar } from '@angular/material/snack-bar';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { Integration, IntegrationField, IntegrationLog, MANUAL_STATUSES, ManualStatus } from './integrations.model';
import { IntegrationsService, buildUpdate, checkResultKey, integrationErrorKey } from './integrations.service';
import { IntegrationsStore } from './integrations.store';

const URL_PATTERN = /^https?:\/\/\S+$/i;

/** One integration: header with status, expandable config form generated from the FieldSpec, recent log. */
@Component({
  selector: 'app-integration-card',
  imports: [
    DatePipe,
    ReactiveFormsModule,
    MatButtonModule,
    MatChipsModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatProgressBarModule,
    MatSelectModule,
    TranslocoPipe,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './integration-card.html',
  styleUrl: './integration-card.scss',
})
export class IntegrationCard {
  private readonly store = inject(IntegrationsStore);
  private readonly api = inject(IntegrationsService);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);

  readonly item = input.required<Integration>();

  protected readonly manualStatuses = MANUAL_STATUSES;
  protected readonly expanded = signal(false);
  protected readonly form = signal(new FormRecord<FormControl<string>>({}));
  protected readonly cleared = signal<ReadonlySet<string>>(new Set());
  protected readonly logs = signal<IntegrationLog[] | null>(null);
  protected readonly logsFailed = signal(false);
  protected readonly busy = computed(() => this.store.pending().has(this.item().key));
  protected readonly manualStatus = computed<ManualStatus | null>(() => {
    const status = this.item().status;
    return status === 'off' || status === 'demo' ? status : null;
  });
  protected readonly checkKey = computed(() => checkResultKey(this.item().last_error));

  protected toggle(): void {
    const open = !this.expanded();
    this.expanded.set(open);
    if (open) {
      this.resetForm();
      this.loadLogs();
    }
  }

  protected setStatus(status: ManualStatus): void {
    this.store.setStatus(this.item(), status, (key) => this.toast(key));
  }

  protected clearSecret(field: IntegrationField): void {
    this.cleared.update((set) => new Set(set).add(field.name));
    this.form().controls[field.name]?.setValue('');
  }

  protected undoClear(field: IntegrationField): void {
    this.cleared.update((set) => {
      const next = new Set(set);
      next.delete(field.name);
      return next;
    });
  }

  protected save(): void {
    const form = this.form();
    if (form.invalid) {
      form.markAllAsTouched();
      return;
    }
    this.store.save(this.item().key, buildUpdate(this.item().fields, form.getRawValue(), this.cleared())).subscribe({
      next: () => {
        this.resetForm();
        this.loadLogs();
        this.toast('integrations.saved');
      },
      error: (e: unknown) => this.toast(integrationErrorKey(e)),
    });
  }

  protected check(): void {
    this.store.check(this.item().key).subscribe({
      next: (item) => {
        this.loadLogs();
        this.toast(item.status === 'connected' ? 'integrations.checkOk' : 'integrations.checkFailed');
      },
      error: (e: unknown) => this.toast(integrationErrorKey(e)),
    });
  }

  protected loadLogs(): void {
    this.logsFailed.set(false);
    this.api.logs(this.item().key).subscribe({
      next: (logs) => this.logs.set(logs),
      error: () => this.logsFailed.set(true),
    });
  }

  protected isRequired(field: IntegrationField): boolean {
    return field.required && field.type !== 'secret';
  }

  private resetForm(): void {
    const controls: Record<string, FormControl<string>> = {};
    for (const field of this.item().fields) {
      const initial = field.type === 'secret' ? '' : (field.value ?? field.default ?? '');
      controls[field.name] = new FormControl(initial, { nonNullable: true, validators: this.validators(field) });
    }
    this.form.set(new FormRecord(controls));
    this.cleared.set(new Set());
  }

  private validators(field: IntegrationField): ValidatorFn[] {
    const list: ValidatorFn[] = [];
    if (this.isRequired(field)) {
      list.push(Validators.required);
    }
    if (field.type === 'url') {
      list.push(Validators.pattern(URL_PATTERN));
    }
    return list;
  }

  private toast(key: string): void {
    this.snack.open(this.i18n.translate(key), undefined, { duration: 3000 });
  }
}
