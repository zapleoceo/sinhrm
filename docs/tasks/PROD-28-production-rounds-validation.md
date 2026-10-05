# PROD-28 — Joint production-round validation (DO NOT MERGE)

## План
1. Verify exact source PR heads against GitHub, combine those revisions in isolated branch.
2. Resolve conflicts minimally, preserving all source logic/assertions/module docs/worklog fragments.
3. Run lightweight integration guards, then full CI once at the pushed combined head; complete independent combined review.
4. Keep the validation PR draft and labeled DO NOT MERGE; no shipping claim, preview, deploy or main merge.

## Source inventory — exact GitHub heads, verified 2026-10-05
| PR | Exact head | Included |
|---|---|---|
| 138 | 31ffbf6a98fe0aa61000d253f1085875e9983a5a | yes |
| 139 | 2cdb49640100863c79ff52068f63963f3f51e1f3 | yes |
| 140 | 880772c9eba9f704f06db300cbafa02f539d0a18 | yes |
| 141 | 9da8931a3197a79df61b00bf710211bedabff980 | yes |
| 143 | 091f22fd284c880f7a655b915da9c9cf775daad2 | yes |
| 145 | 88dd8b7ac1dac13f99595e2bd15f14465460627a | yes |
| 146 | 8acfadbdad8edeb2a98f4aeb27b2367837c96559 | yes |
| 147 | d12e956df19dd17af058166aeef9553a9bbdd16b | yes, refreshed source |
| 148 | df397ed1c5eda1cf6be958fd345b7a45da9d1d51 | yes |
| 149 | 2cd011ad829f691a04e9dee5b9a1373e96631cf7 | yes |
| 150 | 081d2a16484a19d8c85c1e7a4806b0d5e13d4f94 | yes |
| 151 | 4626e81c5efd9ea6b2ae1237dad9b6185f0afc53 | yes |
| 152 | 4bf3524ce10222bafbd1e44091f9c572ed86d588 | yes |
| 153 | 7b05362519cbad202ac454edebc03fcb48369577 | yes |
| 154 | 00682bda11d080b9e553b02b46069fe344d77ba5 | via squash commit 38d90ea in main |
| 155 | c7e638e8805460ee8bf0cd5f8d0b78256111277d | yes, docs only |

PR142/144 validation branches remain explicitly excluded. PR154 contributes only the rules commit already present in `origin/main` 38d90eaada1c43363cd64c2f16d7ceaecb611dab. PROD-39/PR155 is documentation-only; active PROD-41 is excluded until reviewed.

## Контекст з KB
Atlassian KB/cloudId unavailable in this session; prior Rovo/Drive SinHRM searches had no applicable results.
Current repo/source tasks/UNIFIED-TZ and exact GitHub source heads are authoritative. Query: SinHRM production rounds combined validation.

## Стан
- Step: diagnose failed combined CI and repair only the outdated application-visibility compatibility assertion plus snapshot ordering.
- Worktree / branch / base: `D:/Projects/sinhrm-wt/production-rounds-validation`, `chore/validate-production-rounds`, `origin/main` 38d90eaada1c43363cd64c2f16d7ceaecb611dab.
- PR #156 remains draft and labeled `DO NOT MERGE`: https://github.com/zapleoceo/sinhrm/pull/156. Previous pushed head before repair: d69d7b959fbf1e95217ef6fdf836b9c2af1367d2.
- CI evidence: run 37285204644 failed only one backend assertion in `AssistantReleaseGateTest` (1469 passed, 13302 assertions) and 12 mobile UI inventory comparisons. Backend log: `D:/Projects/_tmp/sinhrm/ci-37285204644-tests.log`; UI log: `D:/Projects/_tmp/sinhrm/ci-37285204644-ui-parity.log`.
- Root cause / repair: the old assistant regression expected a Recruiter who can see the North application to receive a South application on the same candidate. PROD-09 correctly removes that row; the test now asserts North included and South absent in both normal API and assistant projection, and hidden-vacancy filtering returns an empty list. All 12 light/dark inventory diffs were compared: each has the same translated paginator controls, in different list positions. Six mobile snapshot interactive arrays are sorted with the shared `parseInventory()` comparator. Rendered light/dark screenshots were inspected; this snapshot-only correction does not change rendered UI.
- Other CI: frontend, lint, api-docs, extension, docs, worklog, security, and synthetic-restore passed at d69d7b; the synthetic restore run 37285204611 is green. Source CI for PR139/153 and refreshed PR147 is green per parent evidence; PR155 source checks are green.
- Documentation: `docs/modules/recruiting.md` already records the PROD-09 visibility contract; no module documentation change is needed for this regression-test/snapshot-order correction. New combined validation worklog fragment is added in this repair.
- Next: commit/push the focused fix, run required full CI once at the new head, and complete independent combined Astra review. Do not merge, preview, or deploy.
- Unexecuted: final combined CI and combined Astra review. No live browser/provider/auth/production proof is claimed.
- Timestamp: 2026-10-05 Asia/Saigon.
