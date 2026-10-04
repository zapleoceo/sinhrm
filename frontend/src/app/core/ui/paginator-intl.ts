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
    const nativeRangeLabel = this.getRangeLabel;
    inject(TranslocoService).selectTranslateObject<PaginatorLabels>('common.paginator')
      .pipe(takeUntilDestroyed(inject(DestroyRef)))
      .subscribe((labels) => {
        this.itemsPerPageLabel = labels.itemsPerPage;
        this.firstPageLabel = labels.firstPage;
        this.previousPageLabel = labels.previousPage;
        this.nextPageLabel = labels.nextPage;
        this.lastPageLabel = labels.lastPage;
        // Localize only the separator; Material owns page arithmetic and range formatting.
        this.getRangeLabel = (page, pageSize, length) =>
          nativeRangeLabel(page, pageSize, length).replace(' of ', ` ${labels.rangeOf} `);
        this.changes.next();
      });
  }
}

export function provideAppPaginator(): Provider {
  return { provide: MatPaginatorIntl, useClass: AppPaginatorIntl };
}
