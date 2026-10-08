import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse } from '@angular/common/http';
import { MatDialogRef } from '@angular/material/dialog';
import { Subject, throwError } from 'rxjs';
import { DialogSave } from './dialog-save';

describe('DialogSave', () => {
  const create = (): { save: DialogSave<string>; close: ReturnType<typeof vi.fn> } => {
    const close = vi.fn();
    TestBed.configureTestingModule({ providers: [{ provide: MatDialogRef, useValue: { close } }] });
    return { save: TestBed.runInInjectionContext(() => new DialogSave<string>()), close };
  };

  it('is saving while the request runs and closes the dialog with the saved answer', () => {
    const { save, close } = create();
    save.error.set('people.changes.nothing');
    const answer = new Subject<string>();
    save.save(answer);
    expect(save.saving()).toBe(true);
    expect(save.error()).toBeNull();
    answer.next('saved');
    expect(close).toHaveBeenCalledWith('saved');
  });

  it('an error stays in the dialog as a people error key and the form is enabled again', () => {
    const { save, close } = create();
    save.save(throwError(() => new HttpErrorResponse({ status: 422, error: { code: 'unknown' } })));
    expect(save.error()).toBe('people.errors.validation');
    expect(save.saving()).toBe(false);
    expect(close).not.toHaveBeenCalled();
  });
});
