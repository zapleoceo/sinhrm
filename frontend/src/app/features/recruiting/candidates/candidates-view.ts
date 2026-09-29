/** "Список | Дошка" on /candidates and the last vacancy of the board, remembered per browser. */
export type CandidatesView = 'list' | 'board';

export interface CandidatesViewPref {
  view: CandidatesView;
  vacancyId: number | null;
}

export const CANDIDATES_VIEW_KEY = 'sinhrm.candidates.view';

/** Storage may be missing or throw (private mode, blocked site data): fall back to the list. */
export function readViewPref(): CandidatesViewPref {
  try {
    const raw = JSON.parse(localStorage.getItem(CANDIDATES_VIEW_KEY) ?? 'null') as Partial<CandidatesViewPref> | null;
    return {
      view: raw?.view === 'board' ? 'board' : 'list',
      vacancyId: typeof raw?.vacancyId === 'number' ? raw.vacancyId : null,
    };
  } catch {
    return { view: 'list', vacancyId: null };
  }
}

export function saveViewPref(pref: CandidatesViewPref): void {
  try {
    localStorage.setItem(CANDIDATES_VIEW_KEY, JSON.stringify(pref));
  } catch {
    // Not remembered: the page still works.
  }
}
