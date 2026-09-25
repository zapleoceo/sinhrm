import { Injectable, inject, signal } from '@angular/core';
import { Observable, tap } from 'rxjs';
import { SaveVacancy, Vacancy, VacancyQuery } from '../recruiting.model';
import { RecruitingService } from '../recruiting.service';

const DEFAULT_QUERY: VacancyQuery = { page: 1, perPage: 50, status: 'open' };

/** Vacancies list page state (provided per page). Stale answers after a filter change are dropped. */
@Injectable()
export class VacanciesStore {
  private readonly api = inject(RecruitingService);
  private seq = 0;

  readonly query = signal<VacancyQuery>(DEFAULT_QUERY);
  readonly items = signal<Vacancy[]>([]);
  readonly total = signal(0);
  readonly loading = signal(false);
  readonly failed = signal(false);

  load(): void {
    const seq = ++this.seq;
    this.loading.set(true);
    this.failed.set(false);
    this.api.vacancies(this.query()).subscribe({
      next: (page) => {
        if (seq !== this.seq) {
          return;
        }
        this.items.set(page.data);
        this.total.set(page.meta.total);
        this.loading.set(false);
      },
      error: () => {
        if (seq === this.seq) {
          this.failed.set(true);
          this.loading.set(false);
        }
      },
    });
  }

  /** Filters reset the page to 1. */
  patchQuery(patch: Partial<VacancyQuery>): void {
    this.query.update((q) => ({ ...q, ...patch, page: 1 }));
    this.load();
  }

  setPage(page: number, perPage: number): void {
    this.query.update((q) => ({ ...q, page, perPage }));
    this.load();
  }

  save(id: number | null, body: SaveVacancy): Observable<Vacancy> {
    const call = id === null ? this.api.createVacancy(body) : this.api.updateVacancy(id, body);
    return call.pipe(tap(() => this.load()));
  }
}
