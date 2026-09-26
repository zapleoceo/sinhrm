import { Injectable, inject } from '@angular/core';
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
  private readonly router = inject(Router);

  run(applicationId: number): void {
    this.api.hire(applicationId).subscribe({
      next: ({ employee, created }) => {
        const ref = this.snack.open(
          this.i18n.translate(created ? 'people.hire.created' : 'people.hire.exists', { name: employee.full_name }),
          this.i18n.translate('people.hire.open'),
          { duration: 6000 },
        );
        ref.onAction().subscribe(() => void this.router.navigate(['/people', employee.id]));
      },
      error: (e: unknown) => this.snack.open(this.i18n.translate(peopleErrorKey(e)), undefined, { duration: 4000 }),
    });
  }
}
