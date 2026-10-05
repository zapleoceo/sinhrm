# PROD-28 — Joint production-round validation (DO NOT MERGE)

## План
1. Verify exact source PR heads against GitHub, combine those revisions in isolated branch.
2. Resolve conflicts minimally, preserving all source logic/assertions/module docs/worklog fragments.
3. Run targeted integration guards locally, full CI once; integrate pending PROD-09 head when supplied and rerun final CI.
4. Independent combined review including PR138/139/140/141/143; no shipping claim, preview, deploy or main merge.

## Source inventory — GitHub heads verified 2026-10-05
| PR | Exact head |
|---|---|
| 138 | 31ffbf6a98fe0aa61000d253f1085875e9983a5a |
| 139 | 227046dd01c389e37a7369ef9e505a98ced3afe4 |
| 140 | 880772c9eba9f704f06db300cbafa02f539d0a18 |
| 141 | 9da8931a3197a79df61b00bf710211bedabff980 |
| 143 | 091f22fd284c880f7a655b915da9c9cf775daad2 |
| 145 | 88dd8b7ac1dac13f99595e2bd15f14465460627a |
| 146 | 8acfadbdad8edeb2a98f4aeb27b2367837c96559 |
| 147 | e0344e42d34f84d4151b0e0e339ba1828f703f5b |
| 148 | df397ed1c5eda1cf6be958fd345b7a45da9d1d51 |
| 149 | 2cd011ad829f691a04e9dee5b9a1373e96631cf7 |
| 150 | 081d2a16484a19d8c85c1e7a4806b0d5e13d4f94 |
| 151 | 4626e81c5efd9ea6b2ae1237dad9b6185f0afc53 |
| 152 | 4bf3524ce10222bafbd1e44091f9c572ed86d588 |

PR142/144 validation branches explicitly excluded. Sources remain independent open draft PRs.

## Контекст з KB
Atlassian KB/cloudId unavailable in this session; prior Rovo/Drive SinHRM searches had no applicable results.
Current repo/source tasks/UNIFIED-TZ and exact GitHub source heads are authoritative. Query: SinHRM production rounds combined validation.

## Стан
- Step: source heads verified; merging exact revisions next.
- Base: origin/main 3b4ac30b101e662441ce224ea73924f529db74e0.
- Completed: new isolated worktree; all 13 GitHub heads match requested source inventory.
- Next: smallest conflict resolutions, CI, then pending PROD-09 and final combined Astra review.
- Blockers: PROD-09 source not ready; no live browser/provider/deploy proof in this branch.
- Timestamp: 2026-10-05 Asia/Saigon.

### Integration pause
Merged exact PR138/139/140 heads. Four mobile inventory conflicts resolved by preserving both independent buttons (AI ranking and compact assistant launcher), not dropping either assertion.
Integration paused before further source merges/push: PR139 ranking visibility bug confirmed; aggregate must be scoped before MAX/sort/filter using shared ApplicationVisibility helper with pending PROD-09.
Next: fix owning PR139, review new139/09 heads, replace inventory heads, continue all source merges, then combined CI/review.
No validation PR/push/deploy created yet.
