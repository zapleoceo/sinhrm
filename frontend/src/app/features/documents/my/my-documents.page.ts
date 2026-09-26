import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatDialog } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { TranslocoPipe } from '@jsverse/transloco';
import { DocumentViewDialog } from '../document-view.dialog';
import { HrDocument } from '../documents.model';
import { DocumentsService } from '../documents.service';

/** "Мої документи" (/me/documents): own documents; open one to acknowledge or reject it. */
@Component({
  selector: 'app-my-documents-page',
  imports: [DatePipe, MatButtonModule, MatIconModule, MatProgressBarModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="page-head">
      <div>
        <h1>{{ 'documents.my.title' | transloco }}</h1>
        <p class="muted">{{ 'documents.my.subtitle' | transloco }}</p>
      </div>
    </header>
    @if (loading()) {
      <mat-progress-bar mode="indeterminate" />
    }
    @if (failed()) {
      <div class="state">
        <p>{{ 'common.error' | transloco }}</p>
        <button mat-stroked-button type="button" (click)="load()">{{ 'common.retry' | transloco }}</button>
      </div>
    }
    <ul class="docs panel">
      @for (d of items(); track d.id) {
        <li [attr.data-status]="d.status">
          <div class="main">
            <strong>{{ d.title }}</strong>
            <span class="muted small">
              {{ d.category ?? '—' }}
              @if (d.sent_at) { · {{ d.sent_at | date: 'dd.MM.yyyy' }} }
            </span>
          </div>
          <span class="status">{{ 'documents.status.' + d.status | transloco }}</span>
          <button mat-stroked-button type="button" (click)="view(d)">
            <mat-icon>{{ d.can_acknowledge ? 'task_alt' : 'visibility' }}</mat-icon>{{ (d.can_acknowledge ? 'documents.my.review' : 'documents.actions.view') | transloco }}
          </button>
        </li>
      } @empty {
        @if (!loading() && !failed()) {
          <li class="muted">{{ 'documents.empty' | transloco }}</li>
        }
      }
    </ul>
  `,
  styles: `
    .docs { list-style: none; margin: 0; padding: 0; }
    .docs li { display: flex; gap: 1rem; align-items: center; padding: 0.6rem 0.75rem; border-bottom: 1px solid var(--app-border); flex-wrap: wrap; }
    .docs li:last-child { border-bottom: 0; }
    .main { flex: 1 1 14rem; display: flex; flex-direction: column; min-width: 0; }
    .docs li[data-status='sent'] .status { color: var(--app-warning); }
    .docs li[data-status='signed'] .status { color: var(--app-success); }
    .docs li[data-status='rejected'] .status { color: var(--app-danger); }
    .small { font-size: 0.8rem; }
  `,
})
export class MyDocumentsPage implements OnInit {
  private readonly api = inject(DocumentsService);
  private readonly dialog = inject(MatDialog);
  protected readonly items = signal<HrDocument[]>([]);
  protected readonly loading = signal(false);
  protected readonly failed = signal(false);

  ngOnInit(): void {
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    this.failed.set(false);
    this.api.mine().subscribe({
      next: (list) => {
        this.items.set(list);
        this.loading.set(false);
      },
      error: () => {
        this.failed.set(true);
        this.loading.set(false);
      },
    });
  }

  protected view(d: HrDocument): void {
    this.dialog
      .open<DocumentViewDialog, number, HrDocument>(DocumentViewDialog, { data: d.id })
      .afterClosed()
      .subscribe((doc) => doc && this.items.update((list) => list.map((x) => (x.id === doc.id ? doc : x))));
  }
}
