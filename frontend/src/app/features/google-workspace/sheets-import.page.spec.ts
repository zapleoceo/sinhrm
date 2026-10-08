import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse } from '@angular/common/http';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { Subject, of } from 'rxjs';
import { SheetImport, SheetImportReport } from './google.model';
import { GoogleService } from './google.service';
import { SheetsImportPage } from './sheets-import.page';

const saved = { id: 3, spreadsheet_id: 'abc', url: 'https://docs.google.com/spreadsheets/d/abc', sheet: '', last_row: 10, auto_sync: false } as unknown as SheetImport;
const report = (created: number): SheetImportReport => ({ created, matched: 0, skipped: 0, applied: 0, vacancy_unmatched: 0, errors: [], last_row: 12, rows: 2 });

describe('SheetsImportPage: rerun of a saved import', () => {
  let run$: Subject<{ data: SheetImport; report: SheetImportReport }>;
  let importsCalls: number;
  let el: HTMLElement;
  let render: () => void;

  beforeEach(() => {
    importsCalls = 0;
    run$ = new Subject();
    const api = { imports: () => (importsCalls++, of([saved])), runImport: () => run$ };
    TestBed.configureTestingModule({
      imports: [SheetsImportPage, TranslocoTestingModule.forRoot({ langs: {}, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
      providers: [{ provide: GoogleService, useValue: api }],
    });
    const fixture = TestBed.createComponent(SheetsImportPage);
    fixture.detectChanges();
    el = fixture.nativeElement as HTMLElement;
    render = () => fixture.detectChanges();
  });

  const rerunButton = () => el.querySelector<HTMLButtonElement>('.saved button[mat-stroked-button]')!;

  it('is busy while running, then shows the report and reloads the saved imports', () => {
    rerunButton().click();
    render();
    expect(rerunButton().disabled).toBe(true);
    run$.next({ data: saved, report: report(5) });
    render();
    expect(rerunButton().disabled).toBe(false);
    expect(el.querySelector('section.ok strong')?.textContent).toBe('5');
    expect(importsCalls).toBe(2);
  });

  it('a failed rerun shows the error and frees the buttons', () => {
    rerunButton().click();
    run$.error(new HttpErrorResponse({ status: 403 }));
    render();
    expect(rerunButton().disabled).toBe(false);
    expect(el.querySelector('p.error[role="alert"]')).not.toBeNull();
    expect(el.querySelector('section.ok')).toBeNull();
  });
});
