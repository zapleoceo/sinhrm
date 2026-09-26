import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatDatepickerModule } from '@angular/material/datepicker';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSelectModule } from '@angular/material/select';
import { MatTimepickerModule } from '@angular/material/timepicker';
import { MatSnackBar } from '@angular/material/snack-bar';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { ListItem, ONE_ON_ONE_STATUSES, OneOnOne, OneOnOnePatch, OneOnOneTemplate, itemsBody, newItem, splitMeetings, toggleItem } from '../perform.model';
import { PerformService, performErrorKey } from '../perform.service';
import { combineDateAndTime, toIsoDateOrNull, toIsoLocalDateTime } from '../../../core/date/iso-date';

/**
 * 1:1 meetings (/perform/one-on-ones): upcoming and past; the selected meeting with its agenda, shared notes,
 * the manager's private notes (only the meeting's manager gets them from the API) and action items.
 */
@Component({
  selector: 'app-one-on-ones-page',
  imports: [DatePipe, FormsModule, MatButtonModule, MatCheckboxModule, MatDatepickerModule, MatFormFieldModule, MatIconModule, MatInputModule, MatProgressBarModule, MatSelectModule, MatTimepickerModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="page-head">
      <div>
        <h1>{{ 'perform.oneOnOnes.title' | transloco }}</h1>
        <p class="muted">{{ 'perform.oneOnOnes.subtitle' | transloco }}</p>
      </div>
    </header>

    <form class="filters" (ngSubmit)="create()">
      <mat-form-field subscriptSizing="dynamic" class="narrow">
        <mat-label>{{ 'perform.fields.employeeId' | transloco }}</mat-label>
        <input matInput type="number" min="1" name="employee" [(ngModel)]="newEmployee" required />
      </mat-form-field>
      <mat-form-field subscriptSizing="dynamic">
        <mat-label>{{ 'perform.fields.when' | transloco }}</mat-label>
        <input matInput [matDatepicker]="whenDay" name="when" [(ngModel)]="newDay" required />
        <mat-datepicker-toggle matIconSuffix [for]="whenDay" />
        <mat-datepicker #whenDay />
      </mat-form-field>
      <mat-form-field subscriptSizing="dynamic" class="narrow">
        <mat-label>{{ 'common.datepicker.time' | transloco }}</mat-label>
        <input matInput [matTimepicker]="whenTime" name="whenTime" [(ngModel)]="newTime" required />
        <mat-timepicker-toggle matIconSuffix [for]="whenTime" [attr.aria-label]="'common.datepicker.openTime' | transloco" />
        <mat-timepicker #whenTime interval="15m" />
      </mat-form-field>
      <mat-form-field subscriptSizing="dynamic">
        <mat-label>{{ 'perform.fields.template' | transloco }}</mat-label>
        <mat-select name="template" [(ngModel)]="newTemplate">
          <mat-option [value]="null">—</mat-option>
          @for (t of templates(); track t.id) {
            <mat-option [value]="t.id">{{ t.name }}</mat-option>
          }
        </mat-select>
      </mat-form-field>
      <button mat-flat-button type="submit" [disabled]="!newEmployee || !newDay || !newTime"><mat-icon>add</mat-icon>{{ 'perform.oneOnOnes.schedule' | transloco }}</button>
    </form>

    @if (loading()) {
      <mat-progress-bar mode="indeterminate" />
    }
    <div class="layout">
      <nav class="list" [attr.aria-label]="'perform.oneOnOnes.title' | transloco">
        <h2>{{ 'perform.oneOnOnes.upcoming' | transloco }}</h2>
        @for (m of groups().upcoming; track m.id) {
          <button type="button" class="row" [class.active]="m.id === selected()?.id" (click)="select(m)">
            <strong>{{ m.employee.full_name }}</strong>
            <span class="muted">{{ m.manager.full_name }} · {{ m.scheduled_at | date: 'dd.MM HH:mm' }}</span>
          </button>
        } @empty {
          <p class="muted">{{ 'perform.oneOnOnes.none' | transloco }}</p>
        }
        <h2>{{ 'perform.oneOnOnes.past' | transloco }}</h2>
        @for (m of groups().past; track m.id) {
          <button type="button" class="row" [class.active]="m.id === selected()?.id" (click)="select(m)">
            <strong>{{ m.employee.full_name }}</strong>
            <span class="muted">{{ m.scheduled_at | date: 'dd.MM.yyyy' }} · {{ 'perform.oneOnOneStatus.' + m.status | transloco }}</span>
          </button>
        }
      </nav>

      @if (selected(); as m) {
        <section class="panel detail">
          <header class="detail-head">
            <div>
              <h2>{{ m.manager.full_name }} ↔ {{ m.employee.full_name }}</h2>
              <p class="muted">{{ m.scheduled_at | date: 'dd.MM.yyyy HH:mm' }}</p>
            </div>
            @if (m.can_manage) {
              <mat-form-field subscriptSizing="dynamic">
                <mat-label>{{ 'perform.fields.status' | transloco }}</mat-label>
                <mat-select [value]="m.status" (selectionChange)="save({ status: $event.value })">
                  @for (s of statuses; track s) {
                    <mat-option [value]="s">{{ 'perform.oneOnOneStatus.' + s | transloco }}</mat-option>
                  }
                </mat-select>
              </mat-form-field>
            }
          </header>

          <h3>{{ 'perform.oneOnOnes.agenda' | transloco }}</h3>
          <ul class="items">
            @for (i of m.agenda; track i.id) {
              <li><mat-checkbox [checked]="i.done" (change)="toggle('agenda', i)">{{ i.text }}</mat-checkbox></li>
            }
          </ul>
          <div class="add">
            <mat-form-field subscriptSizing="dynamic" class="grow">
              <mat-label>{{ 'perform.oneOnOnes.addAgenda' | transloco }}</mat-label>
              <input matInput [(ngModel)]="agendaText" (keydown.enter)="add('agenda')" />
            </mat-form-field>
            <button mat-icon-button type="button" (click)="add('agenda')" [attr.aria-label]="'perform.oneOnOnes.addAgenda' | transloco"><mat-icon>add</mat-icon></button>
          </div>

          <mat-form-field class="full">
            <mat-label>{{ 'perform.oneOnOnes.sharedNotes' | transloco }}</mat-label>
            <textarea matInput rows="4" [(ngModel)]="shared"></textarea>
          </mat-form-field>
          @if (m.can_private_notes) {
            <mat-form-field class="full private">
              <mat-label><mat-icon inline>lock</mat-icon> {{ 'perform.oneOnOnes.privateNotes' | transloco }}</mat-label>
              <textarea matInput rows="3" [(ngModel)]="privateNotes"></textarea>
              <mat-hint>{{ 'perform.oneOnOnes.privateHint' | transloco }}</mat-hint>
            </mat-form-field>
          }
          <button mat-stroked-button type="button" (click)="saveNotes(m)">{{ 'perform.save' | transloco }}</button>

          <h3>{{ 'perform.oneOnOnes.actionItems' | transloco }}</h3>
          <ul class="items">
            @for (i of m.action_items; track i.id) {
              <li>
                <mat-checkbox [checked]="i.done" (change)="toggle('action_items', i)">{{ i.text }}</mat-checkbox>
                @if (i.due_on) {
                  <span class="muted">· {{ i.due_on | date: 'dd.MM' }}</span>
                }
              </li>
            }
          </ul>
          <div class="add">
            <mat-form-field subscriptSizing="dynamic" class="grow">
              <mat-label>{{ 'perform.oneOnOnes.addAction' | transloco }}</mat-label>
              <input matInput [(ngModel)]="actionText" (keydown.enter)="add('action_items')" />
            </mat-form-field>
            <mat-form-field subscriptSizing="dynamic">
              <mat-label>{{ 'perform.fields.due' | transloco }}</mat-label>
              <input matInput [matDatepicker]="dp1" [(ngModel)]="actionDue" /><mat-datepicker-toggle matIconSuffix [for]="dp1" /><mat-datepicker #dp1 />
            </mat-form-field>
            <button mat-icon-button type="button" (click)="add('action_items')" [attr.aria-label]="'perform.oneOnOnes.addAction' | transloco"><mat-icon>add</mat-icon></button>
          </div>
        </section>
      } @else {
        <p class="muted state">{{ 'perform.oneOnOnes.pick' | transloco }}</p>
      }
    </div>
  `,
  styles: `
    .layout { display: grid; grid-template-columns: minmax(14rem, 20rem) 1fr; gap: 1rem; }
    @media (max-width: 800px) { .layout { grid-template-columns: 1fr; } }
    .list { display: flex; flex-direction: column; gap: 0.25rem; }
    .list h2 { font: var(--mat-sys-title-small); margin: 0.75rem 0 0.25rem; }
    .row { display: flex; flex-direction: column; align-items: flex-start; gap: 0.1rem; padding: 0.5rem 0.75rem; border: 1px solid var(--app-border); border-radius: 8px; background: none; color: inherit; font: inherit; cursor: pointer; text-align: left; }
    .row.active { border-color: var(--mat-sys-primary); background: var(--mat-sys-secondary-container); }
    .detail { padding: 1rem; }
    .detail-head { display: flex; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
    .detail-head h2 { margin: 0; font: var(--mat-sys-title-medium); }
    .items { list-style: none; margin: 0; padding: 0; }
    .add { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; }
    .grow { flex: 1 1 12rem; }
    .full { width: 100%; margin-top: 1rem; }
    .private { background: color-mix(in srgb, var(--app-warning) 6%, transparent); border-radius: 8px; }
    .narrow { width: 9rem; }
  `,
})
export class OneOnOnesPage implements OnInit {
  private readonly api = inject(PerformService);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  protected readonly statuses = ONE_ON_ONE_STATUSES;
  protected readonly items = signal<OneOnOne[]>([]);
  protected readonly templates = signal<OneOnOneTemplate[]>([]);
  protected readonly loading = signal(false);
  protected readonly selectedId = signal<number | null>(null);
  protected readonly selected = computed(() => this.items().find((m) => m.id === this.selectedId()) ?? null);
  protected readonly groups = computed(() => splitMeetings(this.items(), new Date()));
  protected newEmployee: number | null = null;
  protected newDay: Date | null = null;
  protected newTime: Date | null = null;
  protected newTemplate: number | null = null;
  protected agendaText = '';
  protected actionText = '';
  protected actionDue: Date | null = null;
  protected shared = '';
  protected privateNotes = '';

  ngOnInit(): void {
    this.load();
    this.api.templates().subscribe({ next: (t) => this.templates.set(t), error: () => this.templates.set([]) });
  }

  protected load(): void {
    this.loading.set(true);
    this.api.oneOnOnes().subscribe({
      next: (list) => {
        this.items.set(list);
        this.loading.set(false);
      },
      error: (e: unknown) => {
        this.loading.set(false);
        this.toast(performErrorKey(e));
      },
    });
  }

  protected select(m: OneOnOne): void {
    this.selectedId.set(m.id);
    this.shared = m.notes_shared ?? '';
    this.privateNotes = m.notes_private_manager ?? '';
  }

  protected create(): void {
    const when = toIsoLocalDateTime(combineDateAndTime(this.newDay, this.newTime));
    if (!this.newEmployee || !when) {
      return;
    }
    this.api.createOneOnOne({ employee_id: Number(this.newEmployee), scheduled_at: when, template_id: this.newTemplate }).subscribe({
      next: (m) => {
        this.items.update((list) => [m, ...list]);
        this.select(m);
        this.newEmployee = null;
        this.toast('perform.oneOnOnes.created');
      },
      error: (e: unknown) => this.toast(performErrorKey(e)),
    });
  }

  protected toggle(list: 'agenda' | 'action_items', item: ListItem): void {
    const m = this.selected();
    if (m) {
      this.save({ [list]: itemsBody(toggleItem(m[list], item.id)) as ListItem[] });
    }
  }

  protected add(list: 'agenda' | 'action_items'): void {
    const m = this.selected();
    const text = list === 'agenda' ? this.agendaText : this.actionText;
    if (!m || !text.trim()) {
      return;
    }
    const item = newItem(text, list === 'action_items' ? toIsoDateOrNull(this.actionDue) : null);
    this.save({ [list]: itemsBody([...m[list], item]) as ListItem[] });
    this.agendaText = list === 'agenda' ? '' : this.agendaText;
    this.actionText = list === 'action_items' ? '' : this.actionText;
  }

  protected saveNotes(m: OneOnOne): void {
    this.save({ notes_shared: this.shared || null, ...(m.can_private_notes ? { notes_private_manager: this.privateNotes || null } : {}) }, 'perform.saved');
  }

  protected save(patch: OneOnOnePatch, doneKey?: string): void {
    const m = this.selected();
    if (!m) {
      return;
    }
    this.api.updateOneOnOne(m.id, patch).subscribe({
      next: (saved) => {
        this.items.update((list) => list.map((x) => (x.id === saved.id ? saved : x)));
        if (doneKey) {
          this.toast(doneKey);
        }
      },
      error: (e: unknown) => this.toast(performErrorKey(e)),
    });
  }

  private toast(key: string): void {
    this.snack.open(this.i18n.translate(key), undefined, { duration: 4000 });
  }
}
