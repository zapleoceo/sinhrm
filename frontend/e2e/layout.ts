// Layout checks: no page-level horizontal scroll, nothing clipped outside its scrollable ancestor.
import type { Page } from '@playwright/test';

export interface LayoutReport {
  pageOverflow: number;
  clipped: string[];
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
    return { pageOverflow, clipped: [...new Set(clipped)].sort() };
  });
}
