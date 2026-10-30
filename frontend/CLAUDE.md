# Read this first

This repository uses a **DOX** documentation framework rooted in [AGENTS.md](AGENTS.md):
a hierarchy of `AGENTS.md` files that are binding work contracts for their subtrees.

**Before making any change:** read the root [AGENTS.md](AGENTS.md), then follow its "Read Before Editing" section —
walk from the repo root to every path you expect to touch and read each `AGENTS.md` along the way
(root → `src/app/`; root → `server/`; root → `scripts/`; root → `e2e/`; root → `docs/`).
The nearest `AGENTS.md` is the local contract; parent docs carry repo-wide rules (stack, on-site server, API proxy, i18n, UI parity).

**After making any change:** follow `AGENTS.md`'s "Update After Editing" and "Closeout" sections — update the nearest
owning `AGENTS.md` (and any affected parent/child index) before the task is done.

Do not rely on memory of these docs — re-read the applicable DOX chain in the current session before editing.
