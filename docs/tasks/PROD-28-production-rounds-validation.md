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
| 157 | f006c7261f2cebe3be44c73b739d4450b0f4a0cf | source CI green; merged locally |
| 158 | ccb3f522706283f5a2ca3d1260227e027dd664f2 | corrected functional source included; source CI pending |

PR142/144 validation branches remain explicitly excluded. PR154 contributes only the rules commit already present in `origin/main` 38d90eaada1c43363cd64c2f16d7ceaecb611dab. PROD-39/PR155 is documentation-only. PROD-41/PR157 is included as a source for combined validation; full CI run `37292425236` passed on exact head f006c72. PR158 functional head bbca41419eadf825da16842ed8c3b27e3f918c88 is included; source CI run `37294470282` is in progress and exact-head static Astra review passed.

## Контекст з KB
Atlassian KB/cloudId unavailable in this session; prior Rovo/Drive SinHRM searches had no applicable results.
Current repo/source tasks/UNIFIED-TZ and exact GitHub source heads are authoritative. Query: SinHRM production rounds combined validation.

## Стан

- Час: 2026-10-05 17:35 Asia/Saigon.
- Scope: combined validation only; no merge to main, preview or deployment.
- Worktree / branch / base: `D:/Projects/sinhrm-wt/production-rounds-validation` / `chore/validate-production-rounds` / `origin/main` `38d90eaada1c43363cd64c2f16d7ceaecb611dab`.
- Prior combined baseline: PR156 exact head `d23b6b17723f2ef8680f3f668bf4cb35ee9868d0`, Astra-reviewed and green at its prior exact source set; prior CI run `37285204644` had focused assertion and inventory failures, repaired at d23. Do not treat that prior green/review as covering new PR157 source.
- Current local source chain: PR157 full-history merge commit bd264ecd7054395831a4b7729dea276e029c1343; PR158 full-history merge in progress at exact head ccb3f522706283f5a2ca3d1260227e027dd664f2. PR157 mobile integration inventory retains PR140 launcher action plus PR157 synthetic-preview action. Resolved Users docs retain the mobile containment guidance and existing credential-safety note. PR158 source CI `37297027767` is pending; earlier source head bbca414 failed UI parity and is superseded.
- PR157 evidence: exact prior head `0726d2f05044e24f733ae68386cc75a98fd52193` had static Astra PASS and CI PHPStan failure at `EmployeeDirectoryPreviewTest.php:81` for missing iterable value type. Fix commit f006c72 annotates `array<string, mixed>`; fresh CI run `37292425236` passed on exact head f006c72. Astra final exact-head review passed on f006c72 (reviewer confirmed one-line PHPDoc-only delta); final combined Astra review is still required.
- Combined checks so far: production build passed with existing Angular bundle/style budget warnings. Targeted compiled E2E `npm run e2e -- -g 'integrations|users' --project=desktop-light --project=mobile-light --project=desktop-dark --project=mobile-dark` passed 28 cases (Playwright report `.out/results/.last-run.json`: `failedTests: []`) against the combined bundle, without an application DB. This includes all four integrations inventory variants and Users mobile 390/375 control checks. `git diff --check` passed.
- Local rendered evidence from that targeted run: `frontend/e2e/.out/screens/{desktop-light,mobile-light,desktop-dark,mobile-dark}/integrations*.png` and `users*.png`; integrations golden changes came from the compiled app and include four disabled Google actions without href, with both mobile launcher and synthetic-preview actions retained. Final combined CI screenshot inspection is still required for shipping evidence.
- Documentation: source inventory includes PR157; PROD-41 state was consolidated to remove duplicate KB/next-step entries. Existing recruiting scope assertion and source worklog fragments remain intact.
- Next: finish lightweight merge/source checks, commit and push the PR158 combined merge as draft PR156, then complete full CI, inspect final combined shipping screenshots, and obtain independent Astra review. No merge/deploy.
- Blockers: PR158 source CI, combined CI, exact-head Astra review, and rendered combined screenshots.
