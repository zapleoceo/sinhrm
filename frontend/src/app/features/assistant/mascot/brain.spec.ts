import { ENTRANCES, EXITS } from './animations';
import { BrainCommand, MascotBrain, TIMING, pickOne, pickWeighted } from './brain';

interface Harness {
  brain: MascotBrain;
  commands: BrainCommand[];
  plays: () => string[];
  lastPlay: () => string | undefined;
  typing: { value: boolean };
}

function setup(rngValue = 0.5): Harness {
  const commands: BrainCommand[] = [];
  const typing = { value: false };
  const brain = new MascotBrain((c) => commands.push(c), { rng: () => rngValue, isTyping: () => typing.value, width: () => 1200 });
  const plays = (): string[] => commands.filter((c): c is Extract<BrainCommand, { type: 'play' }> => c.type === 'play').map((c) => c.action);
  return { brain, commands, plays, lastPlay: () => plays().at(-1), typing };
}

describe('MascotBrain', () => {
  beforeEach(() => vi.useFakeTimers());
  afterEach(() => vi.useRealTimers());

  it('enters a few seconds after start, greets, idles, then leaves and schedules the next visit', () => {
    const h = setup();
    h.brain.start(true, false);
    expect(h.brain.state).toBe('offstage');
    vi.advanceTimersByTime(TIMING.firstAppearance);
    expect(h.brain.state).toBe('entering');
    expect(h.commands).toContainEqual({ type: 'visible', value: true });
    const entrance = h.lastPlay()!;
    expect(ENTRANCES as readonly string[]).toContain(entrance);

    h.brain.clipDone(entrance === 'enter-peek' ? 'peek-out' : (entrance as never));
    if (entrance === 'enter-peek') {
      h.brain.clipDone('peek-out');
    }
    expect(h.brain.state).toBe('idle');
    expect(h.lastPlay()).toBe('idle-breathe');
    expect(h.commands.some((c) => c.type === 'say' && c.key?.startsWith('assistant.greetings.'))).toBe(true);

    // Idle alternates breathing and weighted fidgets.
    h.brain.clipDone('idle-breathe');
    expect(h.lastPlay()).not.toBe('idle-breathe');

    vi.advanceTimersByTime(TIMING.stay);
    expect(h.brain.state).toBe('exiting');
    const exit = h.lastPlay()!;
    expect(EXITS as readonly string[]).toContain(exit);
    h.brain.clipDone(exit as never);
    expect(h.brain.state).toBe('offstage');
    expect(h.commands.at(-1)).toEqual({ type: 'visible', value: false });

    vi.advanceTimersByTime(TIMING.minGap - 1);
    expect(h.brain.state).toBe('offstage');
    vi.advanceTimersByTime(TIMING.maxGap - TIMING.minGap + 1);
    expect(h.brain.state).toBe('entering');
  });

  it('a peek either ducks back or comes out', () => {
    const out = setup(0.9);
    out.brain.start(true, false);
    vi.advanceTimersByTime(TIMING.firstAppearance);
    out.brain.clipDone('enter-peek');
    expect(out.lastPlay()).toBe('peek-out');

    const back = setup(0.1);
    back.brain.start(true, false);
    vi.advanceTimersByTime(TIMING.firstAppearance);
    back.brain.clipDone('enter-peek');
    expect(back.lastPlay()).toBe('exit-peek');
    back.brain.clipDone('exit-peek');
    expect(back.brain.state).toBe('offstage');
  });

  it('stays while hovered', () => {
    const h = setup();
    h.brain.start(true, false);
    vi.advanceTimersByTime(TIMING.firstAppearance);
    h.brain.clipDone('enter-walk');
    h.brain.hover(true);
    vi.advanceTimersByTime(TIMING.stay + TIMING.hoverGrace * 3);
    expect(h.brain.state).toBe('idle');
    h.brain.hover(false);
    vi.advanceTimersByTime(TIMING.hoverGrace);
    expect(h.brain.state).toBe('exiting');
  });

  it('waits while the user is typing', () => {
    const h = setup();
    h.typing.value = true;
    h.brain.start(true, false);
    vi.advanceTimersByTime(TIMING.firstAppearance + TIMING.typingRetry * 2);
    expect(h.brain.state).toBe('offstage');
    h.typing.value = false;
    vi.advanceTimersByTime(TIMING.typingRetry);
    expect(h.brain.state).toBe('entering');
  });

  it('falls asleep after inactivity on the chat and wakes with a start when the pointer comes near', () => {
    const h = setup();
    h.brain.start(true, false);
    h.brain.chatOpened();
    expect(h.brain.state).toBe('docked');
    expect(h.lastPlay()).toBe('docked');
    vi.advanceTimersByTime(TIMING.sleepAfter - 1000);
    h.brain.userActivity();
    vi.advanceTimersByTime(TIMING.sleepAfter - 1000);
    expect(h.brain.state).toBe('docked');
    vi.advanceTimersByTime(1000);
    expect(h.brain.state).toBe('asleep');
    expect(h.lastPlay()).toBe('sleep');
    h.brain.pointerNear();
    expect(h.lastPlay()).toBe('wake');
    h.brain.clipDone('wake');
    expect(h.brain.state).toBe('docked');
  });

  it('chat: docks on open, leaves on close; moods become gestures', () => {
    const h = setup();
    h.brain.start(true, false);
    h.brain.chatOpened();
    h.brain.mood('think');
    expect(h.commands.at(-1)).toEqual({ type: 'gesture', name: 'think' });
    h.brain.mood('talk', 3000);
    expect(h.commands.at(-1)).toEqual({ type: 'gesture', name: 'talk' });
    vi.advanceTimersByTime(3000);
    expect(h.commands.at(-1)).toEqual({ type: 'gesture', name: null });
    h.brain.chatClosed();
    expect(h.brain.state).toBe('exiting');
  });

  it('drag → thrown → hard landing → dizzy → dusts off → idle', () => {
    const h = setup();
    h.brain.start(true, false);
    vi.advanceTimersByTime(TIMING.firstAppearance);
    h.brain.clipDone('enter-walk');
    expect(h.brain.canDrag).toBe(true);
    h.brain.dragStart();
    h.brain.dragEnd();
    expect(h.brain.state).toBe('airborne');
    h.brain.landed(1600);
    expect(h.lastPlay()).toBe('dizzy');
    h.brain.clipDone('dizzy');
    expect(h.lastPlay()).toBe('dust');
    h.brain.clipDone('dust');
    expect(h.brain.state).toBe('idle');
  });

  it('switched off: curls into the circle, no appearances, no quips; switched on: unfolds and greets', () => {
    const h = setup();
    h.brain.start(true, false);
    vi.advanceTimersByTime(TIMING.firstAppearance);
    h.brain.clipDone('enter-walk');
    h.brain.setEnabled(false);
    expect(h.brain.state).toBe('curling');
    expect(h.lastPlay()).toBe('curl');
    h.brain.clipDone('curl');
    expect(h.brain.state).toBe('orb');
    expect(h.lastPlay()).toBe('orb');
    const before = h.commands.length;
    vi.advanceTimersByTime(TIMING.maxGap * 2);
    h.brain.routeChanged('/candidates');
    vi.advanceTimersByTime(TIMING.routeDelay * 2);
    expect(h.brain.state).toBe('orb');
    expect(h.commands.slice(before).some((c) => c.type === 'say' && c.key)).toBe(false);

    h.brain.setEnabled(true);
    expect(h.brain.state).toBe('unfolding');
    expect(h.lastPlay()).toBe('unfold');
    h.brain.clipDone('unfold');
    expect(h.brain.state).toBe('idle');
    expect(h.commands.some((c) => c.type === 'say' && c.key?.startsWith('assistant.greetings.'))).toBe(true);
  });

  it('starts as the circle when switched off, and the chat still opens', () => {
    const h = setup();
    h.brain.start(false, false);
    expect(h.brain.state).toBe('orb');
    expect(h.lastPlay()).toBe('orb-pop');
    h.brain.clipDone('orb-pop');
    expect(h.lastPlay()).toBe('orb');
    h.brain.chatOpened();
    expect(h.brain.state).toBe('orb');
    vi.advanceTimersByTime(TIMING.firstAppearance * 10);
    expect(h.brain.state).toBe('orb');
  });

  it('reduced motion: a static pose in the corner, no random entrances, instant switching', () => {
    const h = setup();
    h.brain.start(true, true);
    expect(h.brain.state).toBe('static');
    expect(h.commands).toContainEqual({ type: 'play', action: 'static', targetX: 1110, blend: 0 });
    vi.advanceTimersByTime(TIMING.maxGap * 2);
    expect(h.plays().filter((a) => a.startsWith('enter-'))).toEqual([]);
    h.brain.setEnabled(false);
    expect(h.lastPlay()).toBe('orb');
    expect(h.commands).toContainEqual({ type: 'play', action: 'orb', blend: 0 });
  });

  it('may pop up on navigation, at most once per cooldown', () => {
    const h = setup(0.1);
    h.brain.start(true, false);
    vi.advanceTimersByTime(TIMING.firstAppearance);
    h.brain.clipDone('enter-walk');
    vi.advanceTimersByTime(TIMING.stay);
    h.brain.clipDone(h.lastPlay() as never);
    expect(h.brain.state).toBe('offstage');
    h.brain.routeChanged('/candidates');
    vi.advanceTimersByTime(TIMING.routeDelay);
    expect(h.brain.state).toBe('offstage');
    vi.advanceTimersByTime(TIMING.routeCooldown);
    h.brain.routeChanged('/candidates/5');
    vi.advanceTimersByTime(TIMING.routeDelay);
    expect(h.brain.state).toBe('entering');
  });

  it('pickers never repeat the previous choice', () => {
    for (let i = 0; i < 20; i++) {
      expect(pickOne(['a', 'b', 'c'], 'b', () => i / 20)).not.toBe('b');
      expect(pickWeighted<'a' | 'b' | 'c'>({ a: 1, b: 5, c: 1 }, 'b', () => i / 20)).not.toBe('b');
    }
    expect(pickOne(['only'], 'only', () => 0.3)).toBe('only');
  });
});
