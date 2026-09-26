import { Injectable, inject, signal } from '@angular/core';
import { Observable, tap } from 'rxjs';
import { Application, Candidate, LogTouch, MoveApplication, RejectReason, TimelineFilter, TimelineItem, Touchpoint } from '../recruiting.model';
import { SendMessage } from '../../channels/channels.model';
import { ChannelsService } from '../../channels/channels.service';
import { RecruitingService } from '../recruiting.service';

const PER_PAGE = 30;

/** One candidate card: profile + route (applications), merged timeline with filter chips, composer, stage moves. */
@Injectable()
export class CandidateCardStore {
  private readonly api = inject(RecruitingService);
  private readonly channels = inject(ChannelsService);
  private candidateSeq = 0;
  private timelineSeq = 0;

  readonly candidate = signal<Candidate | null>(null);
  readonly loading = signal(false);
  readonly failed = signal(false);
  readonly filters = signal<readonly TimelineFilter[]>([]);
  readonly timeline = signal<TimelineItem[]>([]);
  readonly timelineTotal = signal(0);
  readonly timelinePage = signal(1);
  readonly timelineLoading = signal(false);
  readonly rejectReasons = signal<RejectReason[]>([]);

  open(id: number): void {
    const seq = ++this.candidateSeq;
    this.loading.set(true);
    this.failed.set(false);
    this.api.candidate(id).subscribe({
      next: (c) => {
        if (seq === this.candidateSeq) {
          this.candidate.set(c);
          this.loading.set(false);
        }
      },
      error: () => {
        if (seq === this.candidateSeq) {
          this.failed.set(true);
          this.loading.set(false);
        }
      },
    });
    this.loadTimeline(id, 1);
    if (this.rejectReasons().length === 0) {
      this.api.rejectReasons().subscribe({ next: (list) => this.rejectReasons.set(list), error: () => undefined });
    }
  }

  /** Toggles a filter chip; an empty set = everything. */
  toggleFilter(filter: TimelineFilter): void {
    this.filters.update((list) => (list.includes(filter) ? list.filter((f) => f !== filter) : [...list, filter]));
    this.reloadTimeline();
  }

  clearFilters(): void {
    this.filters.set([]);
    this.reloadTimeline();
  }

  loadMore(): void {
    const c = this.candidate();
    if (c && this.timeline().length < this.timelineTotal()) {
      this.loadTimeline(c.id, this.timelinePage() + 1);
    }
  }

  logTouch(body: LogTouch): Observable<Touchpoint> {
    const c = this.candidate();
    if (!c) {
      throw new Error('no candidate open');
    }
    return this.api.logTouch(c.id, body).pipe(tap(() => this.refresh()));
  }

  sendMessage(body: SendMessage): Observable<Touchpoint> {
    const c = this.candidate();
    if (!c) {
      throw new Error('no candidate open');
    }
    return this.channels.send(c.id, body).pipe(tap(() => this.refresh()));
  }

  move(application: Application, body: MoveApplication): Observable<Application> {
    return this.api.move(application.id, body).pipe(tap(() => this.refresh()));
  }

  /** Reloads the card and the first timeline page (after a touch or a move). */
  refresh(): void {
    const c = this.candidate();
    if (c) {
      this.open(c.id);
    }
  }

  private reloadTimeline(): void {
    const c = this.candidate();
    if (c) {
      this.loadTimeline(c.id, 1);
    }
  }

  private loadTimeline(id: number, page: number): void {
    const seq = ++this.timelineSeq;
    this.timelineLoading.set(true);
    this.api.timeline(id, this.filters(), page, PER_PAGE).subscribe({
      next: (res) => {
        if (seq !== this.timelineSeq) {
          return;
        }
        this.timeline.update((list) => (page === 1 ? res.data : [...list, ...res.data]));
        this.timelineTotal.set(res.meta.total);
        this.timelinePage.set(page);
        this.timelineLoading.set(false);
      },
      error: () => {
        if (seq === this.timelineSeq) {
          this.timelineLoading.set(false);
        }
      },
    });
  }
}
