import { Provider, SkipSelf, inject } from '@angular/core';
import { ActivatedRoute, IsActiveMatchOptions, NavigationBehaviorOptions, Router, UrlTree } from '@angular/router';
import { WindowContext, WINDOW_CONTEXT } from './window-context';
import { WindowNavigation } from './window-navigation';

type CreateUrlTreeArgs = Parameters<Router['createUrlTree']>;
type NavigateArgs = Parameters<Router['navigate']>;
type NavigateByUrlArgs = Parameters<Router['navigateByUrl']>;

/** A component-scoped adapter: real Router state/events remain global; only registered canonical targets are scoped. */
export function createWindowRouter(root: Router, windows: WindowNavigation, context: WindowContext): Router {
  return new Proxy(root, {
    get(target, property) {
      if (property === 'createUrlTree') {
        return (...args: CreateUrlTreeArgs): UrlTree => {
          const scoped = windows.createUrlTreeFromCommands(context.slot, args[0], args[1]);
          return scoped ?? target.createUrlTree(...args);
        };
      }
      if (property === 'navigate') {
        return (...args: NavigateArgs): Promise<boolean> => {
          const scoped = windows.createUrlTreeFromCommands(context.slot, args[0], args[1]);
          return scoped ? target.navigateByUrl(scoped, args[1]) : target.navigate(...args);
        };
      }
      if (property === 'navigateByUrl') {
        return (...args: NavigateByUrlArgs): Promise<boolean> => {
          const source = typeof args[0] === 'string' ? target.parseUrl(args[0]) : args[0];
          if (windows.isWorkspaceTree(source, context.slot)) return target.navigateByUrl(...args);
          const scoped = windows.routeTreeForUrl(context.slot, source);
          return scoped ? target.navigateByUrl(scoped, args[1] as NavigationBehaviorOptions | undefined) : target.navigateByUrl(...args);
        };
      }
      if (property === 'isActive') {
        return (url: string | UrlTree, matchOptions: IsActiveMatchOptions): boolean => {
          const tree = typeof url === 'string' ? target.parseUrl(url) : url;
          const scoped = windows.isWorkspaceTree(tree, context.slot) ? tree : windows.routeTreeForUrl(context.slot, tree);
          return target.isActive(scoped ?? url, matchOptions);
        };
      }
      const value: unknown = Reflect.get(target, property, target);
      return typeof value === 'function' ? value.bind(target) : value;
    },
  });
}

export const WINDOW_ROUTER_PROVIDER: Provider = {
  provide: Router,
  useFactory: createWindowRouter,
  deps: [[new SkipSelf(), Router], WindowNavigation, WINDOW_CONTEXT],
};

export function windowContextProvider(): Provider {
  return {
    provide: WINDOW_CONTEXT,
    useFactory: (): WindowContext => {
      const route = inject(ActivatedRoute);
      const slot: unknown = route.snapshot.data['windowSlot'];
      if (slot !== 'os1' && slot !== 'os2') throw new Error('Missing registered window outlet context');
      return { slot };
    },
  };
}
