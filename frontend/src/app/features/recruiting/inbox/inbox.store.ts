import { Injectable, inject } from '@angular/core';
import { Observable, tap } from 'rxjs';
import { PagedList } from '../../../core/ui/table/paged-list';
import { Candidate, Touchpoint } from '../recruiting.model';
import { RecruitingService } from '../recruiting.service';

/** Unmatched messages: link to a candidate or create one; a resolved message leaves the list. */
@Injectable()
export class InboxStore {
  private readonly api = inject(RecruitingService);
  private readonly list = new PagedList<Touchpoint>();

  readonly items = this.list.items;
  readonly total = this.list.total;
  readonly loading = this.list.loading;
  readonly failed = this.list.failed;

  /** A repeated load (refresh) cancels the one still in flight. */
  load(): void {
    this.list.load(this.api.inbox());
  }

  link(message: Touchpoint, candidateId: number): Observable<Touchpoint> {
    return this.api.linkInbox(message.id, candidateId).pipe(tap(() => this.remove(message.id)));
  }

  createCandidate(message: Touchpoint, body: { full_name: string; vacancy_id?: number }): Observable<Candidate> {
    return this.api.createFromInbox(message.id, body).pipe(tap(() => this.remove(message.id)));
  }

  private remove(id: number): void {
    this.items.update((list) => list.filter((m) => m.id !== id));
    this.total.update((n) => Math.max(0, n - 1));
  }
}
