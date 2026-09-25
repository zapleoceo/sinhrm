import { Injectable, inject, signal } from '@angular/core';
import { Observable, tap } from 'rxjs';
import { Candidate, Touchpoint } from '../recruiting.model';
import { RecruitingService } from '../recruiting.service';

/** Unmatched messages: link to a candidate or create one; a resolved message leaves the list. */
@Injectable()
export class InboxStore {
  private readonly api = inject(RecruitingService);

  readonly items = signal<Touchpoint[]>([]);
  readonly total = signal(0);
  readonly loading = signal(false);
  readonly failed = signal(false);

  load(): void {
    this.loading.set(true);
    this.failed.set(false);
    this.api.inbox().subscribe({
      next: (page) => {
        this.items.set(page.data);
        this.total.set(page.meta.total);
        this.loading.set(false);
      },
      error: () => {
        this.failed.set(true);
        this.loading.set(false);
      },
    });
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
