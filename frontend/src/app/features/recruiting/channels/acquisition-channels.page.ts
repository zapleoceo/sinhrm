import { DecimalPipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, OnInit, computed, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatDatepickerModule } from '@angular/material/datepicker';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { MatSnackBar } from '@angular/material/snack-bar';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { AcquisitionChannel, CHANNEL_TYPES, ChannelType } from '../recruiting.model';
import { RecruitingService } from '../recruiting.service';
import { ChannelsService, UtmInput, channelErrorKey, ruleLabel } from './channels.service';
import { toIsoDate } from '../../../core/date/iso-date';
import { ChannelIcon } from '../../../core/ui/channel-icon';
import { hasChannelIcon } from '../../../core/ui/channel-icons';

/**
 * Admin: acquisition channels dictionary (tz3) — channel, technical name (code), type, active; UTM rules per
 * channel (most specific wins, then priority) with a "test UTM" box; costs per period for cost-per-hire.
 */
@Component({
  selector: 'app-acquisition-channels-page',
  imports: [ChannelIcon, DecimalPipe, MatButtonModule, MatDatepickerModule, MatFormFieldModule, MatIconModule, MatInputModule, MatSelectModule, MatSlideToggleModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="page-head">
      <div>
        <h1>{{ 'recruiting.channels.title' | transloco }}</h1>
        <p class="muted">{{ 'recruiting.channels.subtitle' | transloco }}</p>
      </div>
    </header>

    <section class="panel box">
      <h2>{{ 'recruiting.channels.test' | transloco }}</h2>
      <form class="row" (submit)="$event.preventDefault(); test(ts.value, tm.value, tc.value)">
        <mat-form-field subscriptSizing="dynamic"><mat-label>utm_source</mat-label><input matInput #ts /></mat-form-field>
        <mat-form-field subscriptSizing="dynamic"><mat-label>utm_medium</mat-label><input matInput #tm /></mat-form-field>
        <mat-form-field subscriptSizing="dynamic"><mat-label>utm_campaign</mat-label><input matInput #tc /></mat-form-field>
        <button mat-stroked-button type="submit"><mat-icon>science</mat-icon>{{ 'recruiting.channels.check' | transloco }}</button>
        @if (tested() !== undefined) {
          <strong>→ {{ testedName() ?? ('recruiting.channels.noMatch' | transloco) }}</strong>
        }
      </form>
    </section>

    @for (ch of channels(); track ch.id) {
      <section class="panel box" [class.inactive]="!ch.active">
        <div class="row head">
          <h2>@if (hasIcon(ch.code)) {<app-channel-icon [key]="ch.code" /> }{{ ch.name }} <code>{{ ch.code }}</code></h2>
          <span class="muted">{{ 'recruiting.channels.types.' + ch.type | transloco }}</span>
          <mat-slide-toggle [checked]="ch.active" (change)="save(ch, { active: $event.checked })">{{ 'recruiting.channels.active' | transloco }}</mat-slide-toggle>
        </div>
        <div class="cols">
          <div>
            <h3>{{ 'recruiting.channels.rules' | transloco }}</h3>
            <ul class="list">
              @for (r of ch.utm_rules ?? []; track r.id) {
                <li>
                  <code>{{ label(r) }}</code> <span class="muted">· {{ 'recruiting.channels.priority' | transloco }} {{ r.priority }}</span>
                  <button mat-icon-button type="button" (click)="deleteRule(r.id)" [attr.aria-label]="'common.delete' | transloco"><mat-icon>delete</mat-icon></button>
                </li>
              } @empty {
                <li class="muted">{{ 'recruiting.channels.noRules' | transloco }}</li>
              }
            </ul>
            <form class="row" (submit)="$event.preventDefault(); addRule(ch, s, m, c, p.value)">
              <mat-form-field subscriptSizing="dynamic" class="sm"><mat-label>source</mat-label><input matInput #s /></mat-form-field>
              <mat-form-field subscriptSizing="dynamic" class="sm"><mat-label>medium</mat-label><input matInput #m /></mat-form-field>
              <mat-form-field subscriptSizing="dynamic" class="sm"><mat-label>campaign</mat-label><input matInput #c /></mat-form-field>
              <mat-form-field subscriptSizing="dynamic" class="xs"><mat-label>{{ 'recruiting.channels.priority' | transloco }}</mat-label><input matInput #p type="number" min="1" max="1000" value="100" /></mat-form-field>
              <button mat-icon-button type="submit" [attr.aria-label]="'recruiting.channels.addRule' | transloco"><mat-icon>add</mat-icon></button>
            </form>
          </div>
          <div>
            <h3>{{ 'recruiting.channels.costs' | transloco }}</h3>
            <ul class="list">
              @for (k of ch.costs ?? []; track k.id) {
                <li>
                  {{ k.period_start }} – {{ k.period_end }}: <strong>{{ k.amount | number: '1.0-2' }} {{ k.currency }}</strong>
                  <button mat-icon-button type="button" (click)="deleteCost(k.id)" [attr.aria-label]="'common.delete' | transloco"><mat-icon>delete</mat-icon></button>
                </li>
              } @empty {
                <li class="muted">{{ 'recruiting.channels.noCosts' | transloco }}</li>
              }
            </ul>
            <form class="row" (submit)="$event.preventDefault(); addCost(ch, period.value?.start ?? null, period.value?.end ?? null, amount.value)">
              <mat-form-field subscriptSizing="dynamic" class="period">
                <mat-label>{{ 'recruiting.channels.from' | transloco }} — {{ 'recruiting.channels.to' | transloco }}</mat-label>
                <mat-date-range-input [rangePicker]="costPeriod" #period="matDateRangeInput" required>
                  <input matStartDate [placeholder]="'recruiting.channels.from' | transloco" required />
                  <input matEndDate [placeholder]="'recruiting.channels.to' | transloco" required />
                </mat-date-range-input>
                <mat-datepicker-toggle matIconSuffix [for]="costPeriod" />
                <mat-date-range-picker #costPeriod />
              </mat-form-field>
              <mat-form-field subscriptSizing="dynamic" class="sm"><mat-label>{{ 'recruiting.channels.amount' | transloco }}</mat-label><input matInput #amount type="number" min="0" required /></mat-form-field>
              <button mat-icon-button type="submit" [attr.aria-label]="'recruiting.channels.addCost' | transloco"><mat-icon>add</mat-icon></button>
            </form>
          </div>
        </div>
      </section>
    }

    <section class="panel box">
      <h2>{{ 'recruiting.channels.add' | transloco }}</h2>
      <form class="row" (submit)="$event.preventDefault(); create(code, name)">
        <mat-form-field subscriptSizing="dynamic"><mat-label>{{ 'recruiting.channels.code' | transloco }}</mat-label><input matInput #code required maxlength="50" /></mat-form-field>
        <mat-form-field subscriptSizing="dynamic"><mat-label>{{ 'recruiting.channels.name' | transloco }}</mat-label><input matInput #name required maxlength="120" /></mat-form-field>
        <mat-form-field subscriptSizing="dynamic">
          <mat-label>{{ 'recruiting.channels.type' | transloco }}</mat-label>
          <mat-select [value]="newType()" (valueChange)="newType.set($event)">
            @for (t of types; track t) {
              <mat-option [value]="t">{{ 'recruiting.channels.types.' + t | transloco }}</mat-option>
            }
          </mat-select>
        </mat-form-field>
        <button mat-flat-button type="submit"><mat-icon>add</mat-icon>{{ 'recruiting.channels.add' | transloco }}</button>
      </form>
    </section>
  `,
  styles: `
    .box { padding: 1rem; margin-bottom: var(--app-gap); }
    .box.inactive { opacity: 0.6; }
    h2 { font: var(--mat-sys-title-medium); margin: 0; }
    h3 { font: var(--mat-sys-title-small); margin: 0.5rem 0; }
    code { font-size: 0.8rem; color: var(--app-muted); }
    .row { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; }
    .head { justify-content: space-between; }
    .cols { display: grid; grid-template-columns: repeat(auto-fit, minmax(20rem, 1fr)); gap: 1rem; }
    .list { list-style: none; padding: 0; margin: 0 0 0.5rem; }
    .list li { display: flex; align-items: center; gap: 0.25rem; }
    .sm { width: 9rem; }
    .period { width: 16rem; }
    .xs { width: 6rem; }
  `,
})
export class AcquisitionChannelsPage implements OnInit {
  private readonly recruiting = inject(RecruitingService);
  private readonly api = inject(ChannelsService);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  protected readonly types = CHANNEL_TYPES;
  protected readonly hasIcon = hasChannelIcon;
  protected readonly channels = signal<AcquisitionChannel[]>([]);
  protected readonly tested = signal<number | null | undefined>(undefined);
  protected readonly testedName = computed(() => this.channels().find((c) => c.id === this.tested())?.name ?? null);
  protected readonly label = ruleLabel;
  protected readonly newType = signal<ChannelType>('job_board');

  ngOnInit(): void {
    this.load();
  }

  protected create(codeInput: HTMLInputElement, nameInput: HTMLInputElement): void {
    const type = this.newType();
    const code = codeInput.value.trim();
    const name = nameInput.value.trim();
    if (code === '' || name === '') {
      return;
    }
    codeInput.value = '';
    nameInput.value = '';
    this.api.save(null, { code, name, type }).subscribe({ next: () => this.load(), error: (e: unknown) => this.toast(e) });
  }

  protected save(ch: AcquisitionChannel, body: { active: boolean }): void {
    this.api.save(ch.id, body).subscribe({ next: (saved) => this.replace(saved), error: (e: unknown) => this.toast(e) });
  }

  protected addRule(ch: AcquisitionChannel, source: HTMLInputElement, medium: HTMLInputElement, campaign: HTMLInputElement, priority: string): void {
    const rule: UtmInput = { utm_source: source.value || null, utm_medium: medium.value || null, utm_campaign: campaign.value || null, priority: Number(priority) || 100 };
    for (const input of [source, medium, campaign]) {
      input.value = '';
    }
    this.api.addRule(ch.id, rule).subscribe({ next: (saved) => this.replace(saved), error: (e: unknown) => this.toast(e) });
  }

  protected deleteRule(id: number): void {
    this.api.deleteRule(id).subscribe({ next: () => this.load(), error: (e: unknown) => this.toast(e) });
  }

  protected addCost(ch: AcquisitionChannel, start: Date | null, end: Date | null, amount: string): void {
    const [from, to] = [toIsoDate(start), toIsoDate(end)];
    if (from === '' || to === '' || amount === '') {
      return;
    }
    this.api.addCost(ch.id, { period_start: from, period_end: to, amount: Number(amount) }).subscribe({
      next: (saved) => this.replace(saved),
      error: (e: unknown) => this.toast(e),
    });
  }

  protected deleteCost(id: number): void {
    this.api.deleteCost(id).subscribe({ next: () => this.load(), error: (e: unknown) => this.toast(e) });
  }

  protected test(source: string, medium: string, campaign: string): void {
    this.api.preview({ utm_source: source || null, utm_medium: medium || null, utm_campaign: campaign || null }).subscribe({
      next: (r) => this.tested.set(r.channel_id),
      error: (e: unknown) => this.toast(e),
    });
  }

  private load(): void {
    this.recruiting.channels(true).subscribe({ next: (list) => this.channels.set(list), error: (e: unknown) => this.toast(e) });
  }

  private replace(saved: AcquisitionChannel): void {
    this.channels.update((list) => list.map((c) => (c.id === saved.id ? saved : c)));
  }

  private toast(e: unknown): void {
    this.snack.open(this.i18n.translate(channelErrorKey(e)), undefined, { duration: 4000 });
  }
}
