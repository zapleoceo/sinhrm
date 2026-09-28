/** A piece of an assistant answer: plain text, a line break or an in-app link. Model output never becomes HTML. */
export type TextSegment =
  | { kind: 'text'; text: string }
  | { kind: 'break' }
  | { kind: 'link'; text: string; path: string };

/** SPA paths like /candidates/12 or /timeoff/calendar (at least one letter segment, optional query). */
const SPA_PATH = /(^|[\s(«"'])(\/[a-z][a-z0-9-]*(?:\/[a-z0-9-]+)*(?:\?[a-z0-9=&%_-]+)?)/gi;

/** Splits an answer into lines and SPA-path links (trailing punctuation stays text). */
export function toSegments(text: string): TextSegment[] {
  const out: TextSegment[] = [];
  text.split(/\r?\n/).forEach((line, i) => {
    if (i > 0) {
      out.push({ kind: 'break' });
    }
    let last = 0;
    for (const match of line.matchAll(SPA_PATH)) {
      const start = (match.index ?? 0) + match[1].length;
      const path = match[2];
      if (start > last) {
        out.push({ kind: 'text', text: line.slice(last, start) });
      }
      out.push({ kind: 'link', text: path, path });
      last = start + path.length;
    }
    if (last < line.length) {
      out.push({ kind: 'text', text: line.slice(last) });
    }
  });
  return out;
}

/** Router-friendly parts of a link path: route + query params. */
export function splitLink(path: string): { route: string; query: Record<string, string> } {
  const [route, search = ''] = path.split('?');
  const query: Record<string, string> = {};
  for (const [k, v] of new URLSearchParams(search)) {
    query[k] = v;
  }
  return { route, query };
}
