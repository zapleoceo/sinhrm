import { HttpErrorResponse } from '@angular/common/http';
import { TestBed } from '@angular/core/testing';
import { Observable, of, throwError } from 'rxjs';
import en from '../../../../public/i18n/en.json';
import ru from '../../../../public/i18n/ru.json';
import uk from '../../../../public/i18n/uk.json';
import { QuipsResult } from './assistant.model';
import { AssistantService } from './assistant.service';
import { AssistantJokes, JOKE_FALLBACK_COUNT, JOKE_MAX_TRIES, JOKE_RETRY_MS, JOKE_SITUATIONS, pickNoRepeat, situationFor } from './jokes';
import { BrainCommand, MascotBrain, TIMING, dispatchEngineEvent } from './mascot/brain';
import { MascotEngine } from './mascot/mascot-engine';

function setup(quips: (situation: string, locale: string) => Observable<QuipsResult>) {
  const api = { quips: vi.fn(quips) };
  TestBed.configureTestingModule({ providers: [{ provide: AssistantService, useValue: api }] });
  return { jokes: TestBed.inject(AssistantJokes), api };
}

describe('jokes after a fall', () => {
  it('maps what happened to a situation', () => {
    expect(situationFor('throw')).toBe('thrown');
    expect(situationFor('slip')).toBe('slip');
    expect(situationFor('trip')).toBe('fall');
    expect(situationFor('faint')).toBe('fall');
    expect(situationFor('drop')).toBe('fall');
  });

  it('never repeats any of the last 5', () => {
    const items = ['a', 'b', 'c', 'd', 'e', 'f', 'g'];
    const recent: string[] = [];
    const shown: string[] = [];
    let seed = 7;
    const rng = (): number => {
      seed = (seed * 16807) % 2147483647;
      return (seed - 1) / 2147483646;
    };
    for (let i = 0; i < 200; i++) {
      const j = pickNoRepeat(items, recent, rng);
      expect(shown.slice(-5)).not.toContain(j);
      shown.push(j as string);
    }
    expect(pickNoRepeat([], [], rng)).toBeNull();
    // Fewer items than the memory: still never the same twice in a row.
    const two: string[] = [];
    let last = '';
    for (let i = 0; i < 20; i++) {
      const j = pickNoRepeat(['x', 'y'], two, rng) as string;
      expect(j).not.toBe(last);
      last = j;
    }
  });

  it('prefetches each situation once per language; a new language fetches again', async () => {
    const { jokes, api } = setup(() => of({ jokes: ['ха'], source: 'ai' }));
    await jokes.prefetch('uk');
    await jokes.prefetch('uk');
    expect(api.quips).toHaveBeenCalledTimes(JOKE_SITUATIONS.length);
    expect(api.quips.mock.calls.map((c) => c[0]).sort()).toEqual([...JOKE_SITUATIONS].sort());
    await jokes.prefetch('en');
    expect(api.quips).toHaveBeenCalledTimes(JOKE_SITUATIONS.length * 2);
    expect(api.quips).toHaveBeenLastCalledWith(expect.any(String), 'en');
  });

  it('uses the AI pool when there is one, built-in jokes when it is empty, off or throttled', async () => {
    const { jokes } = setup((s) =>
      s === 'thrown'
        ? of({ jokes: ['AI joke about flying'], source: 'ai' as const })
        : s === 'slip'
          ? of({ jokes: [], source: 'none' as const })
          : throwError(() => new HttpErrorResponse({ status: 429 })),
    );
    await jokes.prefetch('uk');
    expect(jokes.pick('thrown', 'uk', () => 0.3)).toEqual({ text: 'AI joke about flying' });
    expect(jokes.pick('slip', 'uk', () => 0.3)).toEqual({ key: expect.stringMatching(/^assistant\.jokes\.slip\.\d$/) });
    expect(jokes.pick('fall', 'uk', () => 0.3)).toEqual({ key: expect.stringMatching(/^assistant\.jokes\.fall\.\d$/) });
    // Another language not fetched yet → built-in.
    expect(jokes.pick('thrown', 'ru', () => 0.3)).toEqual({ key: expect.stringMatching(/^assistant\.jokes\.thrown\.\d$/) });
  });

  it('asks again for an empty pool after a minute (server still generating), at most 4 times', async () => {
    vi.useFakeTimers();
    let ready = false;
    const { jokes, api } = setup((s) => of(ready && s === 'fall' ? { jokes: ['Приземлився!'], source: 'ai' as const } : { jokes: [], source: 'none' as const }));
    await jokes.prefetch('uk');
    const fallCalls = (): number => api.quips.mock.calls.filter((c) => c[0] === 'fall').length;
    expect(fallCalls()).toBe(1);

    jokes.pick('fall', 'uk', () => 0.3); // < 1 min: no new request
    expect(fallCalls()).toBe(1);

    ready = true;
    vi.advanceTimersByTime(JOKE_RETRY_MS);
    jokes.pick('fall', 'uk', () => 0.3); // built-in now, AI pool fetched in the background
    await Promise.resolve();
    expect(fallCalls()).toBe(2);
    expect(jokes.pick('fall', 'uk', () => 0.3)).toEqual({ text: 'Приземлився!' });

    ready = false;
    for (let i = 0; i < 10; i++) {
      vi.advanceTimersByTime(JOKE_RETRY_MS);
      jokes.pick('slip', 'uk', () => 0.3);
      await Promise.resolve();
    }
    expect(api.quips.mock.calls.filter((c) => c[0] === 'slip').length).toBe(JOKE_MAX_TRIES);
    vi.useRealTimers();
  });

  it.each([
    ['uk', uk],
    ['ru', ru],
    ['en', en],
  ])('%s has built-in jokes for every situation', (_l, file) => {
    const all = (file as unknown as { assistant: { jokes: Record<string, Record<string, string>> } }).assistant.jokes;
    for (const s of JOKE_SITUATIONS) {
      for (let i = 0; i < JOKE_FALLBACK_COUNT; i++) {
        expect(all[s][String(i)].length).toBeGreaterThan(5);
        expect(all[s][String(i)].length).toBeLessThanOrEqual(120);
      }
    }
  });
});

describe('brain tells a joke once he is up', () => {
  beforeEach(() => vi.useFakeTimers());
  afterEach(() => vi.useRealTimers());

  function run(opts: { rng: number; chance?: number; canJoke?: boolean; cause?: 'throw' | 'slip' | 'trip' }) {
    const commands: BrainCommand[] = [];
    const joke = vi.fn((s: string) => ({ text: `joke:${s}` }));
    const brain = new MascotBrain((c) => commands.push(c), {
      rng: () => opts.rng,
      width: () => 1200,
      joke,
      canJoke: () => opts.canJoke ?? true,
      jokeChance: opts.chance ?? 0.7,
    });
    brain.start(true, false);
    vi.advanceTimersByTime(TIMING.firstAppearance);
    brain.clipDone('enter-walk');
    if (opts.cause === 'throw') {
      brain.dragStart();
      brain.dragEnd();
    }
    brain.fell(opts.cause ?? 'trip');
    brain.rested(300);
    vi.advanceTimersByTime(3000);
    const before = commands.length;
    brain.clipDone('getup');
    const said = commands.slice(before).filter((c) => c.type === 'say' && (c.text || c.key));
    brain.clipDone('rub-head');
    brain.clipDone('dust');
    return { said, joke, brain };
  }

  it('as soon as he is on his feet: one joke for the situation (probability injected)', () => {
    const { said, joke, brain } = run({ rng: 0.2, cause: 'slip' });
    expect(brain.state).toBe('idle');
    expect(joke).toHaveBeenCalledWith('slip');
    expect(said).toEqual([{ type: 'say', key: null, text: 'joke:slip' }]);
    expect(run({ rng: 0.2, cause: 'throw' }).joke).toHaveBeenCalledWith('thrown');
    expect(run({ rng: 0.2, cause: 'trip' }).joke).toHaveBeenCalledWith('fall');
  });

  it('not every time, and never while the chat answers or someone types', () => {
    expect(run({ rng: 0.9 }).said).toEqual([]);
    expect(run({ rng: 0.2, canJoke: false }).said).toEqual([]);
    expect(run({ rng: 0.2, chance: 0 }).said).toEqual([]);
  });

  it('a built-in joke is said by its i18n key; reduced motion never falls, so never jokes', () => {
    const commands: BrainCommand[] = [];
    const brain = new MascotBrain((c) => commands.push(c), { rng: () => 0.1, joke: () => ({ key: 'assistant.jokes.fall.2' }) });
    brain.start(true, false);
    vi.advanceTimersByTime(TIMING.firstAppearance);
    brain.clipDone('enter-walk');
    brain.fell('faint');
    brain.rested(200);
    vi.advanceTimersByTime(3000);
    brain.clipDone('getup');
    brain.clipDone('rub-head');
    brain.clipDone('dust');
    expect(commands).toContainEqual({ type: 'say', key: 'assistant.jokes.fall.2' });

    const reducedSaid: BrainCommand[] = [];
    const joke = vi.fn(() => ({ text: 'x' }));
    const reduced = new MascotBrain((c) => reducedSaid.push(c), { rng: () => 0.1, joke });
    reduced.start(true, true);
    reduced.dragStart();
    reduced.dragEnd();
    reduced.fell('throw');
    reduced.clipDone('dust');
    expect(joke).not.toHaveBeenCalled();
  });
});

describe('joke with the chat panel open (docked)', () => {
  beforeEach(() => vi.useFakeTimers());
  afterEach(() => vi.useRealTimers());

  it('drag from the chat seat → throw → fall → rest → get up → back to the seat → the joke is said', () => {
    const stage = { width: 1200, height: 800, ground: 798, seat: { x: 1000, y: 300 }, corner: { x: 1166, y: 766 } };
    const engine = new MascotEngine(stage, () => 0.4);
    type Say = Extract<BrainCommand, { type: 'say' }>;
    const timeline: string[] = [];
    let frame = 0;
    const says: Say[] = [];
    const actions: string[] = [];
    const brain = new MascotBrain(
      (c) => {
        if (c.type === 'play') {
          actions.push(c.action);
          timeline.push(`${(frame / 60).toFixed(1)}s play ${c.action}`);
          engine.play(c.action, { side: c.side, targetX: c.targetX, blend: c.blend, variant: c.variant });
        } else if (c.type === 'say') {
          says.push(c);
          timeline.push(`${(frame / 60).toFixed(1)}s say ${c.text ?? c.key}`);
          engine.say(c.text ?? c.key);
        } else if (c.type === 'gesture') {
          engine.gesture(c.name);
        }
      },
      { rng: () => 0.4, width: () => 1200, joke: (s) => ({ text: `joke:${s}` }), jokeChance: 1 },
    );
    const frames = (n: number): void => {
      for (let i = 0; i < n; i++) {
        frame++;
        vi.advanceTimersByTime(1000 / 60);
        for (const e of engine.tick(1 / 60).events) {
          dispatchEngineEvent(brain, e);
        }
      }
    };
    brain.start(true, false);
    brain.chatOpened();
    frames(90);
    expect(brain.state).toBe('docked');
    const r = engine.root;
    brain.dragStart();
    engine.dragStart(r.x, r.y - 20, 0);
    for (let i = 1; i <= 10; i++) {
      engine.dragMove(r.x - i * 25, r.y - 20 - i * 10, (i * 1000) / 60);
      frames(1);
    }
    engine.dragEnd();
    brain.dragEnd();
    timeline.push(`${(frame / 60).toFixed(1)}s RELEASE`);
    for (let i = 0; i < 1500 && !(brain.state === 'docked' && actions.includes('dust')); i++) {
      frames(1);
    }
    frames(120);
    expect(actions).toEqual(expect.arrayContaining(['getup', 'dust']));
    // Said as soon as he is back on his feet — not seconds later after the head rub and dust-off (the prod bug:
    // throwing him again in that window meant never hearing a joke).
    const at = (needle: string): number => timeline.findIndex((l) => l.includes(needle));
    const jokeLine = timeline[at('say joke:thrown')];
    const release = Number(timeline[at('RELEASE')].split('s')[0]);
    expect(at('say joke:thrown')).toBeLessThan(at('play rub-head'));
    expect(Number(jokeLine.split('s')[0]) - release).toBeLessThan(10);
    // With the chat open he hops back to the seat, then sits.
    expect(at('play seat-route')).toBeGreaterThan(at('play dust'));
    expect(brain.state).toBe('docked');
    expect(says.some((c) => c.text === 'joke:thrown')).toBe(true);
    // The last bubble command is not a clear that wiped the joke immediately.
    const jokeIdx = says.findIndex((c) => c.text === 'joke:thrown');
    expect(says.slice(jokeIdx + 1).filter((c) => c.key === null && !c.text).length).toBeLessThanOrEqual(1);
  });
});

describe('joke bubble placement on the chat seat', () => {
  it('stays on screen when the chat panel reaches the top edge (placed beside/below the head)', async () => {
    const { MascotRenderer } = await import('./mascot/mascot-renderer');
    const stage = { width: 1200, height: 800, ground: 798, seat: { x: 1000, y: 30 }, corner: { x: 1166, y: 766 } };
    const engine = new MascotEngine(stage, () => 0.4);
    engine.play('docked', { blend: 0 });
    engine.tick(0.5);
    engine.say('Себе в резюме додам: «здатний до польотів без ліцензії».');
    const host = document.createElement('div');
    document.body.appendChild(host);
    const renderer = new MascotRenderer(host, document);
    const frame = engine.tick(0.3).frame;
    renderer.render(frame, { width: 1200, height: 800 }, false);
    const oy = Number(/translate3d\([^,]+,\s*(-?[\d.]+)px/.exec(host.style.transform)?.[1]);
    const bubble = host.querySelector('.mascot-bubble') as HTMLElement;
    const by = Number(/translate\([^,]+,\s*(-?[\d.]+)px\)/.exec(bubble.style.transform)?.[1]);
    expect(bubble.style.display).toBe('block');
    expect(oy + by).toBeGreaterThanOrEqual(8);
    // And it does not cover his face: it starts beside the head.
    const headX = frame.pose.x + frame.joints.head.x;
    const ox = Number(/translate3d\((-?[\d.]+)px/.exec(host.style.transform)?.[1]);
    const bx = Number(/translate\((-?[\d.]+)px/.exec(bubble.style.transform)?.[1]);
    const left = ox + bx;
    expect(left > headX + 10 || left + 180 < headX - 10).toBe(true);
    renderer.destroy();
    host.remove();
  });
});
