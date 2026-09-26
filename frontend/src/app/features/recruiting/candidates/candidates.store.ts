import { Injectable, inject, signal } from '@angular/core';
import { Candidate, CandidateQuery } from '../recruiting.model';
import { RecruitingService } from '../recruiting.service';

const DEFAULT_QUERY: CandidateQuery = { page: 1, perPage: 50 };

/** Candidates list (left side of the split view) with keyboard selection (j/k, ↑/↓). */
@Injectable()
export class CandidatesStore {
  private readonly api = inject(RecruitingService);
  private seq = 0;

  readonly query = signal<CandidateQuery>(DEFAULT_QUERY);
  readonly items = signal<Candidate[]>([]);
  readonly total = signal(0);
  readonly loading = signal(false);
  readonly failed = signal(false);

  load(): void {
    const seq = ++this.seq;
    this.loading.set(true);
    this.failed.set(false);
    this.api.candidates(this.query()).subscribe({
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

  patchQuery(patch: Partial<CandidateQuery>): void {
    this.query.update((q) => ({ ...q, ...patch, page: 1 }));
    this.load();
  }

  setPage(page: number, perPage: number): void {
    this.query.update((q) => ({ ...q, page, perPage }));
    this.load();
  }
}
