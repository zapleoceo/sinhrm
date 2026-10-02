import { Provider, Type } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Router, provideRouter, withComponentInputBinding } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { TranslocoTestingModule } from '@jsverse/transloco';

/**
 * Page specs of tables with `th[app-column-header]` (docs/guides/tables.md): opens a page at a URL with mocked
 * services and reads / clicks its headers. Labels are i18n keys (the testing Transloco has no translations).
 * Test-only helper — excluded from the app build (`tsconfig.app.json`).
 */
export class TablePage {
  constructor(readonly harness: RouterTestingHarness) {}

  static async open(route: string, component: Type<unknown>, url: string, providers: Provider[]): Promise<TablePage> {
    TestBed.configureTestingModule({
      imports: [TranslocoTestingModule.forRoot({ langs: {}, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
      providers: [provideRouter([{ path: route, component }], withComponentInputBinding()), ...providers],
    });
    const harness = await RouterTestingHarness.create();
    await harness.navigateByUrl(url);
    harness.detectChanges();
    return new TablePage(harness);
  }

  get root(): HTMLElement {
    return this.harness.routeNativeElement!;
  }

  /** Current URL query. */
  get params(): URLSearchParams {
    return new URL(TestBed.inject(Router).url, 'http://x').searchParams;
  }

  /** Header cell by its visible title. */
  th(label: string): HTMLTableCellElement {
    const cell = Array.from(this.root.querySelectorAll<HTMLTableCellElement>('th[app-column-header]')).find(
      (t) => t.querySelector('.title .text, .title.static')?.textContent?.trim() === label,
    );
    if (!cell) throw new Error(`no header «${label}»`);
    return cell;
  }

  /** Clicks the title of a column (sort) and waits for the URL and the render. */
  async sort(label: string): Promise<void> {
    (this.th(label).querySelector('button.title') as HTMLButtonElement).click();
    await this.settle();
  }

  async navigate(url: string): Promise<void> {
    await this.harness.navigateByUrl(url);
    this.harness.detectChanges();
  }

  async settle(): Promise<void> {
    await this.harness.fixture.whenStable();
    this.harness.detectChanges();
  }

  /**
   * Text of one column (0-based) of every body row of the tables matching `table`; `inner` narrows the cell to an
   * element inside it (e.g. the link of a cell that also has a subtitle).
   */
  column(index: number, table = 'table', inner?: string): string[] {
    const rows = Array.from(this.root.querySelectorAll<HTMLTableRowElement>(`${table} tbody tr`));
    return rows.map((r) => {
      const cell = r.cells[index];
      const el = inner ? cell?.querySelector(inner) : cell;
      return el?.textContent?.trim() ?? '';
    });
  }
}
