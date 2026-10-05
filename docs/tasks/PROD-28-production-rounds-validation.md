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
- Step: all authorized source heads are combined; run focused repository checks, record final inventory, then push the draft validation PR and run its required full CI.
- Branch/base: `chore/validate-production-rounds`, based on `origin/main` 38d90eaada1c43363cd64c2f16d7ceaecb611dab; current combined HEAD before task-state commit: 92d810136ee1488aed3b8d643405bf8c862a29da.
- Completed: exact heads above merged; PR139 scoped-ranking implementation is present before PR153; PR154 rules are already in base; PR155 documentation included. PR141 mobile snapshots retain ranking/assistant assertions and add localized paginator labels. PR143/146 Playwright selection retains all suites. PR152 module documentation sections were combined.
- Source evidence from parent: PR139 and PR153 source CI each green (10 checks); PR155 source CI green (10 checks) with Astra PASS; PR147 refreshed docs CI is still running. These do not replace final combined-branch CI or combined Astra review.
- Next: run lightweight JSON/conflict/diff checks; push branch and open a draft PR labeled `DO NOT MERGE`; start one required full CI at the pushed final head; complete independent combined review.
- Unexecuted gates: combined full CI, combined Astra review, live browser/provider/auth verification, preview/deploy/production proof. No shipping claim is made.
- Excluded: PR142/144 and active PROD-41 source.
- Timestamp: 2026-10-05 Asia/Saigon.
