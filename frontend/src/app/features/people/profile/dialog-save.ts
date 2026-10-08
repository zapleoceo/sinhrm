import { inject, signal } from '@angular/core';
import { MatDialogRef } from '@angular/material/dialog';
import { Observable } from 'rxjs';
import { peopleErrorKey } from '../people.service';

/**
 * Save state of a People dialog: `saving` disables the form, `error` is the i18n key shown in the dialog; the saved
 * answer closes the dialog with it. Create it in the dialog's injection context (a field).
 */
export class DialogSave<R> {
  private readonly ref = inject<MatDialogRef<unknown, R>>(MatDialogRef);
  readonly saving = signal(false);
  /** i18n key of the last error (also set directly by the dialog for its own checks); null — none. */
  readonly error = signal<string | null>(null);

  save(request: Observable<R>): void {
    this.saving.set(true);
    this.error.set(null);
    request.subscribe({
      next: (saved) => this.ref.close(saved),
      error: (err: unknown) => {
        this.error.set(peopleErrorKey(err));
        this.saving.set(false);
      },
    });
  }
}
