import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatButtonToggleModule } from '@angular/material/button-toggle';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { Router, RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { ClientColumn, ClientTable, TEXT_FILTER, translatedSelect } from '../../../core/ui/table/client-table';
import { ColumnHeader } from '../../../core/ui/table/column-header';
import { TableSortDirective } from '../../../core/ui/table/table-sort.directive';
import { TableUrlState } from '../../../core/ui/table/table-url-state';
import { SCRIPT_CHANNELS, Script, ScriptChannel } from '../scripts.model';

const PRESENCE = ['yes', 'no'] as const;
const presence = (v: unknown): 'yes' | 'no' => (v ? 'yes' : 'no');

/**
 * Columns of the scripts list (all rows are on the page). Versions sort by their date (published / last saved),
 * a script without one goes last; their filter is «есть / нет».
 */
export const SCRIPT_COLUMNS: readonly ClientColumn<Script>[] = [
  { key: 'name', value: (s) => s.name, filter: 'text' },
  { key: 'channel', value: (s) => SCRIPT_CHANNELS.indexOf(s.channel), filter: 'select', filterValue: (s) => s.channel },
  { key: 'active', value: (s) => s.active_version?.published_at, filter: 'select', filterValue: (s) => presence(s.active_version) },
  { key: 'draft', value: (s) => s.draft?.updated_at, filter: 'select', filterValue: (s) => presence(s.draft) },
];
import { ScriptsService, scriptsErrorKey } from '../scripts.service';
import { PagedList } from '../../../core/ui/table/paged-list';
import { NotifyService } from '../../../core/ui/notify.service';

/** Admin → Скрипти: all scripts with their active version / draft state (sortable / filterable headers), creation of a new one. */
@Component({
  selector: 'app-scripts-page',
  imports: [
    DatePipe,
    ReactiveFormsModule,
    MatButtonModule,
    MatButtonToggleModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatProgressBarModule,
    MatSlideToggleModule,
    RouterLink,
    TranslocoPipe,
    TableSortDirective,
    ColumnHeader,
  ],
  providers: [TableUrlState],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="page-head">
      <div>
        <h1>{{ 'scripts.title' | transloco }}</h1>
        <p class="muted">{{ 'scripts.subtitle' | transloco }}</p>
      </div>
      <mat-slide-toggle [checked]="withArchived()" (change)="toggleArchived($event.checked)">{{ 'scripts.showArchived' | transloco }}</mat-slide-toggle>
    </header>

    <form class="filters" [formGroup]="form" (ngSubmit)="create()">
      <mat-form-field class="grow" subscriptSizing="dynamic">
        <mat-label>{{ 'scripts.newName' | transloco }}</mat-label>
        <input matInput formControlName="name" maxlength="200" autocomplete="off" />
      </mat-form-field>
      <mat-button-toggle-group formControlName="channel" [attr.aria-label]="'scripts.channelLabel' | transloco" hideSingleSelectionIndicator>
        @for (c of channels; track c) {
          <mat-button-toggle [value]="c">{{ 'scripts.channel.' + c | transloco }}</mat-button-toggle>
        }
      </mat-button-toggle-group>
      <button mat-flat-button type="submit" [disabled]="form.invalid || busy()"><mat-icon>add</mat-icon>{{ 'scripts.create' | transloco }}</button>
    </form>

    @if (loading()) {
      <mat-progress-bar mode="indeterminate" />
    }
    @if (failed()) {
      <div class="state">
        <p>{{ 'scripts.errors.generic' | transloco }}</p>
        <button mat-stroked-button type="button" (click)="load()">{{ 'common.retry' | transloco }}</button>
      </div>
    }
    <div class="panel">
      <table class="app-table" [appTableSort]="table.sort()" [appTableSortCount]="table.rows().length" (appTableSortChange)="table.setSort($event)">
        <thead>
          <tr>
            <th scope="col" app-column-header key="name" [label]="'scripts.name' | transloco"
              [filter]="textFilter" [filterValue]="table.filterValue('name')" (filterChange)="table.setFilter('name', $event)"></th>
            <th scope="col" app-column-header key="channel" [label]="'scripts.channelLabel' | transloco"
              [filter]="channelFilter()" [filterValue]="table.filterValue('channel')" (filterChange)="table.setFilter('channel', $event)"></th>
            <th scope="col" app-column-header key="active" [label]="'scripts.activeVersion' | transloco"
              [filter]="presenceFilter()" [filterValue]="table.filterValue('active')" (filterChange)="table.setFilter('active', $event)"></th>
            <th scope="col" app-column-header key="draft" [label]="'scripts.draft' | transloco"
              [filter]="presenceFilter()" [filterValue]="table.filterValue('draft')" (filterChange)="table.setFilter('draft', $event)"></th>
          </tr>
        </thead>
        <tbody>
          @for (s of table.rows(); track s.id) {
            <tr [class.archived]="s.archived">
              <th scope="row"><a [routerLink]="['/admin/scripts', s.id]">{{ s.name }}</a>
                @if (s.archived) {
                  <span class="muted"> · {{ 'scripts.archived' | transloco }}</span>
                }
              </th>
              <td>{{ 'scripts.channel.' + s.channel | transloco }}</td>
              <td>
                @if (s.active_version; as v) {
                  v{{ v.version }} · {{ v.published_at | date: 'dd.MM.yyyy' }}
                } @else {
                  <span class="muted">{{ 'scripts.notPublished' | transloco }}</span>
                }
              </td>
              <td>
                @if (s.draft; as d) {
                  v{{ d.version }} · {{ d.updated_at | date: 'dd.MM HH:mm' }}
                } @else {
                  <span class="muted">—</span>
                }
              </td>
            </tr>
          } @empty {
            @if (!loading()) {
              <tr><td colspan="4" class="muted">{{ (scripts().length ? 'table.noMatches' : 'scripts.empty') | transloco }}</td></tr>
            }
          }
        </tbody>
      </table>
    </div>
  `,
  styles: `
    /* Row header (the script name) reads as a cell, not as a column title of the global .app-table. */
    tbody th { font: inherit; color: inherit; white-space: normal; padding: 0.6rem 1rem; border-bottom-color: var(--app-track); }
    th a { color: inherit; font-weight: 500; }
    tr.archived { opacity: 0.6; }
  `,
})
export class ScriptsPage implements OnInit {
  private readonly api = inject(ScriptsService);
  private readonly router = inject(Router);
  private readonly notify = inject(NotifyService);

  protected readonly channels = SCRIPT_CHANNELS;
  private readonly list = new PagedList<Script>();
  protected readonly scripts = this.list.items;
  protected readonly loading = this.list.loading;
  protected readonly failed = this.list.failed;
  protected readonly busy = signal(false);
  protected readonly withArchived = signal(false);
  protected readonly form = inject(NonNullableFormBuilder).group({
    name: ['', [Validators.required, Validators.maxLength(200)]],
    channel: ['call' as ScriptChannel],
  });
  protected readonly table = new ClientTable({ rows: this.scripts, columns: SCRIPT_COLUMNS });
  protected readonly textFilter = TEXT_FILTER;
  protected readonly channelFilter = translatedSelect(() => SCRIPT_CHANNELS, (c) => 'scripts.channel.' + c);
  protected readonly presenceFilter = translatedSelect(() => PRESENCE, (v) => 'table.' + v);

  ngOnInit(): void {
    this.load();
  }

  protected load(): void {
    this.list.load(this.api.list(this.withArchived()));
  }

  protected toggleArchived(on: boolean): void {
    this.withArchived.set(on);
    this.load();
  }

  protected create(): void {
    const v = this.form.getRawValue();
    if (this.form.invalid || v.name.trim() === '') {
      return;
    }
    this.busy.set(true);
    this.api.create(v.name.trim(), v.channel).subscribe({
      next: (s) => {
        this.busy.set(false);
        void this.router.navigate(['/admin/scripts', s.id]);
      },
      error: (e: unknown) => {
        this.busy.set(false);
        this.notify.show(scriptsErrorKey(e), { duration: 3000 });
      },
    });
  }
}
