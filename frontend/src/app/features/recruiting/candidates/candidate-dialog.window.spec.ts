import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, DestroyRef, ViewContainerRef, inject } from '@angular/core';
import { By } from '@angular/platform-browser';
import { MatDialog, MatDialogModule } from '@angular/material/dialog';
import { MatSnackBarModule } from '@angular/material/snack-bar';
import { OverlayContainer } from '@angular/cdk/overlay';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { CanDeactivateFn, Router, RouterOutlet, Routes, provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { TestBed } from '@angular/core/testing';
import { Observable, Subject, of, throwError } from 'rxjs';
import { AuthService } from '../../../core/auth/auth.service';
import { WindowNavigation } from '../../../core/windows/window-navigation';
import { windowOutletRoute } from '../../../core/windows/window-route';
import { RecruitingService } from '../recruiting.service';
import { HireAction } from '../../people/hire.action';
import { PeopleService } from '../../people/people.service';
import { CandidateDialog } from './candidate.dialog';

@Component({
  standalone: true,
  imports: [RouterOutlet],
  providers: [WindowNavigation],
  template: '<router-outlet name="os1" /><router-outlet name="os2" /><router-outlet name="os3" /><router-outlet name="os4" /><router-outlet />',
})
class WindowDialogShell {
  readonly navigation = inject(WindowNavigation);
}

@Component({
  standalone: true,
  imports: [MatDialogModule, MatSnackBarModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: '<button id="open-dialog" type="button" (click)="open()">Create candidate</button><button id="hire" type="button" (click)="hire.run(7, router, destroyRef)">Hire</button>',
})
class CandidateDialogCaller {
  private readonly dialog = inject(MatDialog);
  private readonly viewContainerRef = inject(ViewContainerRef);
  protected readonly destroyRef = inject(DestroyRef);
  protected readonly hire = inject(HireAction);
  protected readonly router = inject(Router);

  open(): void {
    const ref = this.dialog.open(CandidateDialog, { viewContainerRef: this.viewContainerRef });
    this.destroyRef.onDestroy(() => ref.close());
  }
}

@Component({ standalone: true, template: '<h1>Candidate {{ id }}</h1>' })
class CandidateDetail {
  readonly id = inject(Router).routerState.snapshot.root.firstChild?.firstChild?.params['id'];
}

@Component({ standalone: true, template: '<h1>Unsaved time page</h1>' })
class DirtyTimePage {
  static instance: DirtyTimePage | null = null;
  constructor() { DirtyTimePage.instance = this; }
  canLeave(): boolean { return false; }
}

const denyDirtyLeave: CanDeactivateFn<DirtyTimePage> = (page) => page.canLeave();
const authUser = { id: 5, roles: ['hr_manager'], active_role: 'hr_manager' };
const duplicateError = new HttpErrorResponse({ status: 409, error: { code: 'duplicate_candidate', existing_id: 42, matched_by: 'email' } });
interface HireResponse {
  employee: { id: number; full_name: string };
  created: boolean;
}

const routes: Routes = [
  {
    path: '', component: WindowDialogShell, children: [
      { path: '', pathMatch: 'full', component: CandidateDialogCaller },
      windowOutletRoute('os1', [
        { path: 'candidates', component: CandidateDialogCaller, data: { windowRoute: 'candidates', module: 'recruiting' } },
        { path: 'candidates/:id', component: CandidateDetail, data: { windowRoute: 'candidate-detail', module: 'recruiting' } },
        { path: 'people/:id', component: CandidateDetail, data: { windowRoute: 'people-detail', module: 'people' } },
        { path: 'tasks', component: CandidateDetail, data: { windowRoute: 'tasks', module: null } },
      ]),
      windowOutletRoute('os2', [
        { path: 'time', component: DirtyTimePage, data: { windowRoute: 'time', module: 'time' }, canDeactivate: [denyDirtyLeave] },
      ]),
    ],
  },
];

describe('CandidateDialog window navigation', () => {
  let harness: RouterTestingHarness;
  let router: Router;
  let overlay: OverlayContainer;
  let shell: WindowDialogShell;
  let hireResponse: () => Observable<HireResponse>;

  beforeEach(async () => {
    DirtyTimePage.instance = null;
    hireResponse = () => of({ employee: { id: 84, full_name: 'Test Employee' }, created: true });
    TestBed.configureTestingModule({
      imports: [TranslocoTestingModule.forRoot({ langs: {}, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
      providers: [
        provideRouter(routes),
        { provide: AuthService, useValue: { user: () => authUser, hasModule: () => true } },
        { provide: RecruitingService, useValue: {
          channels: () => of([]),
          createCandidate: () => throwError(() => duplicateError),
        } },
        { provide: PeopleService, useValue: { hire: () => hireResponse() } },
      ],
    });
    harness = await RouterTestingHarness.create('/');
    router = TestBed.inject(Router);
    overlay = TestBed.inject(OverlayContainer);
    shell = harness.fixture.debugElement.query(By.directive(WindowDialogShell)).componentInstance as WindowDialogShell;
  });

  afterEach(() => {
    overlay.ngOnDestroy();
    TestBed.resetTestingModule();
  });

  it('routes duplicate candidate from the real dialog into its caller window and retains a dirty sibling', async () => {
    await shell.navigation.open('os1', 'candidates');
    await shell.navigation.open('os2', 'time');
    harness.fixture.detectChanges();
    const dirtyPage = DirtyTimePage.instance;
    expect(dirtyPage).not.toBeNull();

    const caller = harness.fixture.debugElement.query(By.directive(CandidateDialogCaller));
    (caller.nativeElement.querySelector('#open-dialog') as HTMLButtonElement).click();
    harness.fixture.detectChanges();
    const dialogRoot = overlay.getContainerElement();
    const name = dialogRoot.querySelector<HTMLInputElement>('input[formcontrolname="full_name"]')!;
    name.value = 'Candidate test';
    name.dispatchEvent(new Event('input'));
    dialogRoot.querySelector<HTMLFormElement>('form')!.dispatchEvent(new Event('submit'));
    await harness.fixture.whenStable();
    harness.fixture.detectChanges();

    (dialogRoot.querySelector('.dup button') as HTMLButtonElement).click();
    await harness.fixture.whenStable();
    harness.fixture.detectChanges();

    expect(router.url).toContain('os1:window/candidates/42');
    expect(router.url).toContain('os2:window/time');
    expect(DirtyTimePage.instance).toBe(dirtyPage);
  });

  it('closes its overlay when the owning window is removed', async () => {
    await shell.navigation.open('os1', 'candidates');
    harness.fixture.detectChanges();
    const caller = harness.fixture.debugElement.query(By.directive(CandidateDialogCaller));
    (caller.nativeElement.querySelector('#open-dialog') as HTMLButtonElement).click();
    harness.fixture.detectChanges();
    expect(overlay.getContainerElement().querySelector('app-candidate-dialog')).not.toBeNull();

    await shell.navigation.close('os1');
    harness.fixture.detectChanges();
    await harness.fixture.whenStable();

    expect(overlay.getContainerElement().querySelector('app-candidate-dialog')).toBeNull();
  });

  it('uses the invoking window for the Hire snackbar action without replacing a dirty sibling', async () => {
    await shell.navigation.open('os1', 'candidates');
    await shell.navigation.open('os2', 'time');
    harness.fixture.detectChanges();
    const dirtyPage = DirtyTimePage.instance;
    const caller = harness.fixture.debugElement.query(By.directive(CandidateDialogCaller));
    (caller.nativeElement.querySelector('#hire') as HTMLButtonElement).click();
    harness.fixture.detectChanges();
    const action = document.querySelector<HTMLButtonElement>('.mat-mdc-snack-bar-action');
    expect(action).not.toBeNull();
    action!.click();
    await harness.fixture.whenStable();
    harness.fixture.detectChanges();

    expect(router.url).toContain('os1:window/people/84');
    expect(router.url).toContain('os2:window/time');
    expect(DirtyTimePage.instance).toBe(dirtyPage);
  });

  it('disposes a Hire snackbar action when its originating slot is closed and reused', async () => {
    await shell.navigation.open('os1', 'candidates');
    await shell.navigation.open('os2', 'time');
    harness.fixture.detectChanges();
    const dirtyPage = DirtyTimePage.instance;
    const caller = harness.fixture.debugElement.query(By.directive(CandidateDialogCaller));
    (caller.nativeElement.querySelector('#hire') as HTMLButtonElement).click();
    harness.fixture.detectChanges();
    const staleAction = document.querySelector<HTMLButtonElement>('.mat-mdc-snack-bar-action');
    expect(staleAction).not.toBeNull();

    await shell.navigation.close('os1');
    await shell.navigation.open('os1', 'tasks');
    harness.fixture.detectChanges();
    staleAction!.click();
    await harness.fixture.whenStable();

    expect(router.url).toContain('os1:window/tasks');
    expect(router.url).toContain('os2:window/time');
    expect(DirtyTimePage.instance).toBe(dirtyPage);
  });

  it('ignores late Hire success and failure after the caller window is closed and reused', async () => {
    await shell.navigation.open('os2', 'time');
    harness.fixture.detectChanges();
    const dirtyPage = DirtyTimePage.instance;
    const completeAfterReuse = async (response: Subject<HireResponse>, fail: boolean): Promise<void> => {
      hireResponse = () => response;
      await shell.navigation.open('os1', 'candidates');
      harness.fixture.detectChanges();
      const caller = harness.fixture.debugElement.query(By.directive(CandidateDialogCaller));
      (caller.nativeElement.querySelector('#hire') as HTMLButtonElement).click();
      await shell.navigation.close('os1');
      await shell.navigation.open('os1', 'tasks');
      harness.fixture.detectChanges();
      if (fail) {
        expect(() => response.error(new HttpErrorResponse({ status: 503 }))).not.toThrow();
      } else {
        expect(() => response.next({ employee: { id: 99, full_name: 'Late Employee' }, created: true })).not.toThrow();
      }
      await harness.fixture.whenStable();
      expect(router.url).toContain('os1:window/tasks');
      expect(router.url).toContain('os2:window/time');
      expect(DirtyTimePage.instance).toBe(dirtyPage);
      expect(document.querySelector('.mat-mdc-snack-bar-container')).toBeNull();
    };

    await completeAfterReuse(new Subject<HireResponse>(), false);
    await completeAfterReuse(new Subject<HireResponse>(), true);
  });
});
