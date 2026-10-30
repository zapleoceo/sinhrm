# docs

## Purpose
Markdown source of the in-app help: `modules/` (plain-language sections are published), `guides/` (admin-only), `worklog.d/` fragments + `worklog.md` history (journal page).

## Local Contracts
- Copied from the monorepo at the split; the backend repo keeps its own docs. A module change updates `modules/<module>.md`; each change adds a `worklog.d/` fragment.
- Raw HTML is escaped and links are limited by `scripts/build-docs.mjs`; do not rely on richer Markdown.

## Verification
`npm run test:docs`; `npm run docs` must report the page count without warnings.
