import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatButtonToggleModule } from '@angular/material/button-toggle';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { MatSnackBar } from '@angular/material/snack-bar';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { AnonymousReport, REPORT_CATEGORIES, ReportCategory } from './safe-speak.model';
import { SafeSpeakService, safeSpeakErrorKey } from './safe-speak.service';
import { SafeSpeakThread } from './thread';

/**
 * Safe Speak (/safe-speak): send an anonymous report and get an access code shown ONCE; follow up with the code.
 * Nothing about the sender is sent or stored; the code is kept only on this screen until it is left.
 */
@Component({
  selector: 'app-safe-speak-page',
  imports: [MatButtonModule, MatButtonToggleModule, MatFormFieldModule, MatIconModule, MatInputModule, MatSelectModule, TranslocoPipe, SafeSpeakThread],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="page-head">
      <div>
        <h1>{{ 'safeSpeak.title' | transloco }}</h1>
        <p class="muted">{{ 'safeSpeak.subtitle' | transloco }}</p>
      </div>
    </header>
    <mat-button-toggle-group [value]="mode()" (change)="mode.set($event.value)" hideSingleSelectionIndicator>
      <mat-button-toggle value="new">{{ 'safeSpeak.new' | transloco }}</mat-button-toggle>
      <mat-button-toggle value="follow">{{ 'safeSpeak.follow' | transloco }}</mat-button-toggle>
    </mat-button-toggle-group>

    @if (mode() === 'new') {
      @if (issuedCode(); as code) {
        <section class="panel code" role="alert">
          <mat-icon>key</mat-icon>
          <div>
            <p>{{ 'safeSpeak.codeOnce' | transloco }}</p>
            <p class="value" data-testid="access-code">{{ code }}</p>
            <button mat-stroked-button type="button" (click)="copy(code)"><mat-icon>content_copy</mat-icon>{{ 'safeSpeak.copy' | transloco }}</button>
            <button mat-button type="button" (click)="issuedCode.set(null)">{{ 'safeSpeak.saved' | transloco }}</button>
          </div>
        </section>
      } @else {
        <form class="panel form" (submit)="$event.preventDefault(); submit()">
          <p class="muted small"><mat-icon inline>visibility_off</mat-icon> {{ 'safeSpeak.privacy' | transloco }}</p>
          <mat-form-field subscriptSizing="dynamic">
            <mat-label>{{ 'safeSpeak.category' | transloco }}</mat-label>
            <mat-select [value]="category()" (valueChange)="category.set($event)" required>
              @for (c of categories; track c) {
                <mat-option [value]="c">{{ 'safeSpeak.categories.' + c | transloco }}</mat-option>
              }
            </mat-select>
          </mat-form-field>
          <mat-form-field subscriptSizing="dynamic">
            <mat-label>{{ 'safeSpeak.subject' | transloco }}</mat-label>
            <input matInput maxlength="200" required [value]="subject()" (input)="subject.set(val($event))" />
          </mat-form-field>
          <mat-form-field subscriptSizing="dynamic">
            <mat-label>{{ 'safeSpeak.body' | transloco }}</mat-label>
            <textarea matInput rows="6" maxlength="10000" required [value]="body()" (input)="body.set(val($event))"></textarea>
          </mat-form-field>
          <div class="actions">
            <button mat-flat-button type="submit" [disabled]="busy() || !category() || subject().trim() === '' || body().trim() === ''">{{ 'safeSpeak.send' | transloco }}</button>
          </div>
        </form>
      }
    } @else {
      <form class="panel form" (submit)="$event.preventDefault(); open()">
        <mat-form-field subscriptSizing="dynamic">
          <mat-label>{{ 'safeSpeak.code' | transloco }}</mat-label>
          <input matInput autocomplete="off" maxlength="40" [value]="code()" (input)="code.set(val($event))" placeholder="XXXX-XXXX-XXXX-XXXX" />
        </mat-form-field>
        <div class="actions"><button mat-flat-button type="submit" [disabled]="busy() || code().trim() === ''">{{ 'safeSpeak.open' | transloco }}</button></div>
      </form>
      @if (report(); as r) {
        <section class="panel view">
          <h2>{{ r.subject }}</h2>
          <p class="muted">{{ 'safeSpeak.categories.' + r.category | transloco }} · {{ 'safeSpeak.status.' + r.status | transloco }}</p>
          <app-safe-speak-thread [messages]="r.messages" />
          @if (r.status !== 'closed') {
            <form class="reply" (submit)="$event.preventDefault(); reply()">
              <mat-form-field subscriptSizing="dynamic">
                <mat-label>{{ 'safeSpeak.reply' | transloco }}</mat-label>
                <textarea matInput rows="3" maxlength="10000" [value]="replyText()" (input)="replyText.set(val($event))"></textarea>
              </mat-form-field>
              <div class="actions"><button mat-flat-button type="submit" [disabled]="busy() || replyText().trim() === ''">{{ 'safeSpeak.send' | transloco }}</button></div>
            </form>
          }
        </section>
      }
    }
  `,
  styles: `
    mat-button-toggle-group { margin-bottom: 1rem; }
    .form, .view { display: flex; flex-direction: column; gap: 0.75rem; padding: 1rem; margin-bottom: 1rem; }
    .view h2 { font: var(--mat-sys-title-medium); margin: 0; }
    .reply { display: flex; flex-direction: column; gap: 0.5rem; }
    .actions { display: flex; justify-content: flex-end; gap: 0.5rem; }
    .code { display: flex; gap: 1rem; padding: 1rem; border-color: var(--app-warning); }
    .value { font: var(--mat-sys-headline-small); font-family: ui-monospace, monospace; letter-spacing: 0.08em; user-select: all; }
    .small { font-size: 0.85rem; }
  `,
})
export class SafeSpeakPage {
  private readonly api = inject(SafeSpeakService);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  protected readonly categories = REPORT_CATEGORIES;
  protected readonly mode = signal<'new' | 'follow'>('new');
  protected readonly category = signal<ReportCategory | null>(null);
  protected readonly subject = signal('');
  protected readonly body = signal('');
  /** Shown once after sending; never stored in the browser. */
  protected readonly issuedCode = signal<string | null>(null);
  protected readonly code = signal('');
  protected readonly report = signal<AnonymousReport | null>(null);
  protected readonly replyText = signal('');
  protected readonly busy = signal(false);

  protected val(event: Event): string {
    return (event.target as HTMLInputElement | HTMLTextAreaElement).value;
  }

  protected submit(): void {
    const category = this.category();
    if (category === null) {
      return;
    }
    this.busy.set(true);
    this.api.submit({ category, subject: this.subject().trim(), body: this.body() }).subscribe({
      next: (res) => {
        this.busy.set(false);
        this.issuedCode.set(res.code);
        this.category.set(null);
        this.subject.set('');
        this.body.set('');
      },
      error: (e: unknown) => this.fail(e),
    });
  }

  protected open(): void {
    this.busy.set(true);
    this.api.followUp(this.code()).subscribe({
      next: (r) => {
        this.busy.set(false);
        this.report.set(r);
      },
      error: (e: unknown) => {
        this.report.set(null);
        this.fail(e);
      },
    });
  }

  protected reply(): void {
    this.busy.set(true);
    this.api.reply(this.code(), this.replyText()).subscribe({
      next: (r) => {
        this.busy.set(false);
        this.report.set(r);
        this.replyText.set('');
      },
      error: (e: unknown) => this.fail(e),
    });
  }

  protected copy(code: string): void {
    void navigator.clipboard?.writeText(code).then(() => this.snack.open(this.i18n.translate('safeSpeak.copied'), undefined, { duration: 2000 }));
  }

  private fail(e: unknown): void {
    this.busy.set(false);
    this.snack.open(this.i18n.translate(safeSpeakErrorKey(e)), undefined, { duration: 5000 });
  }
}
