import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSnackBar } from '@angular/material/snack-bar';
import { RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { saveBlob } from '../../core/http/api-error';
import { CatalogGroup, SavedReport } from './reports.model';
import { ReportsService, reportsErrorKey } from './reports.service';

/** Report catalog (/reports/catalog): ready reports grouped (only those the user may run), saved reports, builder. */
@Component({
  selector: 'app-report-catalog-page',
  imports: [DatePipe, MatButtonModule, MatIconModule, MatProgressBarModule, RouterLink, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="page-head">
      <div>
        <h1>{{ 'reports.title' | transloco }}</h1>
        <p class="muted">{{ 'reports.subtitle' | transloco }}</p>
      </div>
      <a mat-flat-button routerLink="/reports/builder"><mat-icon>tune</mat-icon>{{ 'reports.builder.title' | transloco }}</a>
    </header>
    @if (loading()) {
      <mat-progress-bar mode="indeterminate" />
    }
    <div class="grid">
      @for (g of groups(); track g.group) {
        <section class="panel card">
          <h2>{{ 'reports.groups.' + g.group | transloco }}</h2>
          <ul>
            @for (r of g.reports; track r.key) {
              <li><a [routerLink]="['/reports/catalog', r.key]">{{ 'reports.names.' + r.key | transloco }}</a></li>
            }
          </ul>
        </section>
      }
      <section class="panel card">
        <h2>{{ 'reports.saved.title' | transloco }}</h2>
        <ul>
          @for (s of saved(); track s.id) {
            <li class="saved">
              @if (s.kind === 'catalog') {
                <a [routerLink]="['/reports/catalog', catalogKey(s)]" [queryParams]="catalogFilters(s)">{{ s.name }}</a>
              } @else {
                <a routerLink="/reports/builder" [queryParams]="{ saved: s.id }">{{ s.name }}</a>
              }
              <span class="muted small">{{ s.updated_at | date: 'dd.MM.yyyy' }}</span>
              <button mat-icon-button type="button" (click)="csv(s)" [attr.aria-label]="'reports.csv' | transloco"><mat-icon>download</mat-icon></button>
              <button mat-icon-button type="button" (click)="remove(s)" [attr.aria-label]="'reports.saved.delete' | transloco"><mat-icon>delete</mat-icon></button>
            </li>
          } @empty {
            <li class="muted">{{ 'reports.saved.empty' | transloco }}</li>
          }
        </ul>
      </section>
    </div>
    <p class="muted small">{{ 'reports.payGapNote' | transloco }}</p>
  `,
  styles: `
    .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(18rem, 1fr)); gap: var(--app-gap); margin-bottom: 1rem; }
    .card { padding: 1rem; }
    h2 { font: var(--mat-sys-title-medium); margin: 0 0 0.5rem; }
    ul { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0.35rem; }
    .saved { display: flex; align-items: center; gap: 0.25rem; }
    .saved a { flex: 1; }
    .small { font-size: 0.8rem; }
  `,
})
export class ReportCatalogPage implements OnInit {
  private readonly api = inject(ReportsService);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  protected readonly groups = signal<CatalogGroup[]>([]);
  protected readonly saved = signal<SavedReport[]>([]);
  protected readonly loading = signal(false);

  ngOnInit(): void {
    this.loading.set(true);
    this.api.catalog().subscribe({
      next: (g) => {
        this.groups.set(g);
        this.loading.set(false);
      },
      error: (e: unknown) => {
        this.loading.set(false);
        this.toast(reportsErrorKey(e));
      },
    });
    this.api.saved().subscribe({ next: (s) => this.saved.set(s), error: () => this.saved.set([]) });
  }

  protected catalogKey(s: SavedReport): string {
    return 'key' in s.definition ? s.definition.key : '';
  }

  protected catalogFilters(s: SavedReport): Record<string, string> {
    return 'key' in s.definition ? { ...s.definition.filters } : {};
  }

  protected csv(s: SavedReport): void {
    this.api.savedCsv(s.id).subscribe({ next: (blob) => saveBlob(blob, `${s.name}.csv`), error: (e: unknown) => this.toast(reportsErrorKey(e)) });
  }

  protected remove(s: SavedReport): void {
    this.api.remove(s.id).subscribe({
      next: () => this.saved.update((list) => list.filter((x) => x.id !== s.id)),
      error: (e: unknown) => this.toast(reportsErrorKey(e)),
    });
  }

  private toast(key: string): void {
    this.snack.open(this.i18n.translate(key), undefined, { duration: 4000 });
  }
}
