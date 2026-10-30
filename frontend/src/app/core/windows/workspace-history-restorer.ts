import { DestroyRef, Injectable, inject } from '@angular/core';
import { Location } from '@angular/common';
import { NavigationEnd, NavigationStart, Router } from '@angular/router';
import { filter } from 'rxjs';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { AuthService } from '../auth/auth.service';
import { WorkspaceSnapshotStore } from './workspace-snapshot.store';
import { createWorkspaceSnapshot, validateWorkspaceSnapshot } from './workspace-url';

/** Replays a browser history workspace entry through Angular Router when its visible URL is canonical. */
@Injectable({ providedIn: 'root' })
export class WorkspaceHistoryRestorer {
  private readonly destroyRef = inject(DestroyRef);
  private readonly router = inject(Router);
  private readonly location = inject(Location);
  private readonly auth = inject(AuthService);
  private readonly snapshots = inject(WorkspaceSnapshotStore);
  private restoringUrl: string | null = null;
  private restoreGeneration = 0;

  constructor() {
    this.router.events.pipe(
      filter((event): event is NavigationStart => event instanceof NavigationStart && event.navigationTrigger === 'popstate'),
      takeUntilDestroyed(this.destroyRef),
    ).subscribe((event) => this.restoreHistoryEntry(event));
    this.router.events.pipe(
      filter((event): event is NavigationEnd => event instanceof NavigationEnd),
      takeUntilDestroyed(this.destroyRef),
    ).subscribe(() => this.saveSuccessfulWorkspace());
  }

  restoreHistoryEntry(event: NavigationStart): void {
    const user = this.auth.user();
    if (!user || this.restoringUrl === event.url) return;
    const snapshot = this.snapshots.readHistory(event.restoredState, user.id);
    if (!snapshot || snapshot.visibleUrl !== event.url || snapshot.visibleUrl !== (this.location.path(true) || '/')) return;
    let internalTree;
    try {
      internalTree = this.router.parseUrl(snapshot.internalUrl);
    } catch {
      return;
    }
    if (!validateWorkspaceSnapshot(this.router, snapshot, user.id, (module) => this.auth.hasModule(module))) return;

    const generation = ++this.restoreGeneration;
    this.restoringUrl = event.url;
    queueMicrotask(() => {
      if (generation !== this.restoreGeneration) return;
      if (this.auth.user()?.id !== user.id) {
        this.restoringUrl = null;
        return;
      }
      if ((this.location.path(true) || '/') !== event.url) {
        this.restoringUrl = null;
        return;
      }
      void this.router.navigateByUrl(internalTree, {
        browserUrl: snapshot.visibleUrl,
        replaceUrl: true,
        state: this.snapshots.historyState(snapshot),
      }).finally(() => {
        if (generation === this.restoreGeneration) this.restoringUrl = null;
      });
    });
  }

  private saveSuccessfulWorkspace(): void {
    const user = this.auth.user();
    if (!user) return;
    const tree = this.router.parseUrl(this.router.url);
    const snapshot = createWorkspaceSnapshot(this.router, tree, user.id, (module) => this.auth.hasModule(module));
    if (snapshot) {
      this.snapshots.writeSession(snapshot);
    } else {
      this.snapshots.clearSession(user.id);
    }
  }
}
