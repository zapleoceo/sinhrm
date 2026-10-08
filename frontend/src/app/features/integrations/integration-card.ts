import { ChangeDetectionStrategy, Component, ElementRef, OnChanges, SimpleChanges, afterRenderEffect, computed, inject, input, signal } from '@angular/core';
import { FormControl, FormRecord, ReactiveFormsModule, ValidatorFn, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSelectModule } from '@angular/material/select';
import { TranslocoPipe } from '@jsverse/transloco';
import { GOOGLE_SERVICES, GoogleOAuthState, connectUrl } from '../google-workspace/google.model';
import { ChannelPanel } from '../channels/channel-panel';
import { INTEGRATION_STATUS_TONE, Integration, IntegrationField, IntegrationLog, IntegrationStatus, MANUAL_STATUSES } from './integrations.model';
import { IntegrationsService, buildUpdate, checkResultKey, integrationErrorKey } from './integrations.service';
import { IntegrationsStore } from './integrations.store';
import { ChannelIcon } from '../../core/ui/channel-icon';
import { NotifyService } from '../../core/ui/notify.service';
import { DATE_LOCALES } from '../../core/date/app-date-adapter';
import { LanguageService } from '../../core/i18n/language.service';
import { IntegrationDatePipe } from './integration-date.pipe';
import { withMember } from '../../core/ui/with-member';

const URL_PATTERN = /^https:\/\/\S+$/i;

/** One integration: header with status, expandable config form generated from the FieldSpec, recent log. */
@Component({
  selector: 'app-integration-card',
  imports: [
    ChannelIcon,
    IntegrationDatePipe,
    ReactiveFormsModule,
    MatButtonModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatProgressBarModule,
    MatSelectModule,
    TranslocoPipe,
    ChannelPanel,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './integration-card.html',
  styleUrl: './integration-card.scss',
})
export class IntegrationCard implements OnChanges {
  private readonly store = inject(IntegrationsStore);
  private readonly api = inject(IntegrationsService);
  private readonly notify = inject(NotifyService);
  private readonly language = inject(LanguageService);
  private readonly dateFormatter = new IntegrationDatePipe();

  readonly item = input.required<Integration>();
  readonly focused = input(false);
  readonly googleOAuthState = input<GoogleOAuthState>('loading');
  protected readonly googleOauthConfigured = computed(() => this.googleOAuthState() === 'ready');
  protected readonly googleConnectHref = connectUrl(GOOGLE_SERVICES);
  private readonly element = inject<ElementRef<HTMLElement>>(ElementRef);

  constructor() {
    afterRenderEffect(() => {
      if (this.focused()) {
        const card = this.element.nativeElement.querySelector<HTMLElement>('article');
        card?.focus({ preventScroll: true });
        card?.scrollIntoView?.({ block: 'start' });
      }
    });
  }

  ngOnChanges(changes: SimpleChanges): void {
    if (changes['focused'] && this.focused() && !this.expanded()) {
      this.expanded.set(true);
      this.resetForm();
      this.loadLogs();
    }
  }

  protected readonly manualStatuses = MANUAL_STATUSES;
  protected readonly statusTone = INTEGRATION_STATUS_TONE;
  protected readonly expanded = signal(false);
  protected readonly form = signal(new FormRecord<FormControl<string>>({}));
  protected readonly cleared = signal<ReadonlySet<string>>(new Set());
  protected readonly logs = signal<IntegrationLog[] | null>(null);
  protected readonly logsFailed = signal(false);
  protected readonly dateLocale = computed(() => DATE_LOCALES[this.language.current()]);
  protected readonly checkedDate = computed(() => this.dateFormatter.transform(this.item().last_checked_at, this.dateLocale()));
  protected readonly busy = computed(() => this.store.pending().has(this.item().key));
  protected readonly checkKey = computed(() => checkResultKey(this.item().last_error));

  protected toggle(): void {
    const open = !this.expanded();
    this.expanded.set(open);
    if (open) {
      this.resetForm();
      this.loadLogs();
    }
  }

  protected setStatus(status: IntegrationStatus): void {
    // Connected/error are observations, never user-assigned modes.
    if (status !== 'off' && status !== 'demo') return;
    this.store.setStatus(this.item(), status, (key) => this.notify.show(key, { duration: 3000 }));
  }

  protected clearSecret(field: IntegrationField): void {
    this.cleared.update((set) => withMember(set, field.name, true));
    this.form().controls[field.name]?.setValue('');
  }

  protected undoClear(field: IntegrationField): void {
    this.cleared.update((set) => withMember(set, field.name, false));
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
        this.notify.show('integrations.saved', { duration: 3000 });
      },
      error: (e: unknown) => this.notify.show(integrationErrorKey(e), { duration: 3000 }),
    });
  }

  protected check(): void {
    this.store.check(this.item().key).subscribe({
      next: (item) => {
        this.loadLogs();
        this.notify.show(item.status === 'connected' ? 'integrations.checkOk' : 'integrations.checkFailed', { duration: 3000 });
      },
      error: (e: unknown) => this.notify.show(integrationErrorKey(e), { duration: 3000 }),
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
}
