import { Injectable, effect, inject, signal } from '@angular/core';
import { Dashboard } from '../overview/overview.model';
import { OverviewService } from '../overview/overview.service';
import { AuthService } from '../../core/auth/auth.service';

/** Finite, per-effective-role request for the existing overview API; never polls or persists dashboard data. */
@Injectable()
export class DesktopDataStore {
  private readonly auth = inject(AuthService);
  private readonly overview = inject(OverviewService);
  private requestContext = '';
  private readonly refreshVersion = signal(0);
  readonly data = signal<Dashboard | null>(null);
  readonly loading = signal(false);
  readonly failed = signal(false);

  constructor() {
    effect((onCleanup) => {
      this.refreshVersion();
      const user = this.auth.user();
      const context = user ? `${user.id}|${[...user.roles].sort().join(',')}|${[...(user.modules ?? [])].sort().join(',')}` : '';
      this.requestContext = context;
      this.data.set(null);
      this.failed.set(false);
      if (!context) {
        this.loading.set(false);
        return;
      }
      this.loading.set(true);
      const subscription = this.overview.dashboard().subscribe({
        next: (dashboard) => {
          if (this.requestContext !== context) return;
          this.data.set(dashboard);
          this.loading.set(false);
        },
        error: () => {
          if (this.requestContext !== context) return;
          this.failed.set(true);
          this.loading.set(false);
        },
      });
      onCleanup(() => subscription.unsubscribe());
    });
  }

  reload(): void {
    this.refreshVersion.update((version) => version + 1);
  }
}
