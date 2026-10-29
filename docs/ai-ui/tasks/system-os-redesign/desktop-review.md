# Desktop PR249 — initial independent source review

Independent SOL, 2026-10-30 Asia/Saigon. Worktree `D:/Projects/HRM/worktrees/browser-os-desktop`, branch `feat/browser-os-desktop`, base `899d4dab4e4ebd9735d785142a0b0fa47b0ac76a`. Local/GitHub draft head verified `3380a55614ca1777fc7a15d3f0c9ae94a36b9542`. Reviewed complete committed base delta, plus dirty desktop.page.scss/worklog fixes during active author CI repair. No untracked product files present at initial status. Actual GitHub diff fetched read-only to `D:/Projects/HRM/tmp/desktop-pr249-review.diff`. Own report only; app source read-only, other writer preserved.

## Findings

1. **P2 — mobile size selection does not change widget dimensions.** `frontend/src/app/features/desktop/desktop.page.scss`, mobile rule: every s/m/l uses grid-column1, with no size-specific height/min-height/content rule elsewhere. Size therefore only changes width spans on desktop; the same mobile widget's S/M/L bounds remain identical. Task explicitly requires a full-width mobile column with actual distinct heights. Add size-aware minimums with auto content growth, preserve responsive width and grid non-overlap. Verify actual bounds S<M<L on the same widget at375/390px, after direct resize/reorder/reload; class-name assertions are insufficient.
2. **P2 — widget control accessible names interpolate translation keys.** `frontend/src/app/features/desktop/desktop.page.html` passes widgetTitle(widget.id) as title into drag/move/size/remove translation parameters; widgetTitle in desktop.page.ts returns a key such as desktop.widgets.approvals.title, not its translated value. Transloco interpolation does not recursively translate that parameter. Resulting accessible names contain the technical key instead of “Мої погодження”; new E2E locators expecting “Прибрати віджет «Мої погодження»” / localized size/move names will not match. Translate the widget title once for these control labels while preserving runtime language reactivity. Add actual rendered UK/RU/EN accessible-name assertions and keep existing E2E expectations intact.

No P1 found in this bounded source review. Both findings require correction before acceptance; actual CI/browser proof remains pending.

## Source/lifecycle and permission assessment

DesktopDataStore reuses real OverviewService GET /api/dashboard through normal authenticated HTTP, not prototype data or invented counts. Requests are finite; refresh is explicit; cleanup unsubscribes the previous request. Effect tracks auth.user and clears prior data before loading on account/roles/modules changes; stale response context checks and unsubscribe prevent retaining an old request result. Layout is per-account cosmetic `{version,widgets:[{id,size}]}`, with exact-key validation, unique allowed IDs, size enums, max12 and blocked-storage fallback. Empty layout persists. Registry currently has only four unique widget types, so max12 is schema ceiling, not twelve duplicate instances; docs state this accurately.

Launcher visibility checks current effective roles/module lists and inspected privileged admin/HR/superadmin destinations match shell/route policies. Original route/server guards remain authoritative; desktop is a new authenticated core leaf, not a public bypass. Primary root overview is unchanged. Widget summary/approvals visibility respects relevant modules; actual payload aggregate fields are displayed, missing states differ from numeric zero. No API payload/name/form enters localStorage. Keeping aggregate dashboard in component memory is not proof of production privacy/security certification.

CSS grid flow avoids absolute-coordinate overlap/below-fold shrink refusal and preserves scroll extent; visible reorder maps only visible widget slots while retaining hidden layout entries. CDK drag and explicit keyboard controls exist. Browser focus retention, narrow header control clipping and actual drag final placement still require compiled renders. Note desktop header controls have significant fixed width; verify S cards at intermediate widths rather than assuming the grid proves controls fit.

## Active repair and evidence boundaries

Author was changing SCSS and worklog while this review ran. An intermediate dangling selector before @media was observed and then removed during that repair; do not treat a moving intermediate file as final shipping source. Latest tail at review time has fast opaque overrides after mobile blur, but reduced-motion/fast transition suppression removed by the repair must be checked at final freeze (drag transitions currently use fixed150ms). Require final syntax/build and rendered fast/reduced behavior before source acceptance; this report does not bless the moving CSS.

Read targeted layout/model/component tests and new E2E flow. Current tests exercise storage, operations/classes, permissions and data states, but do not prove actual S/M/L bounds or resolved accessible names. Author reports20 focused tests/lint/E2E types PASS, not independently rerun. Existing parity assertions/axe thresholds remain intact; missing baseline still fails with actual inventory/screens attached. New E2E checks drag/reorder/persistence and launch real candidates route; no snapshot/axe blessing found.

Actual screenshots are blocked by build CI, so **no rendered visual PASS** is claimed. Pending: bounded fixes/final source freeze and review; CI install/build/test/docs/worklog/security; actual desktop/mobile4theme matrix, accessibility/focus/controls, drag/resize/reload and fast/fallback inspection; intentional new desktop inventory acceptance from CI artifacts. Foundation/windows separate branches must be combined and reviewed before whole-system completion. This leaf is not root OS integration or true-window launcher wiring yet; its DI launcher seam is deliberate future integration.

## Reviewed SHA256

- desktop.page.ts `ea080625ce77d28c5a0695152f04561de03415705b407168d25ea27ec6a1f650`
- desktop.page.html `0c6c3477ffa39b9da0d0f45ecf227591e9bd89222f12765150e1e5b5dfa45455`
- dirty desktop.page.scss `289a9d925779740264cb126d00ed7b7b0222e6b94907d2af8981770953eb78ce`
- desktop.model.ts `561b3501c7ffe33932d2ef32c9308d4b3fbda9b975508e9b248ff23fc8e70bef`
- desktop-data.store.ts `0239c1df307b4dd8ca51d3263b2984f29c70ebfe99a25a3100d4663a54cb308d`

**Verdict: CHANGES REQUIRED (two P2), plus final active CSS repair/CI/visual gates.** No product edits, full suite/server/build, GitHub write/approval, merge or deploy by reviewer.

## Frozen correction source review — source PASS, runtime/CI pending

Reviewed exact dirty correction over3380a556: mobile sizes now set minimum content heights7.5/9.5/11.5rem with auto growth/full width; translated widgetTitle resolves actual localized text and h3 no longer translates that resolved value again. Added rendered drag-label/title unit assertions and a real mobile flow measuring S/M/L bounds (rather than class-only proof). Both original P2 findings are closed in source. Existing E2E expectations remain unchanged. The mobile flow is in the existing mobile describe, so its hook does not skip the intended project.

Independent bounded verification: Sass compile with compressed style succeeded,7591bytes; git diff --check passed. This is not Angular component CSS budget proof: Angular encapsulation/optimization can change emitted size, so real build CI must still pass. No app server/full build/runtime suite executed. SCSS refactor shares material variables, final fast surface overrides remain after mobile rules, fixed drag interpolation transitions were removed rather than bypassing reduced-motion. Worklog link corrected using the existing docs-build-relative format. Remaining decorative desktop glow/drag shadow should be checked in the final fast-mode render; do not infer production performance from source.

Correction SHA256: desktop.page.scss `e93cc8c7de535f60a0b2b12426263bb1b5c19d2e82bac6a54850c475dd5cd30d`; desktop.page.html `0ecc7b9a5ea7a7c3888e5a73d56b7a0b6fe1cf7f8795da8dbf76aa71c389d0eb`. TS changes limited to injecting TranslocoService and resolving title; other original reviewed business/data files unchanged.

**SOURCE PASS for the bounded fixes.** No new confirmed blocker within the correction. Publish/rerun CI evidence is still required for Angular budget, actual translated control labels, size/layout/focus/drag and fresh compiled visual review. This does not supersede those pending gates or claim whole-system completion/merge readiness.
