import { Injectable, computed, inject, signal } from '@angular/core';
import { Observable, tap } from 'rxjs';
import { INTEGRATION_GROUPS, Integration, IntegrationGroup, ManualStatus, UpdateIntegration } from './integrations.model';
import { IntegrationsService, integrationErrorKey } from './integrations.service';

export interface IntegrationGroupView {
  group: IntegrationGroup;
  items: Integration[];
}

/**
 * State of the integrations page (provided per page). Status switches and the AI toggle are optimistic:
 * applied at once and rolled back if the API refuses. onError receives an i18n key.
 */
@Injectable()
export class IntegrationsStore {
  private readonly api = inject(IntegrationsService);

  readonly items = signal<Integration[]>([]);
  readonly aiEnabled = signal(false);
  readonly loading = signal(false);
  readonly failed = signal(false);
  readonly pending = signal<ReadonlySet<string>>(new Set());

  readonly groups = computed<IntegrationGroupView[]>(() =>
    INTEGRATION_GROUPS.map((group) => ({ group, items: this.items().filter((i) => i.group === group) })).filter(
      (g) => g.items.length > 0,
    ),
  );

  load(): void {
    this.loading.set(true);
    this.failed.set(false);
    this.api.list().subscribe({
      next: (list) => {
        this.items.set(list.data);
        this.aiEnabled.set(list.ai_policy.enabled);
        this.loading.set(false);
      },
      error: () => {
        this.failed.set(true);
        this.loading.set(false);
      },
    });
  }

  setStatus(item: Integration, status: ManualStatus, onError: (key: string) => void): void {
    const previous = item;
    this.replace({ ...item, status });
    this.setPending(item.key, true);
    this.api.setStatus(item.key, status).subscribe({
      next: (saved) => {
        this.replace(saved);
        this.setPending(item.key, false);
      },
      error: (e: unknown) => {
        this.replace(previous);
        this.setPending(item.key, false);
        onError(integrationErrorKey(e));
      },
    });
  }

  setAi(enabled: boolean, onError: (key: string) => void): void {
    const previous = this.aiEnabled();
    this.aiEnabled.set(enabled);
    this.api.setAiPolicy(enabled).subscribe({
      next: (policy) => this.aiEnabled.set(policy.enabled),
      error: (e: unknown) => {
        this.aiEnabled.set(previous);
        onError(integrationErrorKey(e));
      },
    });
  }

  /** Save and check are not optimistic (the server decides); the fresh item replaces the old one. */
  save(key: string, body: UpdateIntegration): Observable<Integration> {
    return this.track(key, this.api.update(key, body));
  }

  check(key: string): Observable<Integration> {
    return this.track(key, this.api.check(key));
  }

  private track(key: string, request: Observable<Integration>): Observable<Integration> {
    this.setPending(key, true);
    return request.pipe(
      tap({
        next: (saved) => this.replace(saved),
        finalize: () => this.setPending(key, false),
      }),
    );
  }

  private replace(item: Integration): void {
    this.items.update((list) => list.map((i) => (i.key === item.key ? item : i)));
  }

  private setPending(key: string, on: boolean): void {
    this.pending.update((set) => {
      const next = new Set(set);
      if (on) {
        next.add(key);
      } else {
        next.delete(key);
      }
      return next;
    });
  }
}
