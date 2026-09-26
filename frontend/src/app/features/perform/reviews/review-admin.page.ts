import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatDatepickerModule } from '@angular/material/datepicker';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { MatSnackBar } from '@angular/material/snack-bar';
import { MatStepperModule } from '@angular/material/stepper';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { Competency, REVIEW_TYPES, RatingScale, ReviewCycle, ReviewType, parseIds } from '../perform.model';
import { PerformService, performErrorKey } from '../perform.service';
import { toIsoDate } from '../../../core/date/iso-date';

/**
 * Review setup (/admin/perform/reviews, admins): rating scales, competencies, and the cycle wizard —
 * basics → participants and review types (360) → competencies → create; then activate (assignments are created)
 * and close. Progress shows who has submitted, never the answers.
 */
@Component({
  selector: 'app-review-admin-page',
  imports: [FormsModule, MatButtonModule, MatCheckboxModule, MatDatepickerModule, MatFormFieldModule, MatIconModule, MatInputModule, MatSelectModule, MatStepperModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="page-head">
      <div>
        <h1>{{ 'perform.admin.title' | transloco }}</h1>
        <p class="muted">{{ 'perform.admin.subtitle' | transloco }}</p>
      </div>
    </header>

    <div class="cols">
      <section class="panel box">
        <h2>{{ 'perform.admin.scales' | transloco }}</h2>
        <ul>
          @for (s of scales(); track s.id) {
            <li>{{ s.name }} <span class="muted">({{ levelsText(s) }})</span></li>
          }
        </ul>
        <div class="filters">
          <mat-form-field subscriptSizing="dynamic" class="grow">
            <mat-label>{{ 'perform.fields.name' | transloco }}</mat-label>
            <input matInput [(ngModel)]="scaleName" />
          </mat-form-field>
          <mat-form-field subscriptSizing="dynamic" class="grow">
            <mat-label>{{ 'perform.admin.levels' | transloco }}</mat-label>
            <input matInput [(ngModel)]="scaleLevels" placeholder="1 Weak; 3 OK; 5 Strong" />
          </mat-form-field>
          <button mat-stroked-button type="button" (click)="addScale()">{{ 'perform.add' | transloco }}</button>
        </div>
      </section>

      <section class="panel box">
        <h2>{{ 'perform.admin.competencies' | transloco }}</h2>
        <ul>
          @for (c of competencies(); track c.id) {
            <li>{{ c.name }}</li>
          }
        </ul>
        <div class="filters">
          <mat-form-field subscriptSizing="dynamic" class="grow">
            <mat-label>{{ 'perform.fields.name' | transloco }}</mat-label>
            <input matInput [(ngModel)]="competencyName" />
          </mat-form-field>
          <mat-form-field subscriptSizing="dynamic">
            <mat-label>{{ 'perform.admin.scale' | transloco }}</mat-label>
            <mat-select [(ngModel)]="competencyScale">
              @for (s of scales(); track s.id) {
                <mat-option [value]="s.id">{{ s.name }}</mat-option>
              }
            </mat-select>
          </mat-form-field>
          <button mat-stroked-button type="button" (click)="addCompetency()">{{ 'perform.add' | transloco }}</button>
        </div>
      </section>
    </div>

    <section class="panel box">
      <h2>{{ 'perform.admin.newCycle' | transloco }}</h2>
      <mat-stepper [linear]="true" #stepper>
        <mat-step [completed]="!!cycleName && !!periodStart && !!periodEnd" [label]="'perform.admin.stepBasics' | transloco">
          <div class="filters">
            <mat-form-field subscriptSizing="dynamic" class="grow">
              <mat-label>{{ 'perform.fields.name' | transloco }}</mat-label>
              <input matInput [(ngModel)]="cycleName" />
            </mat-form-field>
            <mat-form-field subscriptSizing="dynamic">
              <mat-label>{{ 'perform.admin.from' | transloco }}</mat-label>
              <input matInput [matDatepicker]="dp1" [(ngModel)]="periodStart" /><mat-datepicker-toggle matIconSuffix [for]="dp1" /><mat-datepicker #dp1 />
            </mat-form-field>
            <mat-form-field subscriptSizing="dynamic">
              <mat-label>{{ 'perform.admin.to' | transloco }}</mat-label>
              <input matInput [matDatepicker]="dp2" [(ngModel)]="periodEnd" /><mat-datepicker-toggle matIconSuffix [for]="dp2" /><mat-datepicker #dp2 />
            </mat-form-field>
          </div>
          <button mat-flat-button matStepperNext type="button">{{ 'perform.admin.next' | transloco }}</button>
        </mat-step>
        <mat-step [completed]="types.length > 0" [label]="'perform.admin.stepPeople' | transloco">
          <div class="filters">
            <mat-form-field subscriptSizing="dynamic" class="grow">
              <mat-label>{{ 'perform.admin.branchIds' | transloco }}</mat-label>
              <input matInput [(ngModel)]="branchIds" />
            </mat-form-field>
            <mat-form-field subscriptSizing="dynamic" class="grow">
              <mat-label>{{ 'perform.admin.departmentIds' | transloco }}</mat-label>
              <input matInput [(ngModel)]="departmentIds" />
            </mat-form-field>
          </div>
          <div class="checks">
            @for (t of reviewTypes; track t) {
              <mat-checkbox [checked]="types.includes(t)" (change)="toggleType(t)">{{ 'perform.reviewType.' + t | transloco }}</mat-checkbox>
            }
            <mat-checkbox [(ngModel)]="anonymous">{{ 'perform.admin.anonymous' | transloco }}</mat-checkbox>
          </div>
          <p class="muted">{{ 'perform.admin.anonymousHint' | transloco }}</p>
          <button mat-button matStepperPrevious type="button">{{ 'perform.admin.back' | transloco }}</button>
          <button mat-flat-button matStepperNext type="button">{{ 'perform.admin.next' | transloco }}</button>
        </mat-step>
        <mat-step [completed]="picked.length > 0" [label]="'perform.admin.stepCompetencies' | transloco">
          <div class="checks">
            @for (c of competencies(); track c.id) {
              <mat-checkbox [checked]="picked.includes(c.id)" (change)="togglePicked(c.id)">{{ c.name }}</mat-checkbox>
            }
          </div>
          <button mat-button matStepperPrevious type="button">{{ 'perform.admin.back' | transloco }}</button>
          <button mat-flat-button type="button" [disabled]="!picked.length || !types.length" (click)="createCycle(); stepper.reset()">{{ 'perform.admin.create' | transloco }}</button>
        </mat-step>
      </mat-stepper>
    </section>

    <section class="panel box">
      <h2>{{ 'perform.admin.cycles' | transloco }}</h2>
      <table class="cycles">
        <tbody>
          @for (c of cycles(); track c.id) {
            <tr>
              <th scope="row">{{ c.name }}</th>
              <td class="muted">{{ c.period_start }} — {{ c.period_end }}</td>
              <td>{{ 'perform.cycleStatus.' + c.status | transloco }}</td>
              <td>
                <span class="mini"><span [style.width.%]="c.progress.total ? (c.progress.submitted / c.progress.total) * 100 : 0"></span></span>
                {{ c.progress.submitted }}/{{ c.progress.total }}
              </td>
              <td>
                @if (c.status === 'draft') {
                  <button mat-stroked-button type="button" (click)="command(c, 'activate')">{{ 'perform.admin.activate' | transloco }}</button>
                } @else if (c.status === 'active') {
                  <button mat-button type="button" (click)="command(c, 'close')">{{ 'perform.admin.close' | transloco }}</button>
                }
              </td>
            </tr>
          } @empty {
            <tr><td class="muted">{{ 'perform.admin.noCycles' | transloco }}</td></tr>
          }
        </tbody>
      </table>
    </section>
  `,
  styles: `
    .cols { display: grid; grid-template-columns: repeat(auto-fit, minmax(20rem, 1fr)); gap: 1rem; margin-bottom: 1rem; }
    .box { padding: 1rem; margin-bottom: 1rem; }
    .box h2 { margin: 0 0 0.5rem; font: var(--mat-sys-title-medium); }
    .checks { display: flex; flex-wrap: wrap; gap: 0.25rem 1rem; margin: 0.5rem 0; }
    .cycles { width: 100%; border-collapse: collapse; }
    .cycles th, .cycles td { text-align: left; padding: 0.4rem 0.5rem; border-bottom: 1px solid var(--app-border); }
    .mini { display: inline-block; width: 5rem; height: 6px; border-radius: 3px; background: var(--mat-sys-surface-container-highest); overflow: hidden; vertical-align: middle; }
    .mini span { display: block; height: 100%; background: var(--app-success); }
  `,
})
export class ReviewAdminPage implements OnInit {
  private readonly api = inject(PerformService);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  protected readonly reviewTypes = REVIEW_TYPES;
  protected readonly scales = signal<RatingScale[]>([]);
  protected readonly competencies = signal<Competency[]>([]);
  protected readonly cycles = signal<ReviewCycle[]>([]);
  protected scaleName = '';
  protected scaleLevels = '1 Very weak; 2 Weak; 3 OK; 4 Strong; 5 Very strong';
  protected competencyName = '';
  protected competencyScale: number | null = null;
  protected cycleName = '';
  protected periodStart: Date | null = null;
  protected periodEnd: Date | null = null;
  protected branchIds = '';
  protected departmentIds = '';
  protected types: ReviewType[] = ['self', 'manager'];
  protected anonymous = true;
  protected picked: number[] = [];

  ngOnInit(): void {
    this.api.scales().subscribe({ next: (s) => this.scales.set(s), error: (e: unknown) => this.toast(performErrorKey(e)) });
    this.api.competencies().subscribe({ next: (c) => this.competencies.set(c), error: () => undefined });
    this.api.cycles().subscribe({ next: (c) => this.cycles.set(c), error: () => undefined });
  }

  protected levelsText(s: RatingScale): string {
    return s.levels.map((l) => `${l.value} ${l.label}`).join('; ');
  }

  protected addScale(): void {
    const levels = this.scaleLevels
      .split(';')
      .map((part) => part.trim().match(/^(\d+)\s+(.+)$/))
      .filter((m): m is RegExpMatchArray => m !== null)
      .map((m) => ({ value: Number(m[1]), label: m[2] }));
    this.api.createScale({ name: this.scaleName, levels }).subscribe({
      next: (s) => {
        this.scales.update((list) => [...list, s]);
        this.scaleName = '';
      },
      error: (e: unknown) => this.toast(performErrorKey(e)),
    });
  }

  protected addCompetency(): void {
    if (!this.competencyScale) {
      return;
    }
    this.api.createCompetency({ name: this.competencyName, scale_id: this.competencyScale }).subscribe({
      next: (c) => {
        this.competencies.update((list) => [...list, c]);
        this.competencyName = '';
      },
      error: (e: unknown) => this.toast(performErrorKey(e)),
    });
  }

  protected toggleType(t: ReviewType): void {
    this.types = this.types.includes(t) ? this.types.filter((x) => x !== t) : [...this.types, t];
  }

  protected togglePicked(id: number): void {
    this.picked = this.picked.includes(id) ? this.picked.filter((x) => x !== id) : [...this.picked, id];
  }

  protected createCycle(): void {
    this.api
      .createCycle({
        name: this.cycleName,
        period_start: toIsoDate(this.periodStart),
        period_end: toIsoDate(this.periodEnd),
        participants: { branch_ids: parseIds(this.branchIds), department_ids: parseIds(this.departmentIds) },
        types: this.types,
        competency_ids: this.picked,
        anonymous: this.anonymous,
        deadlines: {},
      })
      .subscribe({
        next: (c) => {
          this.cycles.update((list) => [c, ...list]);
          this.cycleName = '';
          this.picked = [];
          this.toast('perform.admin.cycleCreated');
        },
        error: (e: unknown) => this.toast(performErrorKey(e)),
      });
  }

  protected command(c: ReviewCycle, command: 'activate' | 'close'): void {
    this.api.cycleCommand(c.id, command).subscribe({
      next: (saved) => this.cycles.update((list) => list.map((x) => (x.id === saved.id ? { ...saved, assignments: undefined } : x))),
      error: (e: unknown) => this.toast(performErrorKey(e)),
    });
  }

  private toast(key: string): void {
    this.snack.open(this.i18n.translate(key), undefined, { duration: 4000 });
  }
}
