import { Injectable, computed, inject, signal } from '@angular/core';
import { Observable } from 'rxjs';
import { ChangeRequest, Employee } from '../people.model';
import { PeopleService, peopleErrorKey } from '../people.service';

export type ProfileTab = 'overview' | 'job' | 'timeoff' | 'changes' | 'documents' | 'workflows' | 'performance' | 'assets';

/**
 * Which tabs a profile shows, from the access flags of the API (hidden tiers are not in the payload anyway).
 * Documents: admins, the employee and managers; Workflows: admins and managers, not the employee themself;
 * Performance (objectives, KPIs, plans, 1:1s, review results): admins, the employee and managers above.
 * Assets (what the employee holds and held): the same job tier; handing out / taking back — admins in /admin/assets.
 */
export function profileTabs(e: Employee | null): ProfileTab[] {
  if (e === null) {
    return [];
  }
  const tabs: ProfileTab[] = ['overview'];
  if (e.access?.job) {
    tabs.push('job', 'timeoff');
  }
  if (e.access?.pii || e.access?.decide) {
    tabs.push('changes');
  }
  if (e.access?.job) {
    tabs.push('documents');
  }
  if (e.access?.manage || (e.access?.job && !e.access?.self)) {
    tabs.push('workflows');
  }
  if (e.access?.job) {
    tabs.push('performance', 'assets');
  }
  return tabs;
}

/** Profile page state: the employee (by id or "me") and their change requests. */
@Injectable()
export class ProfileStore {
  private readonly api = inject(PeopleService);
  private seq = 0;

  readonly employee = signal<Employee | null>(null);
  readonly loading = signal(false);
  /** i18n key of a load error (no_employee for "My profile" without a link). */
  readonly error = signal<string | null>(null);
  readonly changes = signal<ChangeRequest[]>([]);
  readonly tabs = computed(() => profileTabs(this.employee()));

  load(id: number | 'me'): void {
    const seq = ++this.seq;
    this.loading.set(true);
    this.error.set(null);
    const call: Observable<Employee> = id === 'me' ? this.api.me() : this.api.get(id);
    call.subscribe({
      next: (e) => {
        if (seq !== this.seq) {
          return;
        }
        this.employee.set(e);
        this.loading.set(false);
        this.loadChanges();
      },
      error: (err: unknown) => {
        if (seq === this.seq) {
          this.employee.set(null);
          this.error.set(peopleErrorKey(err));
          this.loading.set(false);
        }
      },
    });
  }

  replace(e: Employee): void {
    this.employee.set(e);
  }

  loadChanges(): void {
    const e = this.employee();
    if (e === null || !(e.access?.pii || e.access?.decide)) {
      this.changes.set([]);
      return;
    }
    this.api.changeRequests({ employee_id: e.id, perPage: 50 }).subscribe({
      next: (page) => this.changes.set(page.data),
      error: () => this.changes.set([]),
    });
  }

  added(request: ChangeRequest): void {
    this.changes.update((list) => [request, ...list]);
  }

  /** Approve/reject; an approval reloads the profile so the new values show up. */
  decide(request: ChangeRequest, approve: boolean, onError: (key: string) => void): void {
    this.api.decideChange(request.id, approve).subscribe({
      next: (updated) => {
        this.changes.update((list) => list.map((r) => (r.id === updated.id ? updated : r)));
        const e = this.employee();
        if (approve && e !== null) {
          this.api.get(e.id).subscribe({ next: (fresh) => this.employee.set(fresh), error: () => undefined });
        }
      },
      error: (err: unknown) => onError(peopleErrorKey(err)),
    });
  }
}
