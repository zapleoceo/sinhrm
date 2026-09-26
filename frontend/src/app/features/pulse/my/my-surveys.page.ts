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
        <li class="panel">
          <div>
            <strong>{{ w.title }}</strong>
            <p class="muted">{{ 'pulse.my.until' | transloco: { date: (w.ends_at | date: 'dd.MM.yyyy') } }}@if (w.anonymous) { · {{ 'pulse.waves.anonymous' | transloco }}}</p>
          </div>
          @if (w.responded) {
            <span class="done"><mat-icon inline>check</mat-icon> {{ 'pulse.my.done' | transloco }}</span>
          } @else {
            <a mat-flat-button [routerLink]="['/pulse/waves', w.id]">{{ 'pulse.my.answer' | transloco }}</a>
          }
        </li>
      } @empty {
        @if (!loading()) {
          <li class="muted state">{{ 'pulse.my.empty' | transloco }}</li>
        }
      }
    </ul>
  `,
  styles: `
    .list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 0.5rem; }
    .list li { display: flex; justify-content: space-between; align-items: center; gap: 1rem; padding: 0.75rem 1rem; flex-wrap: wrap; }
    .list p { margin: 0.25rem 0 0; }
    .done { color: var(--app-success); }
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
