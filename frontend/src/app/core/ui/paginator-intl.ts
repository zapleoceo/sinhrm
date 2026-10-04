import { DestroyRef, Injectable, Provider, inject } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { MatPaginatorIntl } from '@angular/material/paginator';
import { TranslocoService } from '@jsverse/transloco';

interface PaginatorLabels {
  itemsPerPage: string;
  firstPage: string;
  previousPage: string;
  nextPage: string;
  lastPage: string;
  rangeOf: string;
}

/** Shared Material labels follow the active UI language once its dictionary is loaded. */
@Injectable()
export class AppPaginatorIntl extends MatPaginatorIntl {
  constructor() {
    super();
    inject(TranslocoService).selectTranslateObject<PaginatorLabels>('common.paginator')
      .pipe(takeUntilDestroyed(inject(DestroyRef)))
      .subscribe((labels) => {
        this.itemsPerPageLabel = labels.itemsPerPage;
        this.firstPageLabel = labels.firstPage;
        this.previousPageLabel = labels.previousPage;
        this.nextPageLabel = labels.nextPage;
        this.lastPageLabel = labels.lastPage;
        this.getRangeLabel = (page, pageSize, length) => {
          const total = Math.max(0, length);
          if (total === 0 || pageSize <= 0) return `0 ${labels.rangeOf} ${total}`;
          // A reduced total can leave the current page past the end while the list reloads.
          const current = Math.max(0, Math.min(page, Math.ceil(total / pageSize) - 1));
          const start = current * pageSize;
          const end = Math.min(start + pageSize, total);
          return `${start + 1}–${end} ${labels.rangeOf} ${total}`;
        };
        this.changes.next();
      });
  }
}

export function provideAppPaginator(): Provider {
  return { provide: MatPaginatorIntl, useClass: AppPaginatorIntl };
}
