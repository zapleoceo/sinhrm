# HRM-16 implementation plan

1. Add a typed, bounded workspace snapshot codec/store keyed by authenticated user in `sessionStorage`; validate at read time and clear on user/security-scope change.
2. Integrate Router `browserUrl` and `NavigationExtras.state` while retaining the internal named-outlet tree. Restore route IDs/slots/controls/query only via `navigateByUrl` so guards execute. Canonical clean links without valid same-tab state use existing primary routes.
3. Persist window geometry in a small validated per-user store; commit writes on pointer end and keyboard/reset actions, not during pointermove.
4. Add focused tests for router address/history behavior, reload and popstate, access rejection, legacy URLs, storage bounds, and geometry validation/persistence.
5. Update desktop module docs and worklog. Do not modify CSS or authentication/backend code.

## Risks to prove first

Angular `browserUrl` changes the address-bar URL but does not itself reconstruct an internal named-outlet tree on reload. The first focused test must establish that navigation state and popstate can be restored with ordinary guarded Router navigation. If that lifecycle cannot be shown, pause before implementing a custom history workaround.
