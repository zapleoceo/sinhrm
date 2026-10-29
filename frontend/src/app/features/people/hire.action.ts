import { DestroyRef, Injectable, inject } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { MatSnackBar } from '@angular/material/snack-bar';
import { Router } from '@angular/router';
import { TranslocoService } from '@jsverse/transloco';
import { PeopleService, peopleErrorKey } from './people.service';

/**
 * "Hire → create employee" from Recruiting (board card, candidate card). The API is idempotent: a second click
 * opens the same employee. The snack bar offers to open the new profile.
 */
@Injectable({ providedIn: 'root' })
export class HireAction {
  private readonly api = inject(PeopleService);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  run(applicationId: number, router: Router, origin: DestroyRef): void {
    this.api.hire(applicationId).pipe(takeUntilDestroyed(origin)).subscribe({
      next: ({ employee, created }) => {
        const ref = this.snack.open(
          this.i18n.translate(created ? 'people.hire.created' : 'people.hire.exists', { name: employee.full_name }),
          this.i18n.translate('people.hire.open'),
          { duration: 6000 },
        );
        // Bind this delayed action to the caller lifetime. A scoped Router is reusable, so invoking it after the
        // originating view has been destroyed could otherwise hijack a later occupant of the same window slot.
        const unregisterDestroy = origin.onDestroy(() => ref.dismiss());
        ref.afterDismissed().subscribe(() => unregisterDestroy());
        // Supply the current Router facade per invocation. This singleton must not retain a scoped window router.
        ref.onAction().pipe(takeUntilDestroyed(origin)).subscribe(() => void router.navigate(['/people', employee.id]));
      },
      error: (e: unknown) => this.snack.open(this.i18n.translate(peopleErrorKey(e)), undefined, { duration: 4000 }),
    });
  }
}
