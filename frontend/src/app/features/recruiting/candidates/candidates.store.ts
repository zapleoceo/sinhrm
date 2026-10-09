import { Injectable, inject, signal } from '@angular/core';
import { Candidate, CandidateQuery } from '../recruiting.model';
import { RecruitingService } from '../recruiting.service';
import { PagedList } from '../../../core/ui/table/paged-list';

const DEFAULT_QUERY: CandidateQuery = { page: 1, perPage: 50 };

/** Candidates list (left side of the split view) with keyboard selection (j/k, ↑/↓). */
@Injectable()
export class CandidatesStore {
  private readonly api = inject(RecruitingService);
  /** A newer query cancels the request still in flight: an old answer never lands over new filters. */
  private readonly list = new PagedList<Candidate>();

  readonly query = signal<CandidateQuery>(DEFAULT_QUERY);
  readonly items = this.list.items;
  readonly total = this.list.total;
  readonly loading = this.list.loading;
  readonly failed = this.list.failed;

  load(): void {
    this.list.load(this.api.candidates(this.query()));
  }

  patchQuery(patch: Partial<CandidateQuery>): void {
    this.query.update((q) => ({ ...q, ...patch, page: 1 }));
    this.load();
  }

  setPage(page: number, perPage: number): void {
    this.query.update((q) => ({ ...q, page, perPage }));
    this.load();
  }
}
