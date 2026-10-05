// Layout checks: no page-level horizontal scroll, nothing clipped outside its scrollable ancestor.
import type { Page } from '@playwright/test';

export interface LayoutReport {
  pageOverflow: number;
  clipped: string[];
  diagnostics: {
    viewportWidth: number;
    documentClientWidth: number;
    documentScrollWidth: number;
    tableAncestors: Array<{
      element: string;
      left: number;
      right: number;
      width: number;
      scrollWidth: number;
      clientWidth: number;
      cssWidth: string;
      minWidth: string;
      overflowX: string;
      display: string;
      position: string;
      scrollWidth: number;
      clientWidth: number;
      before: {
        contentPresent: boolean;
        width: string;
        left: string;
        right: string;
        visibility: string;
        overflowX: string;
        position: string;
      };
      after: {
        contentPresent: boolean;
        width: string;
        left: string;
        right: string;
        visibility: string;
        overflowX: string;
        position: string;
      };
      descendants: Array<{
        element: string;
        left: number;
        right: number;
        width: number;
        visibility: string;
        overflowX: string;
        position: string;
      }>;
    }>;
    documentOverflowRoots: Array<{
      element: string;
      left: number;
      right: number;
      width: number;
      cssWidth: string;
      minWidth: string;
      overflowX: string;
      display: string;
      position: string;
    }>;
  };
}

/**
 * pageOverflow — how many px the document is wider than the viewport (0 = fine).
 * clipped — interactive elements and headings that stick out of an `overflow: hidden/clip` ancestor (or out of the
 * viewport) without a scroll container in between: the user cannot see or reach them. Described as
 * `tag.class "name"` for the allow-list (e2e/layout-allowlist.json).
 */
export async function layoutOf(page: Page): Promise<LayoutReport> {
  return page.evaluate(() => {
    const doc = document.scrollingElement ?? document.documentElement;
    const pageOverflow = Math.max(0, doc.scrollWidth - doc.clientWidth);
    const TOL = 2;
    const sel = 'button, a[href], input:not([type=hidden]), select, textarea, [role=button], [role=link], [role=tab], [role=switch], [role=checkbox], [role=menuitem], [role=option], h1, h2, h3, th';
    const clipped: string[] = [];
    const describe = (el: Element): string => {
      const cls = typeof el.className === 'string' && el.className.trim() ? '.' + el.className.trim().split(/\s+/)[0] : '';
      const name = (el.getAttribute('aria-label') ?? (el as HTMLElement).innerText ?? '').replace(/\s+/g, ' ').trim().slice(0, 60);
      return `${el.tagName.toLowerCase()}${cls} "${name}"`;
    };
    for (const el of Array.from(document.querySelectorAll(sel))) {
      const r = el.getBoundingClientRect();
      if (r.width <= 1 || r.height <= 1) continue; // visually-hidden helpers
      const style = getComputedStyle(el);
      if (style.visibility === 'hidden' || el.closest('[inert], [aria-hidden=true]')) continue;
      let out = false;
      let fixed = style.position === 'fixed';
      for (let a = el.parentElement; a && a !== document.body && !out; a = a.parentElement) {
        const s = getComputedStyle(a);
        if (s.position === 'fixed') fixed = true;
        const scrolls = (v: string) => v === 'auto' || v === 'scroll';
        const clips = (v: string) => v === 'hidden' || v === 'clip';
        // Material tab headers page their labels with arrow buttons: reachable, not clipped.
        if (a.matches('.mat-mdc-tab-label-container, .mat-mdc-tab-link-container')) break;
        const ar = a.getBoundingClientRect();
        if (clips(s.overflowX) && (r.left < ar.left - TOL || r.right > ar.right + TOL)) out = true;
        if (clips(s.overflowY) && (r.top < ar.top - TOL || r.bottom > ar.bottom + TOL)) out = true;
        if (scrolls(s.overflowX) || scrolls(s.overflowY)) break; // reachable by scrolling that container
      }
      // Out of the viewport sideways with no page scroll to reach it (vertical overflow scrolls the page).
      if (!out && (r.right > window.innerWidth + TOL || r.left < -TOL) && (fixed || pageOverflow === 0)) {
        let scroller = false;
        for (let a = el.parentElement; a && a !== document.body; a = a.parentElement) {
          const s = getComputedStyle(a);
          if (s.overflowX === 'auto' || s.overflowX === 'scroll' || a.matches('.mat-mdc-tab-label-container, .mat-mdc-tab-link-container')) scroller = true;
        }
        if (!scroller) out = true;
      }
      if (out) clipped.push(describe(el));
    }
    // The table can be wider than the viewport inside an intentional scroller; measure its ancestors to find
    // which box (if any) allows that width to escape into the document.
    const table = document.querySelector('table.mat-mdc-table');
    const tableAncestors: LayoutReport['diagnostics']['tableAncestors'] = [];
    for (let el: Element | null = table; el; el = el.parentElement) {
      const rect = el.getBoundingClientRect();
      const style = getComputedStyle(el);
      const classes = typeof el.className === 'string' ? el.className.trim().split(/\s+/).filter(Boolean).slice(0, 3) : [];
      tableAncestors.push({
        element: `${el.tagName.toLowerCase()}${classes.map((name) => `.${name}`).join('')}`,
        left: Math.round(rect.left * 10) / 10,
        right: Math.round(rect.right * 10) / 10,
        width: Math.round(rect.width * 10) / 10,
        scrollWidth: el.scrollWidth,
        clientWidth: el.clientWidth,
        cssWidth: style.width,
        minWidth: style.minWidth,
        overflowX: style.overflowX,
        display: style.display,
        position: style.position,
      });
    }
    const documentOverflowRoots = Array.from(document.body.querySelectorAll('*'))
      .flatMap((el) => {
        const rect = el.getBoundingClientRect();
        if (rect.right <= window.innerWidth + TOL && rect.left >= -TOL) return [];
        let contained = false;
        for (let parent = el.parentElement; parent && parent !== document.body; parent = parent.parentElement) {
          const parentRect = parent.getBoundingClientRect();
          if (parentRect.right > window.innerWidth + TOL || parentRect.left < -TOL) {
            contained = true; // Report the outermost escaping element, not all its descendants.
            break;
          }
          const overflowX = getComputedStyle(parent).overflowX;
          if (overflowX === 'auto' || overflowX === 'scroll' || overflowX === 'hidden' || overflowX === 'clip') {
            contained = true; // A scroll/clipping ancestor keeps this element from widening the document.
            break;
          }
        }
        if (contained) return [];
        const style = getComputedStyle(el);
        const classes = typeof el.className === 'string' ? el.className.trim().split(/\s+/).filter(Boolean).slice(0, 3) : [];
        const pseudo = (which: '::before' | '::after') => {
          const pseudoStyle = getComputedStyle(el, which);
          return {
            contentPresent: pseudoStyle.content !== 'none' && pseudoStyle.content !== 'normal',
            width: pseudoStyle.width,
            left: pseudoStyle.left,
            right: pseudoStyle.right,
            visibility: pseudoStyle.visibility,
            overflowX: pseudoStyle.overflowX,
            position: pseudoStyle.position,
          };
        };
        const descendants = Array.from(el.querySelectorAll('*'))
          .map((descendant) => {
            const descendantRect = descendant.getBoundingClientRect();
            if (descendantRect.right <= window.innerWidth + TOL && descendantRect.left >= -TOL) return null;
            const descendantStyle = getComputedStyle(descendant);
            const descendantClasses = typeof descendant.className === 'string'
              ? descendant.className.trim().split(/\s+/).filter(Boolean).slice(0, 3)
              : [];
            return {
              element: `${descendant.tagName.toLowerCase()}${descendantClasses.map((name) => `.${name}`).join('')}`,
              left: Math.round(descendantRect.left * 10) / 10,
              right: Math.round(descendantRect.right * 10) / 10,
              width: Math.round(descendantRect.width * 10) / 10,
              visibility: descendantStyle.visibility,
              overflowX: descendantStyle.overflowX,
              position: descendantStyle.position,
            };
          })
          .filter((item): item is NonNullable<typeof item> => item !== null)
          .sort((a, b) => b.right - a.right)
          .slice(0, 10);
        return [{
          element: `${el.tagName.toLowerCase()}${classes.map((name) => `.${name}`).join('')}`,
          left: Math.round(rect.left * 10) / 10,
          right: Math.round(rect.right * 10) / 10,
          width: Math.round(rect.width * 10) / 10,
          cssWidth: style.width,
          minWidth: style.minWidth,
          overflowX: style.overflowX,
          display: style.display,
          position: style.position,
          scrollWidth: el.scrollWidth,
          clientWidth: el.clientWidth,
          before: pseudo('::before'),
          after: pseudo('::after'),
          descendants,
        }];
      })
      .slice(0, 30);
    return {
      pageOverflow,
      clipped: [...new Set(clipped)].sort(),
      diagnostics: {
        viewportWidth: window.innerWidth,
        documentClientWidth: doc.clientWidth,
        documentScrollWidth: doc.scrollWidth,
        tableAncestors,
        documentOverflowRoots,
      },
    };
  });
}
