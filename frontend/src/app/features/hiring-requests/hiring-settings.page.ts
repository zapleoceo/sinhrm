import { USER_ROLES } from '../../core/auth/auth.model';
import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { MatSnackBar } from '@angular/material/snack-bar';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { FORM_FIELD_TYPES, FormField, FormFieldType, ROUTE_STEP_KINDS, RouteStep, RouteStepKind } from './hiring-requests.model';
import { HiringRequestsService, hiringErrorKey } from './hiring-requests.service';

const ROLES = USER_ROLES;

/** Moves an item one position up (-1) or down (+1) — the "drag" of tz2 done with buttons (keyboard-friendly). */
export function moveItem<T>(list: readonly T[], index: number, delta: -1 | 1): T[] {
  const target = index + delta;
  if (target < 0 || target >= list.length) {
    return [...list];
  }
  const copy = [...list];
  [copy[index], copy[target]] = [copy[target], copy[index]];
  return copy;
}

/**
 * Admin settings of hiring requests (tz2 "Настройки: конструктор полей формы заявки" + approval route):
 * route steps (manager / role / user, SLA days), configurable form fields (order, required), extra creators,
 * auto-vacancy on final approval.
 */
@Component({
  selector: 'app-hiring-settings-page',
  imports: [MatButtonModule, MatCheckboxModule, MatFormFieldModule, MatIconModule, MatInputModule, MatSelectModule, MatSlideToggleModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="page-head">
      <div>
        <h1>{{ 'hiring.settings.title' | transloco }}</h1>
        <p class="muted">{{ 'hiring.settings.subtitle' | transloco }}</p>
      </div>
      <button mat-flat-button type="button" (click)="save()" [disabled]="saving()"><mat-icon>save</mat-icon>{{ 'common.save' | transloco }}</button>
    </header>

    <section class="panel box">
      <h2>{{ 'hiring.settings.route' | transloco }}</h2>
      @for (s of route(); track $index; let i = $index) {
        <div class="row">
          <span class="num">{{ i + 1 }}.</span>
          <mat-form-field subscriptSizing="dynamic"><mat-label>{{ 'hiring.settings.stepName' | transloco }}</mat-label>
            <input matInput [value]="s.name" (input)="patchStep(i, { name: value($event) })" maxlength="120" /></mat-form-field>
          <mat-form-field subscriptSizing="dynamic"><mat-label>{{ 'hiring.settings.kind' | transloco }}</mat-label>
            <mat-select [value]="s.kind" (valueChange)="patchStep(i, { kind: $event })">
              @for (k of kinds; track k) {
                <mat-option [value]="k">{{ 'hiring.kind.' + k | transloco }}</mat-option>
              }
            </mat-select></mat-form-field>
          @if (s.kind === 'role') {
            <mat-form-field subscriptSizing="dynamic"><mat-label>{{ 'hiring.settings.role' | transloco }}</mat-label>
              <mat-select [value]="s.role" (valueChange)="patchStep(i, { role: $event })">
                @for (r of roles; track r) {
                  <mat-option [value]="r">{{ 'roles.' + r | transloco }}</mat-option>
                }
              </mat-select></mat-form-field>
          }
          @if (s.kind === 'user') {
            <mat-form-field subscriptSizing="dynamic"><mat-label>{{ 'hiring.settings.user' | transloco }}</mat-label>
              <mat-select [value]="s.user_id" (valueChange)="patchStep(i, { user_id: $event })">
                @for (u of users(); track u.id) {
                  <mat-option [value]="u.id">{{ u.name }}</mat-option>
                }
              </mat-select></mat-form-field>
          }
          <mat-form-field subscriptSizing="dynamic" class="xs"><mat-label>{{ 'hiring.settings.sla' | transloco }}</mat-label>
            <input matInput type="number" min="1" max="60" [value]="s.sla_days ?? ''" (input)="patchStep(i, { sla_days: numberOrNull($event) })" /></mat-form-field>
          <button mat-icon-button type="button" (click)="route.set(move(route(), i, -1))" [attr.aria-label]="'hiring.settings.up' | transloco"><mat-icon>arrow_upward</mat-icon></button>
          <button mat-icon-button type="button" (click)="route.set(move(route(), i, 1))" [attr.aria-label]="'hiring.settings.down' | transloco"><mat-icon>arrow_downward</mat-icon></button>
          <button mat-icon-button type="button" (click)="removeStep(i)" [attr.aria-label]="'common.delete' | transloco"><mat-icon>delete</mat-icon></button>
        </div>
      }
      <button mat-stroked-button type="button" (click)="addStep()"><mat-icon>add</mat-icon>{{ 'hiring.settings.addStep' | transloco }}</button>
    </section>

    <section class="panel box">
      <h2>{{ 'hiring.settings.fields' | transloco }}</h2>
      <p class="muted small">{{ 'hiring.settings.fieldsHint' | transloco }}</p>
      @for (f of fields(); track $index; let i = $index) {
        <div class="row">
          <mat-form-field subscriptSizing="dynamic" class="sm"><mat-label>{{ 'hiring.settings.key' | transloco }}</mat-label>
            <input matInput [value]="f.key" (input)="patchField(i, { key: value($event) })" maxlength="40" /></mat-form-field>
          <mat-form-field subscriptSizing="dynamic"><mat-label>{{ 'hiring.settings.label' | transloco }}</mat-label>
            <input matInput [value]="f.label" (input)="patchField(i, { label: value($event) })" maxlength="120" /></mat-form-field>
          <mat-form-field subscriptSizing="dynamic" class="sm"><mat-label>{{ 'hiring.settings.type' | transloco }}</mat-label>
            <mat-select [value]="f.type" (valueChange)="patchField(i, { type: $event })">
              @for (t of types; track t) {
                <mat-option [value]="t">{{ 'hiring.fieldType.' + t | transloco }}</mat-option>
              }
            </mat-select></mat-form-field>
          @if (f.type === 'select') {
            <mat-form-field subscriptSizing="dynamic"><mat-label>{{ 'hiring.settings.options' | transloco }}</mat-label>
              <input matInput [value]="(f.options ?? []).join(', ')" (change)="patchField(i, { options: list($event) })" /></mat-form-field>
          }
          <mat-checkbox [checked]="f.required" (change)="patchField(i, { required: $event.checked })">{{ 'hiring.settings.required' | transloco }}</mat-checkbox>
          <button mat-icon-button type="button" (click)="fields.set(move(fields(), i, -1))" [attr.aria-label]="'hiring.settings.up' | transloco"><mat-icon>arrow_upward</mat-icon></button>
          <button mat-icon-button type="button" (click)="fields.set(move(fields(), i, 1))" [attr.aria-label]="'hiring.settings.down' | transloco"><mat-icon>arrow_downward</mat-icon></button>
          <button mat-icon-button type="button" (click)="removeField(i)" [attr.aria-label]="'common.delete' | transloco"><mat-icon>delete</mat-icon></button>
        </div>
      }
      <button mat-stroked-button type="button" (click)="addField()"><mat-icon>add</mat-icon>{{ 'hiring.settings.addField' | transloco }}</button>
    </section>

    <section class="panel box">
      <h2>{{ 'hiring.settings.access' | transloco }}</h2>
      <p class="muted small">{{ 'hiring.settings.creatorsHint' | transloco }}</p>
      <mat-form-field class="wide"><mat-label>{{ 'hiring.settings.creators' | transloco }}</mat-label>
        <mat-select multiple [value]="creators()" (valueChange)="creators.set($event)">
          @for (u of users(); track u.id) {
            <mat-option [value]="u.id">{{ u.name }}</mat-option>
          }
        </mat-select></mat-form-field>
      <mat-slide-toggle [checked]="autoVacancy()" (change)="autoVacancy.set($event.checked)">{{ 'hiring.settings.autoVacancy' | transloco }}</mat-slide-toggle>
    </section>
  `,
  styles: `
    .box { padding: 1rem; margin-bottom: var(--app-gap); display: flex; flex-direction: column; gap: 0.5rem; align-items: flex-start; }
    h2 { font: var(--mat-sys-title-medium); margin: 0; }
    .row { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; }
    .num { width: 1.5rem; color: var(--app-muted); }
    .sm { width: 10rem; }
    .xs { width: 6rem; }
    .wide { width: 100%; max-width: 40rem; }
    .small { font-size: 0.8rem; margin: 0; }
  `,
})
export class HiringSettingsPage implements OnInit {
  private readonly api = inject(HiringRequestsService);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  protected readonly kinds = ROUTE_STEP_KINDS;
  protected readonly types = FORM_FIELD_TYPES;
  protected readonly roles = ROLES;
  protected readonly move = moveItem;
  protected readonly route = signal<RouteStep[]>([]);
  protected readonly fields = signal<FormField[]>([]);
  protected readonly creators = signal<number[]>([]);
  protected readonly autoVacancy = signal(true);
  protected readonly users = signal<{ id: number; name: string }[]>([]);
  protected readonly saving = signal(false);

  ngOnInit(): void {
    this.api.settings().subscribe({ next: (s) => this.apply(s), error: (e: unknown) => this.toast(hiringErrorKey(e)) });
  }

  protected value(event: Event): string {
    return (event.target as HTMLInputElement).value;
  }

  protected numberOrNull(event: Event): number | null {
    const v = this.value(event);
    return v === '' ? null : Number(v);
  }

  protected list(event: Event): string[] {
    return this.value(event).split(',').map((s) => s.trim()).filter((s) => s !== '');
  }

  protected patchStep(i: number, patch: Partial<RouteStep> & { kind?: RouteStepKind }): void {
    this.route.update((list) => list.map((s, j) => (j === i ? { ...s, ...patch } : s)));
  }

  protected addStep(): void {
    this.route.update((list) => [...list, { name: '', kind: 'role', role: 'admin', user_id: null, sla_days: 2 }]);
  }

  protected removeStep(i: number): void {
    this.route.update((list) => list.filter((_, j) => j !== i));
  }

  protected patchField(i: number, patch: Partial<FormField> & { type?: FormFieldType }): void {
    this.fields.update((list) => list.map((f, j) => (j === i ? { ...f, ...patch } : f)));
  }

  protected addField(): void {
    this.fields.update((list) => [...list, { key: `field_${list.length + 1}`, label: '', type: 'text', required: false }]);
  }

  protected removeField(i: number): void {
    this.fields.update((list) => list.filter((_, j) => j !== i));
  }

  protected save(): void {
    this.saving.set(true);
    const route = this.route().map((s) => ({ name: s.name, kind: s.kind, role: s.kind === 'role' ? s.role : null, user_id: s.kind === 'user' ? s.user_id : null, sla_days: s.sla_days }));
    this.api.saveSettings({ route, form_fields: this.fields(), creator_user_ids: this.creators(), auto_vacancy: this.autoVacancy() }).subscribe({
      next: (s) => {
        this.saving.set(false);
        this.apply(s);
        this.toast('hiring.settings.saved');
      },
      error: (e: unknown) => {
        this.saving.set(false);
        this.toast(hiringErrorKey(e));
      },
    });
  }

  private apply(s: { route: RouteStep[]; form_fields: FormField[]; creator_user_ids: number[]; auto_vacancy: boolean; users: { id: number; name: string }[] }): void {
    this.route.set(s.route.map((r) => ({ name: r.name, kind: r.kind, role: r.role, user_id: r.user_id, sla_days: r.sla_days })));
    this.fields.set(s.form_fields);
    this.creators.set(s.creator_user_ids);
    this.autoVacancy.set(s.auto_vacancy);
    this.users.set(s.users);
  }

  private toast(key: string): void {
    this.snack.open(this.i18n.translate(key), undefined, { duration: 4000 });
  }
}
