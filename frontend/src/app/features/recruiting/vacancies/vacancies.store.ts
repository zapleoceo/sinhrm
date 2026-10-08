import { Injectable, inject, signal } from '@angular/core';
import { Observable, tap } from 'rxjs';
import { SaveVacancy, Vacancy, VacancyQuery } from '../recruiting.model';
import { RecruitingService } from '../recruiting.service';
import { PagedList } from '../../../core/ui/table/paged-list';

const DEFAULT_QUERY: VacancyQuery = { page: 1, perPage: 50, status: 'open' };

/** Vacancies list page state (provided per page). A filter change cancels the request still in flight. */
@Injectable()
export class VacanciesStore {
  private readonly api = inject(RecruitingService);
  private readonly list = new PagedList<Vacancy>();

  readonly query = signal<VacancyQuery>(DEFAULT_QUERY);
  readonly items = this.list.items;
  readonly total = this.list.total;
  /** Active (open AND published) vacancies in scope, regardless of the filters. */
  readonly activeCount = signal(0);
  readonly loading = this.list.loading;
  readonly failed = this.list.failed;

  load(): void {
    this.list.load(this.api.vacancies(this.query()), { next: (page) => this.activeCount.set(page.meta.active_count ?? 0) });
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
