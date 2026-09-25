import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSelectModule } from '@angular/material/select';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { MatSnackBar } from '@angular/material/snack-bar';
import { RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { SHEET_FIELDS, SheetField, SheetImport, SheetImportReport, SheetInspection, SheetMapping, isSheetUrl } from './google.model';
import { GoogleService, googleErrorKey } from './google.service';

/**
 * Admin → Import from Google Sheets (superadmin): paste the sheet URL → read the header → map columns (suggested
 * automatically) → preview 10 rows → import (dedupe by phone / e-mail / Telegram). Saved imports can be re-run
 * for new rows only, by hand or every 30 minutes (auto sync).
 */
@Component({
  selector: 'app-sheets-import-page',
  imports: [
    DatePipe,
    FormsModule,
    MatButtonModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatProgressBarModule,
    MatSelectModule,
    MatSlideToggleModule,
    RouterLink,
    TranslocoPipe,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="head">
      <h1>{{ 'google.sheets.title' | transloco }}</h1>
      <p class="muted">{{ 'google.sheets.subtitle' | transloco }}</p>
    </header>

    <section class="panel">
      <div class="source">
        <mat-form-field class="url" subscriptSizing="dynamic">
          <mat-label>{{ 'google.sheets.url' | transloco }}</mat-label>
          <input matInput type="url" [ngModel]="url()" (ngModelChange)="url.set($event)" placeholder="https://docs.google.com/spreadsheets/d/…" />
        </mat-form-field>
        <mat-form-field subscriptSizing="dynamic">
          <mat-label>{{ 'google.sheets.sheet' | transloco }}</mat-label>
          <input matInput [ngModel]="sheet()" (ngModelChange)="sheet.set($event)" maxlength="100" />
        </mat-form-field>
        <button mat-stroked-button type="button" [disabled]="busy() || !validUrl()" (click)="inspect()">
          <mat-icon>table_view</mat-icon>{{ 'google.sheets.inspect' | transloco }}
        </button>
      </div>
      @if (url() && !validUrl()) {
        <p class="error small">{{ 'google.errors.invalid_sheet_url' | transloco }}</p>
      }
      @if (error(); as key) {
        <p class="error" role="alert">
          {{ key | transloco }}
          @if (key.endsWith('not_connected') || key.endsWith('reconnect_required')) {
            <a mat-button routerLink="/admin/integrations">{{ 'google.openIntegrations' | transloco }}</a>
          }
        </p>
      }
      @if (busy()) {
        <mat-progress-bar mode="indeterminate" />
      }
    </section>

    @if (inspection(); as ins) {
      <section class="panel">
        <h2>{{ 'google.sheets.mapping' | transloco }}</h2>
        <div class="mapping">
          @for (f of fields; track f) {
            <mat-form-field subscriptSizing="dynamic">
              <mat-label>{{ 'google.sheets.fields.' + f | transloco }}</mat-label>
              <mat-select [value]="mapping()[f] ?? null" (valueChange)="setColumn(f, $event)">
                <mat-option [value]="null">{{ 'google.sheets.none' | transloco }}</mat-option>
                @for (h of ins.headers; track $index) {
                  <mat-option [value]="$index">{{ h || ('google.sheets.column' | transloco: { n: $index + 1 }) }}</mat-option>
                }
              </mat-select>
            </mat-form-field>
          }
        </div>

        <h3>{{ 'google.sheets.preview' | transloco }}</h3>
        <div class="table-wrap">
          <table class="preview">
            <thead>
              <tr>
                @for (f of mappedFields(); track f) {
                  <th>{{ 'google.sheets.fields.' + f | transloco }}</th>
                }
              </tr>
            </thead>
            <tbody>
              @for (row of ins.rows; track $index) {
                <tr>
                  @for (f of mappedFields(); track f) {
                    <td>{{ row[mapping()[f] ?? -1] ?? '' }}</td>
                  }
                </tr>
              } @empty {
                <tr><td class="muted">{{ 'google.sheets.noRows' | transloco }}</td></tr>
              }
            </tbody>
          </table>
        </div>

        <div class="actions">
          <mat-slide-toggle [checked]="autoSync()" (change)="autoSync.set($event.checked)">
            {{ 'google.sheets.autoSync' | transloco }}
          </mat-slide-toggle>
          <span class="spacer"></span>
          <button mat-flat-button type="button" [disabled]="busy() || !canImport()" (click)="runImport()">
            <mat-icon>cloud_download</mat-icon>{{ 'google.sheets.import' | transloco }}
          </button>
        </div>
        @if (!canImport()) {
          <p class="muted small">{{ 'google.sheets.needContact' | transloco }}</p>
        }
      </section>
    }

    @if (report(); as r) {
      <section class="panel ok" role="status">
        <h2>{{ 'google.sheets.report.title' | transloco }}</h2>
        <p>
          {{ 'google.sheets.report.created' | transloco }}: <strong>{{ r.created }}</strong> ·
          {{ 'google.sheets.report.matched' | transloco }}: <strong>{{ r.matched }}</strong> ·
          {{ 'google.sheets.report.skipped' | transloco }}: <strong>{{ r.skipped }}</strong> ·
          {{ 'google.sheets.report.applied' | transloco }}: <strong>{{ r.applied }}</strong> ·
          {{ 'google.sheets.report.vacancy_unmatched' | transloco }}: <strong>{{ r.vacancy_unmatched }}</strong>
        </p>
        @if (r.errors.length) {
          <p>{{ 'google.sheets.report.errors' | transloco }}:</p>
          <ul>
            @for (e of r.errors; track e.row) {
              <li>{{ 'google.sheets.report.row' | transloco: { row: e.row } }} — {{ rowErrorKey(e.code) | transloco }}</li>
            }
          </ul>
        }
      </section>
    }

    <section class="panel">
      <h2>{{ 'google.sheets.saved' | transloco }}</h2>
      @for (imp of imports(); track imp.id) {
        <div class="saved">
          <a [href]="imp.url" target="_blank" rel="noopener noreferrer">{{ imp.spreadsheet_id }}</a>
          @if (imp.sheet) {
            <span class="muted">/ {{ imp.sheet }}</span>
          }
          <span class="muted">{{ 'google.sheets.lastRow' | transloco: { row: imp.last_row } }}</span>
          @if (imp.last_synced_at) {
            <time class="muted" [attr.datetime]="imp.last_synced_at">{{ imp.last_synced_at | date: 'dd.MM.yyyy HH:mm' }}</time>
          }
          <span class="spacer"></span>
          <mat-slide-toggle [checked]="imp.auto_sync" (change)="toggleAuto(imp, $event.checked)">
            {{ 'google.sheets.auto' | transloco }}
          </mat-slide-toggle>
          <button mat-stroked-button type="button" [disabled]="busy()" (click)="rerun(imp)">
            <mat-icon>sync</mat-icon>{{ 'google.sheets.run' | transloco }}
          </button>
        </div>
      } @empty {
        <p class="muted">{{ 'google.sheets.empty' | transloco }}</p>
      }
    </section>
  `,
  styles: `
    .panel { border: 1px solid var(--app-border); border-radius: 12px; padding: 1rem; margin-bottom: 1rem; }
    .panel.ok { border-color: var(--app-success); }
    .source, .actions, .saved { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; }
    .url { flex: 1; min-width: min(28rem, 100%); }
    .mapping { display: grid; grid-template-columns: repeat(auto-fill, minmax(12rem, 1fr)); gap: 0.75rem; }
    .table-wrap { overflow-x: auto; margin-bottom: 1rem; }
    .preview { border-collapse: collapse; width: 100%; font-size: 0.85rem; }
    .preview th, .preview td { border-bottom: 1px solid var(--app-border); padding: 0.3rem 0.5rem; text-align: left; white-space: nowrap; }
    .spacer { flex: 1; }
    .saved { padding: 0.5rem 0; border-bottom: 1px solid var(--app-border); }
    .error { color: var(--app-danger); }
    .small { font-size: 0.8rem; }
  `,
})
export class SheetsImportPage implements OnInit {
  private readonly api = inject(GoogleService);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);

  protected readonly fields = SHEET_FIELDS;
  protected readonly url = signal('');
  protected readonly sheet = signal('');
  protected readonly autoSync = signal(false);
  protected readonly busy = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly inspection = signal<SheetInspection | null>(null);
  protected readonly mapping = signal<SheetMapping>({});
  protected readonly report = signal<SheetImportReport | null>(null);
  protected readonly imports = signal<SheetImport[]>([]);

  protected readonly validUrl = computed(() => isSheetUrl(this.url()));
  protected readonly mappedFields = computed(() => this.fields.filter((f) => this.mapping()[f] !== undefined));
  /** The backend dedupes by contacts: at least one contact column must be mapped. */
  protected readonly canImport = computed(() => {
    const m = this.mapping();
    return m.phone !== undefined || m.email !== undefined || m.telegram !== undefined;
  });

  ngOnInit(): void {
    this.loadImports();
  }

  protected inspect(): void {
    this.start();
    this.api.inspectSheet(this.url().trim(), this.sheet().trim()).subscribe({
      next: (ins) => {
        this.inspection.set(ins);
        this.mapping.set({ ...ins.suggested });
        this.busy.set(false);
      },
      error: (e: unknown) => this.fail(e),
    });
  }

  protected setColumn(field: SheetField, index: number | null): void {
    this.mapping.update((m) => {
      const next = { ...m };
      if (index === null) {
        delete next[field];
      } else {
        next[field] = index;
      }
      return next;
    });
  }

  protected runImport(): void {
    this.start();
    this.api
      .saveImport({ url: this.url().trim(), sheet: this.sheet().trim(), mapping: this.mapping(), auto_sync: this.autoSync() })
      .subscribe({
        next: (r) => {
          this.report.set(r.report);
          this.busy.set(false);
          this.loadImports();
        },
        error: (e: unknown) => this.fail(e),
      });
  }

  protected rerun(imp: SheetImport): void {
    this.start();
    this.api.runImport(imp.id).subscribe({
      next: (r) => {
        this.report.set(r.report);
        this.busy.set(false);
        this.loadImports();
      },
      error: (e: unknown) => this.fail(e),
    });
  }

  protected toggleAuto(imp: SheetImport, on: boolean): void {
    this.api.updateImport(imp.id, { auto_sync: on }).subscribe({
      next: (saved) => this.imports.update((list) => list.map((i) => (i.id === saved.id ? saved : i))),
      error: (e: unknown) => this.snack.open(this.i18n.translate(googleErrorKey(e)), undefined, { duration: 3000 }),
    });
  }

  protected rowErrorKey(code: string): string {
    return ['full_name_required', 'row_failed'].includes(code) ? `google.sheets.rowErrors.${code}` : 'google.sheets.rowErrors.row_failed';
  }

  private loadImports(): void {
    this.api.imports().subscribe({ next: (list) => this.imports.set(list), error: () => this.imports.set([]) });
  }

  private start(): void {
    this.busy.set(true);
    this.error.set(null);
    this.report.set(null);
  }

  private fail(e: unknown): void {
    this.busy.set(false);
    this.error.set(googleErrorKey(e));
  }
}
