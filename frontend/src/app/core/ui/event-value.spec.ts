import { eventValue } from './event-value';

describe('eventValue', () => {
  it('reads the value of the input, textarea or select that fired the event', () => {
    for (const tag of ['input', 'textarea'] as const) {
      const el = document.createElement(tag);
      el.value = `typed in ${tag}`;
      const event = new Event('input');
      el.dispatchEvent(event);
      expect(eventValue(event)).toBe(`typed in ${tag}`);
    }
    const select = document.createElement('select');
    select.append(new Option('A', 'a'), new Option('B', 'b', true, true));
    const change = new Event('change');
    select.dispatchEvent(change);
    expect(eventValue(change)).toBe('b');
  });
});
