import { TestBed } from '@angular/core/testing';
import { MatSnackBar } from '@angular/material/snack-bar';
import { TranslocoService } from '@jsverse/transloco';
import { NOTIFY_DURATION_MS, NotifyService } from './notify.service';

describe('NotifyService', () => {
  let open: ReturnType<typeof vi.fn>;
  let notify: NotifyService;

  beforeEach(() => {
    open = vi.fn(() => ({ dismiss: vi.fn() }));
    TestBed.configureTestingModule({
      providers: [
        { provide: MatSnackBar, useValue: { open } },
        { provide: TranslocoService, useValue: { translate: (key: string, params?: Record<string, unknown>) => (params ? `${key}:${JSON.stringify(params)}` : `t(${key})`) } },
      ],
    });
    notify = TestBed.inject(NotifyService);
  });

  it('shows the translated key without an action for the default 4 s (snack-bar a11y defaults untouched)', () => {
    notify.show('desk.errors.not_found');
    expect(open).toHaveBeenCalledWith('t(desk.errors.not_found)', undefined, { duration: NOTIFY_DURATION_MS });
    expect(NOTIFY_DURATION_MS).toBe(4000);
    // No politeness/role overrides: the snack bar keeps its own live region and role.
    expect(Object.keys(open.mock.calls[0][2] as object)).toEqual(['duration']);
  });

  it('passes translation params and a custom duration', () => {
    notify.show('shell.menu.workingAs', { params: { role: 'HR' }, duration: 3000 });
    expect(open).toHaveBeenCalledWith('shell.menu.workingAs:{"role":"HR"}', undefined, { duration: 3000 });
  });
});
