import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse } from '@angular/common/http';
import { Subject, of, throwError } from 'rxjs';
import { NotifyService } from '../../core/ui/notify.service';
import { ReportRun } from './report-run';

describe('ReportRun', () => {
  let shown: string[];
  const create = (): ReportRun<{ rows: number }> => {
    shown = [];
    TestBed.configureTestingModule({ providers: [{ provide: NotifyService, useValue: { show: (key: string) => shown.push(key) } }] });
    return TestBed.runInInjectionContext(() => new ReportRun<{ rows: number }>());
  };

  it('keeps the latest result; a newer run cancels the one still in flight', () => {
    const run = create();
    const slow = new Subject<{ rows: number }>();
    run.run(slow);
    expect(run.loading()).toBe(true);
    run.run(of({ rows: 2 }));
    expect(slow.observed).toBe(false);
    expect(run.result()).toEqual({ rows: 2 });
    expect(run.loading()).toBe(false);
  });

  it('an error keeps the previous result and shows the reports error key', () => {
    const run = create();
    run.run(of({ rows: 1 }));
    run.run(throwError(() => new HttpErrorResponse({ status: 403 })));
    expect(run.result()).toEqual({ rows: 1 });
    expect(run.loading()).toBe(false);
    expect(shown).toEqual(['reports.errors.forbidden']);
  });

  it('a failed CSV download is a notification too', () => {
    const run = create();
    run.download(throwError(() => new HttpErrorResponse({ status: 422 })), 'x.csv');
    expect(shown).toEqual(['reports.errors.validation']);
  });
});
