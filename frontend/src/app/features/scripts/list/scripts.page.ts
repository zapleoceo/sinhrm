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
import { MatSnackBar } from '@angular/material/snack-bar';
import { Router, RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { SCRIPT_CHANNELS, Script, ScriptChannel } from '../scripts.model';
import { ScriptsService, scriptsErrorKey } from '../scripts.service';

/** Admin → Скрипти: all scripts with their active version / draft state, creation of a new one. */
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
  ],
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
      <table>
        <thead>
          <tr>
            <th scope="col">{{ 'scripts.name' | transloco }}</th>
            <th scope="col">{{ 'scripts.channelLabel' | transloco }}</th>
            <th scope="col">{{ 'scripts.activeVersion' | transloco }}</th>
            <th scope="col">{{ 'scripts.draft' | transloco }}</th>
          </tr>
        </thead>
        <tbody>
          @for (s of scripts(); track s.id) {
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
              <tr><td colspan="4" class="muted">{{ 'scripts.empty' | transloco }}</td></tr>
            }
          }
        </tbody>
      </table>
    </div>
  `,
  styles: `
    table { width: 100%; border-collapse: collapse; }
    th, td { text-align: left; padding: 0.5rem 0.75rem; border-bottom: 1px solid var(--app-border); font-weight: normal; }
    thead th { color: var(--app-muted); font-size: 0.8rem; }
    th a { color: inherit; font-weight: 500; }
    tr.archived { opacity: 0.6; }
  `,
})
export class ScriptsPage implements OnInit {
  private readonly api = inject(ScriptsService);
  private readonly router = inject(Router);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);

  protected readonly channels = SCRIPT_CHANNELS;
  protected readonly scripts = signal<Script[]>([]);
  protected readonly loading = signal(false);
  protected readonly failed = signal(false);
  protected readonly busy = signal(false);
  protected readonly withArchived = signal(false);
  protected readonly form = inject(NonNullableFormBuilder).group({
    name: ['', [Validators.required, Validators.maxLength(200)]],
    channel: ['call' as ScriptChannel],
  });

  ngOnInit(): void {
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    this.failed.set(false);
    this.api.list(this.withArchived()).subscribe({
      next: (list) => {
        this.scripts.set(list);
        this.loading.set(false);
      },
      error: () => {
        this.failed.set(true);
        this.loading.set(false);
      },
    });
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
        this.snack.open(this.i18n.translate(scriptsErrorKey(e)), undefined, { duration: 3000 });
      },
    });
  }
}
