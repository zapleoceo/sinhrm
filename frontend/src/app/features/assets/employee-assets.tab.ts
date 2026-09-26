import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, effect, inject, input, signal } from '@angular/core';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { TranslocoPipe } from '@jsverse/transloco';
import { AssetHistory } from './assets.model';
import { AssetsService } from './assets.service';

/** Profile tab "Активи": what the employee holds now and held before (People job tier; others get 404). */
@Component({
  selector: 'app-employee-assets-tab',
  imports: [DatePipe, MatProgressBarModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (loading()) {
      <mat-progress-bar mode="indeterminate" />
    }
    <h3>{{ 'assets.tab.current' | transloco }}</h3>
    <ul class="list">
      @for (h of current(); track h.id) {
        <li>
          <strong>{{ h.asset?.inventory_number }}</strong> {{ h.asset?.name }}
          <span class="muted small">{{ h.asset?.type ?? '' }} · {{ 'assets.tab.since' | transloco }} {{ h.assigned_at | date: 'dd.MM.yyyy' }}</span>
        </li>
      } @empty {
        <li class="muted">{{ 'assets.tab.none' | transloco }}</li>
      }
    </ul>
    @if (past().length) {
      <h3>{{ 'assets.tab.past' | transloco }}</h3>
      <ul class="list">
        @for (h of past(); track h.id) {
          <li class="muted">
            {{ h.asset?.inventory_number }} {{ h.asset?.name }} · {{ h.assigned_at | date: 'dd.MM.yyyy' }} — {{ h.returned_at | date: 'dd.MM.yyyy' }}
            @if (h.condition_in) { · {{ h.condition_in }} }
          </li>
        }
      </ul>
    }
  `,
  styles: `
    :host { display: block; padding: 1rem 0; }
    h3 { font: var(--mat-sys-title-small); margin: 0.5rem 0; }
    .list { list-style: none; margin: 0; padding: 0; }
    .list li { padding: 0.4rem 0; border-bottom: 1px solid var(--app-border); }
    .small { font-size: 0.8rem; }
  `,
})
export class EmployeeAssetsTab {
  readonly employeeId = input.required<number>();

  private readonly api = inject(AssetsService);
  protected readonly items = signal<AssetHistory[]>([]);
  protected readonly loading = signal(false);
  protected readonly current = computed(() => this.items().filter((h) => h.returned_at === null));
  protected readonly past = computed(() => this.items().filter((h) => h.returned_at !== null));

  constructor() {
    effect(() => {
      const id = this.employeeId();
      this.loading.set(true);
      this.api.ofEmployee(id).subscribe({
        next: (list) => {
          this.items.set(list);
          this.loading.set(false);
        },
        error: () => {
          this.items.set([]);
          this.loading.set(false);
        },
      });
    });
  }
}
