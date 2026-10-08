import { ChangeDetectionStrategy, Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSelectModule } from '@angular/material/select';
import { TranslocoPipe } from '@jsverse/transloco';
import { Objective, VISIBILITIES, Visibility, keyResultRatio, objectiveTree, progressTone, quarterOf, quarterOptions } from '../perform.model';
import { PerformService, performErrorKey } from '../perform.service';
import { NotifyService } from '../../../core/ui/notify.service';
import { PagedList } from '../../../core/ui/table/paged-list';

interface KrDraft {
  title: string;
  start: number;
  target: number;
  unit: string;
}

/**
 * Objectives (/perform/objectives): the alignment tree of the period with progress bars; new objective with key
 * results; check-in (new current values + comment) on objectives the user may edit.
 */
@Component({
  selector: 'app-objectives-page',
  imports: [FormsModule, MatButtonModule, MatFormFieldModule, MatIconModule, MatInputModule, MatProgressBarModule, MatSelectModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="page-head">
      <div>
        <h1>{{ 'perform.objectives.title' | transloco }}</h1>
        <p class="muted">{{ 'perform.objectives.subtitle' | transloco }}</p>
      </div>
      <mat-form-field subscriptSizing="dynamic">
        <mat-label>{{ 'perform.fields.period' | transloco }}</mat-label>
        <mat-select [value]="period()" (selectionChange)="setPeriod($event.value)">
          @for (p of periods; track p) {
            <mat-option [value]="p">{{ p }}</mat-option>
          }
        </mat-select>
      </mat-form-field>
    </header>

    <details class="panel create">
      <summary>{{ 'perform.objectives.new' | transloco }}</summary>
      <form (ngSubmit)="create()">
        <div class="filters">
          <mat-form-field subscriptSizing="dynamic" class="grow">
            <mat-label>{{ 'perform.fields.title' | transloco }}</mat-label>
            <input matInput name="title" [(ngModel)]="title" required />
          </mat-form-field>
          <mat-form-field subscriptSizing="dynamic">
            <mat-label>{{ 'perform.fields.visibility' | transloco }}</mat-label>
            <mat-select name="visibility" [(ngModel)]="visibility">
              @for (v of visibilities; track v) {
                <mat-option [value]="v">{{ 'perform.visibility.' + v | transloco }}</mat-option>
              }
            </mat-select>
          </mat-form-field>
          <mat-form-field subscriptSizing="dynamic">
            <mat-label>{{ 'perform.objectives.parent' | transloco }}</mat-label>
            <mat-select name="parent" [(ngModel)]="parent">
              <mat-option [value]="null">—</mat-option>
              @for (o of items(); track o.id) {
                <mat-option [value]="o.id">{{ o.title }}</mat-option>
              }
            </mat-select>
          </mat-form-field>
        </div>
        @for (kr of drafts(); track $index; let i = $index) {
          <div class="filters">
            <mat-form-field subscriptSizing="dynamic" class="grow">
              <mat-label>{{ 'perform.objectives.keyResult' | transloco }} {{ i + 1 }}</mat-label>
              <input matInput [name]="'kr' + i" [(ngModel)]="kr.title" required />
            </mat-form-field>
            <mat-form-field subscriptSizing="dynamic" class="num">
              <mat-label>{{ 'perform.objectives.start' | transloco }}</mat-label>
              <input matInput type="number" [name]="'s' + i" [(ngModel)]="kr.start" />
            </mat-form-field>
            <mat-form-field subscriptSizing="dynamic" class="num">
              <mat-label>{{ 'perform.objectives.target' | transloco }}</mat-label>
              <input matInput type="number" [name]="'t' + i" [(ngModel)]="kr.target" />
            </mat-form-field>
            <mat-form-field subscriptSizing="dynamic" class="num">
              <mat-label>{{ 'perform.objectives.unit' | transloco }}</mat-label>
              <input matInput [name]="'u' + i" [(ngModel)]="kr.unit" />
            </mat-form-field>
          </div>
        }
        <button mat-button type="button" (click)="addDraft()"><mat-icon>add</mat-icon>{{ 'perform.objectives.addKeyResult' | transloco }}</button>
        <button mat-flat-button type="submit" [disabled]="!title.trim()">{{ 'perform.save' | transloco }}</button>
      </form>
    </details>

    @if (loading()) {
      <mat-progress-bar mode="indeterminate" />
    }
    <ul class="tree">
      @for (n of tree(); track n.objective.id) {
        <li [style.padding-left.rem]="n.depth * 1.5" [class.child]="n.depth > 0">
          <div class="obj panel">
            <div class="obj-head">
              <strong>{{ n.objective.title }}</strong>
              <span class="muted">{{ n.objective.owner?.full_name ?? ('perform.scope.' + n.objective.scope | transloco) }} · {{ 'perform.visibility.' + n.objective.visibility | transloco }}</span>
              <span class="pct" [attr.data-tone]="tone(n.objective.progress)">{{ n.objective.progress }}%</span>
            </div>
            <div class="bar" role="progressbar" [attr.aria-valuenow]="n.objective.progress" aria-valuemin="0" aria-valuemax="100" [attr.aria-label]="n.objective.title">
              <span [style.width.%]="n.objective.progress" [attr.data-tone]="tone(n.objective.progress)"></span>
            </div>
            <ul class="krs">
              @for (kr of n.objective.key_results; track kr.id) {
                <li>
                  <span>{{ kr.title }}</span>
                  <span class="muted">{{ kr.current }} / {{ kr.target }} {{ kr.unit ?? '' }}</span>
                  <span class="mini"><span [style.width.%]="ratio(kr) * 100"></span></span>
                  @if (checkinId() === n.objective.id) {
                    <input class="cur" type="number" [attr.aria-label]="kr.title" [value]="values()[kr.id] ?? kr.current" (change)="setValue(kr.id, $event)" />
                  }
                </li>
              }
            </ul>
            @if (n.objective.can_edit) {
              @if (checkinId() === n.objective.id) {
                <div class="filters">
                  <mat-form-field subscriptSizing="dynamic" class="grow">
                    <mat-label>{{ 'perform.objectives.comment' | transloco }}</mat-label>
                    <input matInput [(ngModel)]="comment" />
                  </mat-form-field>
                  <button mat-flat-button type="button" (click)="checkIn(n.objective)">{{ 'perform.objectives.checkIn' | transloco }}</button>
                  <button mat-button type="button" (click)="checkinId.set(null)">{{ 'common.cancel' | transloco }}</button>
                </div>
              } @else {
                <button mat-button type="button" (click)="startCheckIn(n.objective)"><mat-icon>trending_up</mat-icon>{{ 'perform.objectives.checkIn' | transloco }}</button>
              }
            }
          </div>
        </li>
      } @empty {
        @if (!loading()) {
          <li class="panel app-empty">{{ 'perform.objectives.empty' | transloco }}</li>
        }
      }
    </ul>
  `,
  styles: `
    @use '../../../core/ui/styles/trace';
    .create { padding: 0.75rem 1.25rem; margin-bottom: 1rem; }
    .create summary { cursor: pointer; font-weight: 700; min-height: 2rem; display: flex; align-items: center; }
    .create form { margin-top: 0.75rem; }
    .num { width: 7rem; }
    .tree { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0.5rem; }
    /* Child objectives hang off their parent like a branch: a short track line into the card. */
    .tree li.child { position: relative; }
    .tree li.child::before {
      content: ''; position: absolute; top: -0.5rem; bottom: 50%; width: 0.75rem; margin-left: -0.9rem;
      border-left: 2px solid var(--app-track); border-bottom: 2px solid var(--app-track); border-bottom-left-radius: var(--app-radius-sm);
    }
    .obj { padding: 0.875rem 1.25rem; }
    .obj-head { display: flex; gap: 0.75rem; align-items: baseline; flex-wrap: wrap; }
    .pct { margin-left: auto; font: 600 0.9375rem var(--app-font-mono); font-variant-numeric: tabular-nums; }
    /* Progress = a route line: track + filled part in the tone colour, drawn once («trace», transform only). */
    .bar, .mini { display: block; height: 6px; border-radius: var(--app-radius-pill); background: var(--app-track); overflow: hidden; margin: 0.6rem 0; }
    .mini { width: 6rem; height: 4px; margin: 0; display: inline-block; }
    @include trace.fill('.bar span, .mini span', var(--mat-sys-primary));
    [data-tone='danger'] { color: var(--app-bad-text); }
    [data-tone='warning'] { color: var(--app-warn-text); }
    [data-tone='success'] { color: var(--app-good-text); }
    .bar span[data-tone='danger'] { background: var(--app-danger); }
    .bar span[data-tone='warning'] { background: var(--app-warning); }
    .bar span[data-tone='success'] { background: var(--app-success); }
    .krs { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 0.25rem; }
    .krs li { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; min-height: 2rem; }
    .krs .muted { font-family: var(--app-font-mono); font-size: 0.8rem; }
    .cur {
      width: 6rem; height: 2.25rem; box-sizing: border-box; padding: 0 0.6rem; border-radius: var(--app-radius-sm);
      border: var(--app-border-w) solid var(--mat-sys-outline); background: var(--app-card); color: inherit;
      font: 500 0.875rem var(--app-font-mono);
    }
    @media (max-width: 600px) { .cur { height: 2.75rem; } }
  `,
})
export class ObjectivesPage implements OnInit {
  private readonly api = inject(PerformService);
  private readonly notify = inject(NotifyService);
  protected readonly periods = quarterOptions(new Date());
  protected readonly visibilities = VISIBILITIES;
  protected readonly period = signal(quarterOf(new Date()));
  private readonly list = new PagedList<Objective>();
  protected readonly items = this.list.items;
  protected readonly loading = this.list.loading;
  protected readonly tree = computed(() => objectiveTree(this.items()));
  protected readonly checkinId = signal<number | null>(null);
  protected readonly values = signal<Record<string, number>>({});
  protected readonly drafts = signal<KrDraft[]>([{ title: '', start: 0, target: 100, unit: '' }]);
  protected title = '';
  protected visibility: Visibility = 'public';
  protected parent: number | null = null;
  protected comment = '';
  protected readonly tone = progressTone;
  protected readonly ratio = keyResultRatio;

  ngOnInit(): void {
    this.load();
  }

  protected setPeriod(period: string): void {
    this.period.set(period);
    this.load();
  }

  protected load(): void {
    this.list.load(this.api.objectives({ period: this.period() }), { error: (e) => this.notify.show(performErrorKey(e)) });
  }

  protected addDraft(): void {
    this.drafts.update((d) => [...d, { title: '', start: 0, target: 100, unit: '' }]);
  }

  protected create(): void {
    const krs = this.drafts()
      .filter((d) => d.title.trim())
      .map((d) => ({ title: d.title.trim(), start: Number(d.start), target: Number(d.target), current: Number(d.start), unit: d.unit || null, weight: 1 }));
    if (!this.title.trim() || krs.length === 0) {
      this.notify.show('perform.objectives.needKeyResult');
      return;
    }
    this.api
      .saveObjective({ scope: 'personal', period: this.period(), title: this.title.trim(), visibility: this.visibility, parent_objective_id: this.parent, key_results: krs })
      .subscribe({
        next: (o) => {
          this.items.update((list) => [...list, o]);
          this.title = '';
          this.drafts.set([{ title: '', start: 0, target: 100, unit: '' }]);
          this.notify.show('perform.saved');
        },
        error: (e: unknown) => this.notify.show(performErrorKey(e)),
      });
  }

  protected startCheckIn(o: Objective): void {
    this.checkinId.set(o.id);
    this.values.set(Object.fromEntries(o.key_results.map((kr) => [kr.id, kr.current])));
    this.comment = '';
  }

  protected setValue(id: string, event: Event): void {
    const value = Number((event.target as HTMLInputElement).value);
    this.values.update((v) => ({ ...v, [id]: value }));
  }

  protected checkIn(o: Objective): void {
    const values = Object.entries(this.values()).map(([id, current]) => ({ id, current }));
    this.api.checkIn(o.id, values, this.comment || null).subscribe({
      next: (saved) => {
        this.items.update((list) => list.map((x) => (x.id === saved.id ? { ...saved, checkins: undefined } : x)));
        this.checkinId.set(null);
        this.notify.show('perform.objectives.checkedIn');
      },
      error: (e: unknown) => this.notify.show(performErrorKey(e)),
    });
  }
}

