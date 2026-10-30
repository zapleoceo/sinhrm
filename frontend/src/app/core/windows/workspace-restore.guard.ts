import { inject } from '@angular/core';
import { Location } from '@angular/common';
import { CanMatchFn, RedirectCommand, Router } from '@angular/router';
import { AuthService } from '../auth/auth.service';
import { WorkspaceSnapshotStore } from './workspace-snapshot.store';
import { validateWorkspaceSnapshot, workspaceVisibleUrl } from './workspace-url';

/** Restores a same-tab workspace through Router redirects; normal module and page guards still run. */
export const workspaceRestoreGuard: CanMatchFn = async () => {
  const auth = inject(AuthService);
  const location = inject(Location);
  const router = inject(Router);
  const store = inject(WorkspaceSnapshotStore);
  const navigation = router.getCurrentNavigation();
  const target = navigation?.finalUrl ?? navigation?.extractedUrl ?? router.parseUrl(router.url);
  const hasNamedOutlet = hasWindowOutlet(target.root);
  const currentVisibleUrl = location.path(true) || '/';
  const knownUser = auth.user();
  if (hasNamedOutlet && knownUser) {
    const pendingSnapshot = store.readHistory(navigation?.extras.state, knownUser.id);
    if (pendingSnapshot?.internalUrl === router.serializeUrl(target)) return true;
  }
  if (!hasNamedOutlet && navigation?.id !== 1) return true;

  await auth.load();
  const user = auth.user();
  if (!user || !auth.isLoggedIn()) return true;

  const history = store.readHistory(location.getState(), user.id);
  const navigationEntry = globalThis.performance?.getEntriesByType('navigation')[0] as PerformanceNavigationTiming | undefined;
  const reloadSnapshot = navigation?.id === 1 && navigationEntry?.type === 'reload' ? store.readSession(user.id) : null;
  const candidate = history && history.visibleUrl === currentVisibleUrl
    ? history
    : reloadSnapshot;

  if (!hasNamedOutlet && candidate && candidate.visibleUrl === currentVisibleUrl
    && validateWorkspaceSnapshot(router, candidate, user.id, (module) => auth.hasModule(module))) {
    const restored = router.parseUrl(candidate.internalUrl);
    if (router.serializeUrl(restored) === candidate.visibleUrl) return true;
    return new RedirectCommand(restored, {
      browserUrl: candidate.visibleUrl,
      replaceUrl: true,
      state: store.historyState(candidate),
    });
  }

  if (hasNamedOutlet) {
    const visibleUrl = workspaceVisibleUrl(router, target);
    const snapshot = { version: 1 as const, userId: user.id, internalUrl: router.serializeUrl(target), visibleUrl };
    if (!validateWorkspaceSnapshot(router, snapshot, user.id, (module) => auth.hasModule(module))) return true;
    const pendingSnapshot = store.readHistory(navigation?.extras.state, user.id);
    if (pendingSnapshot?.internalUrl === snapshot.internalUrl && pendingSnapshot.visibleUrl === snapshot.visibleUrl) return true;
    return new RedirectCommand(target, {
      browserUrl: visibleUrl,
      replaceUrl: true,
      state: store.historyState(snapshot),
    });
  }

  return true;
};

function hasWindowOutlet(group: import('@angular/router').UrlSegmentGroup): boolean {
  return Object.keys(group.children).some((key) => key.startsWith('os'))
    || Object.values(group.children).some(hasWindowOutlet);
}
