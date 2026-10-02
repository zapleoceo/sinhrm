import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { MyWave } from '../pulse.model';
import { PulseService } from '../pulse.service';

/** "My surveys" (/pulse): open surveys the user is asked in; answered ones are marked. */
@Component({
  selector: 'app-my-surveys-page',
  imports: [DatePipe, MatButtonModule, MatIconModule, MatProgressBarModule, RouterLink, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="page-head">
      <div>
        <h1>{{ 'pulse.my.title' | transloco }}</h1>
        <p class="muted">{{ 'pulse.my.subtitle' | transloco }}</p>
      </div>
      <a mat-stroked-button routerLink="/pulse/mood"><mat-icon>mood</mat-icon>{{ 'pulse.mood.title' | transloco }}</a>
    </header>
    @if (loading()) {
      <mat-progress-bar mode="indeterminate" />
    }
    <ul class="list">
      @for (w of items(); track w.id) {
        <li class="panel" [class.answered]="w.responded">
          <div class="info">
            <strong>{{ w.title }}</strong>
            <p class="muted until">{{ 'pulse.my.until' | transloco: { date: (w.ends_at | date: 'dd.MM.yyyy') } }}@if (w.anonymous) { · {{ 'pulse.waves.anonymous' | transloco }}}</p>
          </div>
          @if (w.responded) {
            <span class="app-pill" data-tone="good">{{ 'pulse.my.done' | transloco }}</span>
          } @else {
            <a mat-flat-button [routerLink]="['/pulse/waves', w.id]">{{ 'pulse.my.answer' | transloco }}</a>
          }
        </li>
      } @empty {
        @if (!loading()) {
          <li class="app-empty">{{ 'pulse.my.empty' | transloco }}</li>
        }
      }
    </ul>
  `,
  styles: `
    .list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 0.5rem; }
    /* Each open survey: a card with a station ring on the left (filled once answered). */
    .list li.panel {
      position: relative; display: flex; justify-content: space-between; align-items: center; gap: 0.75rem 1rem; flex-wrap: wrap;
      padding: 0.875rem 1rem 0.875rem 2.5rem;
    }
    .list li.panel::before {
      content: ''; position: absolute; left: 1rem; top: 1.15rem; width: 0.75rem; height: 0.75rem; box-sizing: border-box;
      border-radius: 50%; border: 3px solid var(--app-accent); background: var(--app-card);
    }
    .list li.answered::before { border-color: var(--app-success); background: var(--app-success); }
    .info { min-width: 0; flex: 1 1 14rem; }
    .info strong { font: var(--mat-sys-title-small); overflow-wrap: anywhere; }
    .until { margin: 0.25rem 0 0; font: var(--mat-sys-body-small); }
    .list .app-empty { border: var(--app-border-w) dashed var(--app-border); border-radius: var(--app-radius); }
  `,
})
export class MySurveysPage implements OnInit {
  private readonly api = inject(PulseService);
  protected readonly items = signal<MyWave[]>([]);
  protected readonly loading = signal(false);

  ngOnInit(): void {
    this.loading.set(true);
    this.api.myWaves().subscribe({
      next: (list) => {
        this.items.set(list);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }
}
