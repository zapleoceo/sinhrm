import { canManageScripts } from './scripts.access';
import { PREVIEW_VALUES, newId, renderTemplate, scoreBand, splitList, unknownTokens } from './scripts.model';

describe('scripts model helpers', () => {
  it('renders templates like the backend: missing values keep the token', () => {
    expect(renderTemplate("Вітаю, {Ім'я}! {Адреса} {json}", { "Ім'я": ' Олена ', Адреса: '' })).toBe('Вітаю, Олена! {Адреса} {json}');
    expect(renderTemplate('{Рекрутер}', PREVIEW_VALUES)).toBe('Ірина');
  });

  it('finds unknown tokens once', () => {
    expect(unknownTokens("{Імя} {Ім'я} {Імя} {Name}")).toEqual(['Імя', 'Name']);
    expect(unknownTokens('no tokens')).toEqual([]);
  });

  it('makes unique ids and splits comma lists', () => {
    expect(newId('s', [])).toBe('s1');
    expect(newId('s', [{ id: 's1' }, { id: 's2' }])).toBe('s3');
    expect(newId('s', [{ id: 's2' }])).toBe('s3');
    expect(splitList(' a, b ,, c ')).toEqual(['a', 'b', 'c']);
  });

  it('bands scores and checks manage rights', () => {
    expect([scoreBand(90), scoreBand(60), scoreBand(10)]).toEqual(['good', 'mid', 'low']);
    expect(canManageScripts(['admin'])).toBe(true);
    expect(canManageScripts(['recruiter', 'viewer'])).toBe(false);
  });
});
