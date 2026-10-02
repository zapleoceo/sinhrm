import { EnvironmentProviders, Provider, Type } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { TranslocoTestingModule } from '@jsverse/transloco';

/**
 * Unit tests of pages with header sorting / filtering (core/ui/table): opens the component at a URL (the table state
 * lives in the query), with translation keys as text. Test-only helper — excluded from the app build.
 */
export interface TablePage<T> {
  fixture: ComponentFixture<T>;
  el: HTMLElement;
  router: Router;
  /** Waits for the navigation started by a click, then renders. */
  settle: () => Promise<void>;
}

export async function openTablePage<T>(
  component: Type<T>,
  url: string,
  providers: (Provider | EnvironmentProviders)[] = [],
  inputs: Record<string, unknown> = {},
): Promise<TablePage<T>> {
  TestBed.configureTestingModule({
    imports: [component, TranslocoTestingModule.forRoot({ langs: {}, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
    providers: [provideRouter([]), ...providers],
  });
  const router = TestBed.inject(Router);
  await router.navigateByUrl(url);
  const fixture = TestBed.createComponent(component);
  for (const [name, value] of Object.entries(inputs)) fixture.componentRef.setInput(name, value);
  fixture.detectChanges();
  const settle = async (): Promise<void> => {
    await fixture.whenStable();
    fixture.detectChanges();
  };
  await settle();
  return { fixture, el: fixture.nativeElement as HTMLElement, router, settle };
}

/** Header cell of a table by its column title (the th's aria-label is the plain title). */
export function header(table: Element, title: string): HTMLTableCellElement {
  const th = [...table.querySelectorAll<HTMLTableCellElement>('thead th')].find((h) => h.getAttribute('aria-label') === title);
  if (!th) throw new Error(`no column «${title}»`);
  return th;
}

/** Clicks the sort title of a column. */
export function clickTitle(table: Element, title: string): void {
  (header(table, title).querySelector('button.title') as HTMLButtonElement).click();
}

/** Trimmed text of the n-th cell (th or td) of every body row. */
export function column(table: Element, index: number): string[] {
  return [...table.querySelectorAll('tbody tr')].map((tr) => tr.children[index]?.textContent?.trim() ?? '');
}
