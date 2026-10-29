# Foundation independent local review — changes required / CI pending

Independent SOL, 2026-10-30 Asia/Saigon. Worktree `D:/Projects/HRM/worktrees/browser-os-system`, branch `feat/browser-os-foundation`, base `899d4dab4e4ebd9735d785142a0b0fa47b0ac76a`. Read complete tracked base delta and relevant untracked appearance page/tests, ThemeService tests and docs (new source inspected using git diff --no-index against NUL). App source read-only; sole report ownership. Another author was finishing styles during this review, so hashes below identify the examined state; freeze/recheck is required.

## Batched findings

1. **P2 — mobile fast mode re-enables backdrop blur.** `frontend/src/app/features/shell/shell.layout.scss:430`: later mobile glass selectors set blur10px and have equal specificity to the earlier fast selectors at51–58. With glass+fast at <=767.98px, later mobile rules win for ordinary sidebar/topbar. Background is opaque, but filtering still runs, contrary to fast-mode contract. Exclude fast from mobile glass selectors or place final fast overrides after all media rules. Verify actual computed backdrop-filter on both surfaces at390px and desktop for glass+fast, including auto-hide; unsupported fallback must remain solid.
2. **P2 — fast mode leaves fixed-duration shell transitions enabled.** `shell.layout.scss:10,201,212,279,409`: fast root tokens only disable shadows/wallpaper. Rail width/grid, group expansion, chevron and mobile drawer use fixed durations and have no fast transition override. The implementation plan promises fast opaque/no blur/animation, and current reduced-motion override is a separate preference. Add scoped fast-mode shell transition suppression, preserving functional state/focus; verify computed durations of moving elements in fast alone (reducedMotion=false). No blanket transition rule that reintroduces the known overlay visibility regression is requested.
3. **P2 — inverse action contrast regresses.** `frontend/src/styles.scss:131`: inverse-primary changed from the opposite light/dark primary to var(--os-primary), while inverse-surface remains on-surface (dark ink in light mode, light ink in dark mode). Snackbar action uses inverse-primary, so the normal dark blue sits on dark ink in light mode and pale blue sits on pale ink in dark mode. The old checker explicitly covered snackbar inverse-primary/ink; the new checker removes that pair. Restore an accent/scheme-appropriate inverse-primary token with adequate contrast, and retain the inverse action/text checks. Validate snackbar action in rendered light/dark/reference palettes, not only page links.
During the active author write, the contrast script initially produced unresolved-alias NaN failures. A final independent rerun after the author alias resolver landed exits0 with real stage ratios across the nine configurations; that transient finding is resolved. Keep the missing inverse/snackbar and other pre-existing useful contrast pairs covered while expanding the matrix.

No P1 security/authorization issue found in this bounded foundation change. Findings1–3 are source/cascade contract defects; this report does not claim a compiled browser reproduction before CI artifacts exist.

## Reviewed behavior and boundaries

ThemeService exposes a typed allowlisted cosmetic schema, clamps/rounds finite transparency, rejects extra persisted keys, handles malformed/blocked storage via safeStorage, retains legacy theme/toggle/init APIs, and applies effective reference dark theme. Media listeners register once and DestroyRef removes them; no new polling/network payloads. Appearance form uses typed nonnullable controls, OnPush and cleanup. Its author-added effect synchronizes external preference changes without emitting recursively; reset returns defaults. No PII/page/form payload enters preferences. Settings are browser-wide, not server/account synchronization; docs say this accurately.

Appearance route sits under existing authenticated/module-guarded shell, as a core setting accessible to all signed-in roles. Original guard, route, form canDeactivate and page behavior stay intact; new menu entry adds rather than replaces controls. UK/RU/EN appearance key sets and visible labels match. Shared tokens flow from html into real lazy pages and root CDK overlays; data surfaces/forms/dialogs stay dense while only shell chrome gets blur. System fonts replace external Google styles; Material Symbols now use the existing bundled package font. Bundled font existence/build URL/loading and layout metrics still need compiled CI/browser verification. No actual performance/FPS improvement is established by source alone.

E2E changes retain existing inventory equality, missing-baseline, errors, layout and axe assertions. Actual inventory JSON is attached before missing/mismatch assertions; screenshot capture moved before the assertion so new appearance baseline failure leaves evidence. This does not bless new baselines or axe counts. New appearance flow verifies controls/root contract and reload, but does not itself prove computed fast/fallback/contrast behavior. Existing UPDATE mode still writes axe baselines and must not be used blindly. Review observed inventory and PNGs before promoting only intentional appearance/menu changes.

Read docs cover foundation scope and explicitly leave real desktop/widgets/multiwindow to later rounds. This is shared preferences/look round1, not the whole OS implementation. No private Router APIs or real-window architecture are introduced here. Required documentation/worklog is present; check all docs links at final commit.

## Evidence and pending gates

Independent execution: contrast token checker only (initial mid-write NaN, final rerun exit0 after alias resolution); no app server, full build, browser suite or DB. Author/root reports:9 targeted preference/page tests PASS,38 route/shell tests PASS, E2E parser5/lint/tsc PASS. These are attributed reports, not independent runtime reruns. Static regex style-contract tests are not runtime/visual evidence and should not be counted as such.

Pending: freeze and correct findings; final targeted tests/lint/contrast with recorded outputs; draft PR final GitHub SHA independent diff review; required CI build/test/security/docs/worklog gates; ui-parity/appearance flows with actual compiled app artifacts; intentional inventory review; actual desktop/mobile light/dark/reference/solid/glass/fast/reduced-motion renders and overlays/controls/contrast/fallback inspection. No formal visual PASS until actual images are opened. Native unsupported-browser fallback remains unverified. No merge/deploy or whole-system completion implied.

## Reviewed source hashes (SHA256)

- styles.scss: `71f748aec79cad9942c9794a2eda0cd3fb204db28efa20a47ccf9915bd5a12fb`
- shell.layout.scss: `047a80bbdb925d4c806dce700fe351df53c28d0e0b34b6301b0f0e185ecf0005`
- theme.service.ts: `b9a6dd4ac444ab6e4c25b972ac225b04db8d4eaa9596f5ae966cb3923b75a5bf`
- appearance.page.ts: `7b5613a637faeabdf941434dbe28de7477cfb6166551bd1883d32b22fc419235`
- contrast-check.mjs: `c63d6b88b1dbd3afba01e45c4fa633f7a4f9ff48f8c1636a4c96a8b3245d0588`

Verdict: **CHANGES REQUIRED for local preflight; final CI/GitHub/render approval pending.** No app source edits, commit, push, merge or deploy by reviewer.


## Exact draft PR correction review — b92a4bc (2026-10-30)

Verified local HEAD and GitHub PR247 head are both `b92a4bc048961303c6c907401f9526856fc48384`, base `899d4dab4e4ebd9735d785142a0b0fa47b0ac76a`; PR is draft. Local tracked source was clean before this report update. Reviewed committed base→HEAD correction and fetched the actual GitHub diff using gh pr diff247 (read-only); saved full diff `D:/Projects/HRM/tmp/foundation-pr247-review.diff`. No GitHub write/formal approval.

Initial findings1–3 are **closed in source**: final fast surface overrides follow mobile glass; scoped fast transitions now cover shell/nav/group/chevron/rail/sidebar; inverse-primary uses its own opposite-surface token. Reference primary is exactly #9b85ff, with reference inverse #62509f. Numerical inverse-primary/inverse-surface >=4.5 check is retained. Independently executed final numerical checker: exit0,261 PASS/0 FAIL across9 configurations. Log `D:/Projects/HRM/tmp/foundation-contrast-review.log`. No mirrored style regex test is treated as runtime evidence. The CI addition is one numerical checker invocation before frontend lint. Shell module guide retains all baseline content and only appends the foundation section; historical architecture document is preserved with an explicit current-direction addendum.

**New P2 — mobile fast regression is skipped in every project.** `frontend/e2e/flows.pw.ts:126`: the mobile-only fast test was inserted in describe('desktop flows') at72. That describe's existing beforeEach at196 unconditionally skips every project except desktop-light; Playwright hooks apply to all tests in their describe regardless of declaration order. Thus mobile-light is skipped by the hook, while desktop-light is skipped by the new test's own mobile-only condition. Move the test to the existing mobile describe or a standalone mobile describe; preserve the original desktop-only hook and every prior assertion. Verify the CI report shows this mobile test executed, not skipped. This is a regression-proof gap rather than a claim that corrected CSS still fails.

Final correction hashes reviewed:

- styles.scss: `867bbd2489c60953f150b5825e08e3ffb1d6ddbfa12e3706fe20926eec45c837`
- shell.layout.scss: `ff82b183ee5080ddc757b6e282c505818c6d3264557576442d1676f7d4fa91ce`
- contrast-check.mjs: `19316d0cb9ddd04891ea691ec702a5ced35fdcdd19469153d26b29cbb182b609`

**Verdict: source corrections accepted; CHANGES REQUIRED for the skipped mobile test.** CI40919766554 and actual compiled render/artifact inspection remain pending. No visual PASS, merge readiness, deployed behavior or whole-OS completion is claimed. Recheck the bounded test relocation on its final PR SHA, then inspect actual CI execution and PNGs.

## Actual foundation CI image inspection and d8e1d298 readiness review

Independent SOL actually opened CI40921259802 compiled-app PNGs in `D:/Projects/HRM/tmp/browser-os-system/ci-40921259802/screens`: desktop-light/appearance.png, desktop-dark/appearance.png, mobile-light/appearance.png, mobile-dark/appearance.png, desktop-light/vacancies.png, desktop-dark/users.png, mobile-light/users.png, desktop-light/overview--user-menu.png. These are sourcefe226e002a06d80b3175ddaa1e9f2b9b8e4de704 artifacts, not d8e1d298 screenshots or live production.

Appearance shows coherent dense panels, legible labels/switches/slider and responsive stacking. All requested controls are present; mobile accent row has horizontal overflow/partial last option within its intended scrollable group. Users table is wider than mobile viewport within its existing horizontal table scroll; the screenshot alone is not proof its right-side controls are unreachable. Vacancy list retains search/status/new/edit actions and compact rows. Real menu includes Appearance alongside existing language/role/theme/profile/extension/assistant/logout. Material icons render as symbols, not ligature text. No new definite P1/P2 visual regression established in these inspected states. Dark status/role captions are visually subdued; no full rendered contrast certification is claimed from screenshots.

One **observed unresolved timing/readiness symptom** is blank scheme/wallpaper select value text in desktop-light appearance PNG, while the other three appearance variants show “Власна”/“Туман”. Do not call that proven permanent product corruption or claim it fixed merely from another state. Reviewed exact d8e1d298e0afe82a4d8e69f13bb46a4d774a5781 delta: adds strict rendered value-text assertions before capture and in the preference flow; correctly accepts unsupported empty prefixed backdrop-filter property while still rejecting real non-none standard/nonempty prefixed filters. This is a legitimate test correction, not weakened filter enforcement. New CI must execute these waits successfully and provide fresh screenshot evidence. If it times out, investigate the real select/CVA lifecycle instead of extending delays/blessing blanks.

Reviewed new appearance desktop/mobile inventory snapshots and menu-only additions as captured UI inventories; source delta retains existing assertions and changes no axe baseline/allowlist. These baselines establish control inventory, not subjective design fidelity or full system coverage. Latest d8 readiness behavior and new-page axe/layout completion remain pending CI; older failed baseline run cannot prove those subsequent gates.

**Foundation source corrections remain accepted, inspected historical rendered states have no additional confirmed blocker, but final CI/readiness/visual completion is still pending.** No merge-ready/whole-OS-complete/deployment claim. Windows pilot review belongs to its separate worktree/report.

## Persistent select product correction — bounded source PASS

Reviewed full base-to-current foundation scope, published PR247 diff at d8e1d298e0af and dirty AppearancePage/spec/docs correction. CI40924284532 demonstrated that the blank select label survived7seconds: the earlier timing-only hypothesis is superseded by a confirmed product/CVA synchronization defect. No claim that old screenshots showed a harmless transient is retained.

Product fix keeps the typed form initialized from current preferences and compares each control before patching only changed values, with emitEvent:false. It avoids the redundant initial/all-control write that can reset selected Material option rendering; actual external theme/scheme changes still update the appropriate controls without feedback. New DOM regression asserts both Material select value texts after initialization and again after unrelated fast-mode update. Independently ran only appearance.page.spec.ts:3tests PASS, exit0. Existing CI readiness assertions/timeouts are unchanged (no longer wait or weaker matcher). Docs correctly explain differential synchronization.

**Bounded SOURCE PASS for this product correction.** Root may publish rerun and port into integration. Fresh actual compiled CI must show both labels and preference flows passing; targeted test success does not prove that browser/CVA defect is closed in shipped output. Previous source/gitleaks/security/visual gates and whole-system limits remain.

## Native select correction review — product accepted, parity adapter correction required

Reviewed exact PR247 head e7878366713e plus dirty AppearancePage/spec/flows/docs/new worklog and full published GitHub diff (saved under HRM tmp). Parsed actual CI40928365667 report: unexpected preference flow desktop-light and appearance parity mobile-light/mobile-dark confirm that prior selective-sync source PASS did **not** close persistent browser blank labels. Earlier proposed fix is superseded by this observed runtime failure.

Bounded product replacement is reasonable: only scheme/wallpaper become select matNativeControl with stable native option values and translated labels, using MatInputModule/Material form-field styling. Typed controls/options, selective external synchronization and preference authority stay intact. Flow selectOption checks actual selected values and checked translated labels; it does not extend readiness timeouts or replace rendered expectations with storage-only assertions. Deferred UK/RU loader regression exercises native selected values before translation arrives, after unrelated preference changes and locale switching. Independently ran appearance.page.spec.ts:4tests PASS, exit0.

**P2 test migration omission:** parity.pw.ts91–92 still searches `.mat-mdc-select-value-text` inside the now-native selects. That Material-only descendant can never exist, so every appearance parity run will timeout even when the product is correct. Convert these two parity readiness checks to native toHaveValue plus option:checked translated text, matching the flow and retaining existing timeouts. Do not delete the readiness assertions or mark the tests skipped.

**Product source accepted; overall bounded source gate CHANGES REQUIRED until parity adapter is corrected.** Fresh compiled browser proof still pending; no claim that native selects have already passed real CI. No app server/full build/full suite/product edits/Git write by reviewer.

### Native parity readiness adapter — SOURCE PASS
Verified final parity-only correction: both native selected values and translated checked-option labels are asserted for scheme/wallpaper, matching the rendered flow. Existing timeouts/readiness enforcement unchanged. The prior P2 Material-descendant locator omission is closed. Product source remains as independently tested4tests PASS above. Root may publish CI rerun; actual compiled browser proof remains pending.
