import { CdkDrag, CdkDragDrop, CdkDragStart, CdkDropList } from '@angular/cdk/drag-drop';
import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MatDialog } from '@angular/material/dialog';
import { MatSnackBar } from '@angular/material/snack-bar';
import { By } from '@angular/platform-browser';
import { provideRouter } from '@angular/router';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { Observable, from, of } from 'rxjs';
import { AuthService } from '../../../core/auth/auth.service';
import { PeopleService } from '../../people/people.service';
import { Application, Board, PersonalBoard, Stage } from '../recruiting.model';
import { RecruitingService } from '../recruiting.service';
import { BoardPage } from './board.page';
import { BoardStore } from './board.store';
import { dropHint } from './drop-hint';

const stage = (id: number, name: string, kind: Stage['kind']): Stage => ({
  id, name, kind, position: id, is_terminal: false, is_reject: false, is_hire: kind === 'hire',
});
const stages = [stage(1, 'New', 'attract'), stage(2, 'Screen', 'select'), { ...stage(3, 'Rejected', 'closed'), is_reject: true, is_terminal: true }];
const app = (id: number, stageId: number): Application =>
  ({ id, candidate_id: id + 100, vacancy_id: 1, stage_id: stageId, status: 'active', is_stale: false, candidate: { id: id + 100, full_name: `C${id}` } }) as Application;
const board = { vacancy: { id: 1, title: 'V', stages }, applications: [app(1, 1), app(2, 1), app(3, 2)] } as unknown as Board;
const personal: PersonalBoard = {
  columns: [{ id: 7, title: 'Mine', color: 'blue', position: 0, hidden: false }],
  cards: [],
  layout: ['stage:1', 'col:7', 'stage:2', 'stage:3'],
};
/** Async like real HTTP: a synchronous of() inside the page's load effect would re-trigger it forever. */
const later = <T>(v: T): Observable<T> => from(Promise.resolve(v));
const pb = {
  dropStage: 'stage {{name}}', dropBack: 'back {{name}}', dropPersonal: 'file {{name}}', dropDenied: 'denied {{name}}',
};

/** API/dialog/snackbar doubles with call counters. */
const api = {
  board: vi.fn(() => later(board)),
  personalBoard: vi.fn(() => later(personal)),
  rejectReasons: vi.fn(() => of([])),
  vacancySources: vi.fn(() => of([])),
  move: vi.fn((id: number, body: { stage_id: number }) => later({ ...app(id, body.stage_id) })),
  fileCard: vi.fn(() => later(undefined)),
};
let dialogResult: unknown;
const dialog = { open: vi.fn(() => ({ afterClosed: () => of(dialogResult) })) };
const snack = { open: vi.fn() };

async function render(roles: string[]): Promise<ComponentFixture<BoardPage>> {
  vi.clearAllMocks();
  dialogResult = undefined;
  TestBed.configureTestingModule({
    imports: [
      BoardPage,
      TranslocoTestingModule.forRoot({
        langs: { uk: { recruiting: { personalBoard: pb } } },
        translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' },
        preloadLangs: true,
      }),
    ],
    providers: [
      provideRouter([]),
      { provide: AuthService, useValue: { user: signal({ roles }) } },
      { provide: PeopleService, useValue: {} },
      { provide: RecruitingService, useValue: api },
      { provide: MatDialog, useValue: dialog },
      { provide: MatSnackBar, useValue: snack },
    ],
  });
  const fixture = TestBed.createComponent(BoardPage);
  fixture.componentRef.setInput('id', 1);
  fixture.componentRef.setInput('personal', true);
  fixture.detectChanges();
  await fixture.whenStable();
  fixture.detectChanges();
  return fixture;
}

const cardDrags = (f: ComponentFixture<BoardPage>): CdkDrag[] =>
  f.debugElement.queryAll(By.css('article.card')).map((d) => d.injector.get(CdkDrag));
const list = (f: ComponentFixture<BoardPage>, key: string): CdkDropList => f.debugElement.query(By.css(`[id="cards-${key}"]`)).injector.get(CdkDropList);

/** Starts a drag of the first card of lane New and moves it over [key] (what CDK emits during a real drag). */
function dragOver(f: ComponentFixture<BoardPage>, key: string): HTMLElement {
  const drag = cardDrags(f)[0];
  drag.started.emit({ source: drag, event: new MouseEvent('mousedown') } as CdkDragStart);
  const target = list(f, key);
  target.entered.emit({ container: target, item: drag, currentIndex: 0 });
  f.detectChanges();
  return f.nativeElement as HTMLElement;
}

describe('BoardPage drag & drop visuals', () => {
  it('every card belongs to its lane drop list (not a free-floating drag) and uses the global preview class', async () => {
    const f = await render(['recruiter']);
    const drags = cardDrags(f);
    expect(drags.length).toBe(3);
    expect(drags.map((d) => d.dropContainer?.id)).toEqual(['cards-stage:1', 'cards-stage:1', 'cards-stage:2']);
    expect(drags.every((d) => d.previewClass === 'board-drag-preview')).toBe(true);
  });

  it('column preview classes are single tokens (CDK adds each with classList.add)', async () => {
    const f = await render(['recruiter']);
    const column = f.debugElement.query(By.css('section.column.own')).injector.get(CdkDrag);
    expect(column.previewClass).toEqual(['board-drag-preview', 'board-drag-preview--column']);
    expect((column.previewClass as string[]).every((c) => !/\s/.test(c))).toBe(true);
  });

  it('over a stage column: stage highlight + «change stage» caption, also announced', async () => {
    const el = dragOver(await render(['recruiter']), 'stage:2');
    const col = el.querySelector('section.column[data-drop]') as HTMLElement;
    expect(col.getAttribute('data-drop')).toBe('stage');
    expect(col.querySelector('.drop-caption')?.textContent?.trim()).toBe('stage Screen');
    expect(el.querySelector('[aria-live="polite"]')?.textContent?.trim()).toBe('stage Screen');
  });

  it('over an own column: neutral highlight + «file, stage stays» caption', async () => {
    const el = dragOver(await render(['recruiter']), 'col:7');
    expect(el.querySelector('section.column[data-drop]')?.getAttribute('data-drop')).toBe('personal');
    expect(el.querySelector('.drop-caption')?.textContent?.trim()).toBe('file Mine');
  });

  it('without write rights a stage column shows the denied state', async () => {
    const el = dragOver(await render(['employee']), 'stage:2');
    expect(el.querySelector('section.column[data-drop]')?.getAttribute('data-drop')).toBe('denied');
    expect(el.querySelector('.drop-caption')?.textContent?.trim()).toBe('denied Screen');
  });

  it('highlight clears when the pointer leaves the lane without entering another', async () => {
    const f = await render(['recruiter']);
    const el = dragOver(f, 'stage:2');
    const target = list(f, 'stage:2');
    target.exited.emit({ container: target, item: cardDrags(f)[0] });
    f.detectChanges();
    expect(el.querySelector('[data-drop]')).toBeNull();
    expect(el.querySelector('.drop-caption')).toBeNull();
  });

  it('body drag class is removed when the page is destroyed mid-drag', async () => {
    const f = await render(['recruiter']);
    dragOver(f, 'stage:2');
    expect(document.body.classList.contains('board-dragging')).toBe(true);
    f.destroy();
    expect(document.body.classList.contains('board-dragging')).toBe(false);
  });

  it('no caption over the lane the card came from; cleared when the drag ends', async () => {
    const f = await render(['recruiter']);
    const el = dragOver(f, 'stage:1');
    expect(el.querySelector('.drop-caption')).toBeNull();
    cardDrags(f)[0].ended.emit({ source: cardDrags(f)[0], distance: { x: 0, y: 0 }, dropPoint: { x: 0, y: 0 }, event: new MouseEvent('mouseup') });
    f.detectChanges();
    expect(el.querySelector('[data-drop]')).toBeNull();
    expect(document.body.classList.contains('board-dragging')).toBe(false);
  });
});

/** What CDK emits on the target list when the card is released there. */
async function drop(f: ComponentFixture<BoardPage>, cardIndex: number, fromKey: string, toKey: string): Promise<void> {
  const item = cardDrags(f)[cardIndex];
  const container = list(f, toKey);
  const event = {
    item, container, previousContainer: list(f, fromKey), previousIndex: 0, currentIndex: 0,
    isPointerOverContainer: true, distance: { x: 0, y: 0 }, dropPoint: { x: 0, y: 0 }, event: new MouseEvent('mouseup'),
  } as CdkDragDrop<unknown, unknown, Application>;
  container.dropped.emit(event);
  await f.whenStable();
  f.detectChanges();
}

describe('BoardPage drop', () => {
  it('stage column → one move with the application id and the stage id', async () => {
    const f = await render(['recruiter']);
    await drop(f, 0, 'stage:1', 'stage:2');
    expect(api.move).toHaveBeenCalledTimes(1);
    expect(api.move).toHaveBeenCalledWith(1, { stage_id: 2 });
    expect(api.fileCard).not.toHaveBeenCalled();
  });

  it('reject stage → RejectDialog first; move only after confirm', async () => {
    const f = await render(['recruiter']);
    dialogResult = { reject_reason_id: 5 };
    await drop(f, 0, 'stage:1', 'stage:3');
    expect(dialog.open).toHaveBeenCalledTimes(1);
    expect(api.move).toHaveBeenCalledTimes(1);
    expect(api.move).toHaveBeenCalledWith(1, { stage_id: 3, reject_reason_id: 5 });
  });

  it('reject stage cancelled → no request', async () => {
    const f = await render(['recruiter']);
    await drop(f, 0, 'stage:1', 'stage:3');
    expect(dialog.open).toHaveBeenCalledTimes(1);
    expect(api.move).not.toHaveBeenCalled();
  });

  it('own column → only filing, never a stage move', async () => {
    const f = await render(['recruiter']);
    await drop(f, 0, 'stage:1', 'col:7');
    expect(api.fileCard).toHaveBeenCalledTimes(1);
    expect(api.fileCard).toHaveBeenCalledWith(1, 7);
    expect(api.move).not.toHaveBeenCalled();
  });

  it('back into the same list → no request', async () => {
    const f = await render(['recruiter']);
    await drop(f, 0, 'stage:1', 'stage:1');
    expect(api.move).not.toHaveBeenCalled();
    expect(api.fileCard).not.toHaveBeenCalled();
  });

  it('without write rights → forbidden toast, no request', async () => {
    const f = await render(['employee']);
    await drop(f, 0, 'stage:1', 'stage:2');
    expect(snack.open).toHaveBeenCalledTimes(1);
    expect(api.move).not.toHaveBeenCalled();
  });

  it('the board loads exactly once after it settles (no reload loop with an empty reject-reason list)', async () => {
    const f = await render(['recruiter']);
    await f.whenStable();
    f.detectChanges();
    expect(api.board).toHaveBeenCalledTimes(1);
    expect(api.rejectReasons).toHaveBeenCalledTimes(1);
  });
});

describe('dropHint', () => {
  const t = (key: string, p: Record<string, string>): string => `${key.split('.').pop()}:${p['name']}`;
  const lane = { key: 'stage:2', kind: 'stage' as const, stage: stages[1], items: [] };
  it('same stage = back, other stage = stage / denied, own column = personal', () => {
    expect(dropHint(lane, app(3, 2), true, t).kind).toBe('back');
    expect(dropHint(lane, app(1, 1), true, t)).toEqual({ key: 'stage:2', kind: 'stage', text: 'dropStage:Screen' });
    expect(dropHint(lane, app(1, 1), false, t).kind).toBe('denied');
    expect(dropHint({ key: 'col:7', kind: 'personal', column: personal.columns[0], items: [] }, app(1, 1), false, t).text).toBe('dropPersonal:Mine');
  });
});

describe('BoardPage route look', () => {
  it('each column head is a station on the line: stage colour by kind, own column without a kind; decorative only', async () => {
    const f = await render(['recruiter']);
    const el = f.nativeElement as HTMLElement;
    const heads = Array.from(el.querySelectorAll<HTMLElement>('section.column .col-head'));
    const stations = heads.map((h) => h.querySelector<HTMLElement>('.app-station'));
    expect(stations.every((s) => s?.getAttribute('aria-hidden') === 'true')).toBe(true);
    expect(stations.map((s) => s?.dataset['kind'] ?? null)).toEqual(['attract', null, 'select', 'closed']);
    expect(heads.map((h) => h.querySelector('.col-count')?.textContent?.trim())).toEqual(['2', '0', '1', '0']);
  });

  it('cards carry no per-card progress dots; the board sits in the scroll-hint wrapper (CSS-only hint)', async () => {
    const el = (await render(['recruiter'])).nativeElement as HTMLElement;
    expect(el.querySelectorAll('article.card .app-station').length).toBe(0);
    expect(el.querySelector('.board-wrap > .board')).not.toBeNull();
  });
});

describe('BoardPage screening ranking', () => {
  it('has one accessible board-wide toggle and restores the card order on a second click', async () => {
    const f = await render(['recruiter']);
    const store = f.debugElement.injector.get(BoardStore);
    store.board.update((current) => current ? {
      ...current,
      applications: current.applications.map((card) => ({ ...card, screening_score: card.id === 2 ? 82 : 0 })),
    } : current);
    f.detectChanges();
    const el = f.nativeElement as HTMLElement;
    const toggles = el.querySelectorAll<HTMLButtonElement>('button[aria-pressed]');
    expect(toggles.length).toBe(1);
    expect(el.querySelector('section.column button[aria-pressed]')).toBeNull();
    expect(toggles[0].getAttribute('aria-pressed')).toBe('false');
    const names = (): string[] => Array.from(el.querySelectorAll('#cards-stage\\:1 article.card .name')).map((node) => node.textContent!.trim());
    expect(names()).toEqual(['C1', 'C2']);
    toggles[0].click();
    f.detectChanges();
    expect(toggles[0].getAttribute('aria-pressed')).toBe('true');
    expect(names()).toEqual(['C2', 'C1']);
    toggles[0].click();
    f.detectChanges();
    expect(toggles[0].getAttribute('aria-pressed')).toBe('false');
    expect(names()).toEqual(['C1', 'C2']);
    expect(api.move).not.toHaveBeenCalled();
    expect(api.fileCard).not.toHaveBeenCalled();
  });
});
