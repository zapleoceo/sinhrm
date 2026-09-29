import { TestBed } from '@angular/core/testing';
import { provideNativeDateAdapter } from '@angular/material/core';
import { MAT_DIALOG_DATA, MatDialogRef } from '@angular/material/dialog';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { of } from 'rxjs';
import { WIDE_DIALOG_CLASS, wideDialog } from '../../../core/ui/dialog';
import { DirectoryService } from '../../directory/directory.service';
import { PeopleService } from '../people.service';
import { EmployeeDialog, EmployeeDialogData } from './employee.dialog';

describe('EmployeeDialog', () => {
  function render(data: EmployeeDialogData = { employee: null }): HTMLElement {
    TestBed.configureTestingModule({
      imports: [EmployeeDialog, TranslocoTestingModule.forRoot({ langs: { uk: {} }, translocoConfig: { defaultLang: 'uk' } })],
      providers: [
        provideNativeDateAdapter(),
        { provide: MAT_DIALOG_DATA, useValue: data },
        { provide: MatDialogRef, useValue: { close: vi.fn() } },
        { provide: DirectoryService, useValue: { active: () => of([]) } },
        { provide: PeopleService, useValue: { list: () => of({ data: [] }) } },
      ],
    });
    const fixture = TestBed.createComponent(EmployeeDialog);
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  it('groups the form into main / work / personal sections', () => {
    const el = render();
    const headings = Array.from(el.querySelectorAll('h3.section')).map((h) => h.textContent?.trim().replace(/^uk\./, ''));
    expect(headings).toEqual(['people.edit.sections.main', 'people.edit.sections.work', 'people.edit.sections.personal']);
    expect(el.querySelectorAll('mat-dialog-content .grid').length).toBe(3);
  });

  it('focuses the full name first and lets hints wrap', () => {
    const el = render();
    expect(el.querySelector('input[formcontrolname="full_name"]')?.hasAttribute('cdkFocusInitial')).toBe(true);
    const fields = el.querySelectorAll('mat-form-field');
    expect(fields.length).toBeGreaterThan(10);
    expect(el.querySelectorAll('.mat-mdc-form-field-subscript-dynamic-size').length).toBe(fields.length);
  });

  it('has no fixed min-width that could force horizontal scroll', () => {
    const styles = ((EmployeeDialog as unknown as { ɵcmp: { styles: string[] } }).ɵcmp.styles ?? []).join('\n');
    expect(styles).toContain('auto-fit');
    expect(styles).not.toMatch(/min-width:\s*min\(/);
    render().querySelectorAll<HTMLElement>('*').forEach((n) => expect(n.style.minWidth).toBe(''));
  });

  it('opens as a wide, viewport-capped dialog', () => {
    expect(wideDialog({ employee: null })).toEqual({
      data: { employee: null },
      width: '720px',
      maxWidth: '95vw',
      panelClass: WIDE_DIALOG_CLASS,
    });
  });
});
