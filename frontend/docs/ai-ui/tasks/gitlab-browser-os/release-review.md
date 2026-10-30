# Independent GitLab release review

Reviewer: SOL; source/release review only. Date: 2026-10-30 (Asia/Saigon).

## Scope and evidence

Candidate: `D:/Projects/HRM/worktrees/gitlab-browser-os`, branch `feat/browser-os-redesign`; base `66d440f5c82b0f719736a66491162290f444a9fb`. Review includes working-tree tracked changes and untracked manifest files, not only committed changes. Previously approved source is `3301c3e96d7e2c9bcc1d807461b9df72808d35b9`, delta from `899d4dab`, in `browser-os-integration`.

Read shared common policy, manually resolved sinhrm adapter (router reports split GitLab repository unregistered), root CLAUDE/AGENTS, docs policy/development/deploy, app and e2e contracts. No local build/server or full suite run by reviewer.

Compared all 211 manifest paths with approved source bytes after CRLF normalization: exactly three expected differences (`e2e/flows.pw.ts`, package manifest, lock). Intentional deleted nav-rail files remain deleted. Other manifest blobs match source exactly. Flows differs only by correct on-site CSRF path `/api/sanctum/csrf-cookie`.

Runtime files remain unchanged from target base: `server.mjs`, `server/`, `run.sh`, `.nvmrc`, `angular.json`, core HTTP/API. Package keeps `node server.mjs`, separate dev script, Node >=24 and target server tests. Only new script behavior is adding project-coverage inventory test to e2e lint. No Vercel deployment files introduced. Target-specific dependency lock remains substantially different from previously approved source.

## Preliminary findings

- Source application port: PASS, no new P1/P2 source findings beyond approved review.
- Release gate: NOT YET PASS. Writer reports four HIGH audit findings from npm ci. Candidate lacks approved source Vite override; target package/lock cannot inherit source security CI evidence. Require strict target `npm audit --package-lock-only --audit-level=high` success or reviewed remediation before release. Task-state statement that patched Vite override was retained needs correction.
- Validation workflow preliminary inspection: PASS. `itstep-frontend-verify.yml` pins action SHAs, runs Node24/npm ci/full unit coverage/docs/lint/build/strict HIGH audit, and all four e2e projects against one production artifact. Aggregate uses always() and explicitly requires successful build and all project shards. Overall workflow success must include independent frontend job. No assertion weakening introduced.

## Remaining final gates

Freeze candidate and workflow; review dependency remediation if needed; compare exact GitLab frontend tree to mirrored GitHub frontend; require full target validation CI green. Coordinator then owns authorized staging-only merge/build/deploy and actual HTTP/browser verification. Source green CI and prior visual approval are distinct from target CI/deployment/live proof.

DOX pass: review artifact adds evidence only; no durable app contract, child ownership or index changed. Owning AGENTS files intentionally unchanged.


## Final frozen source publication gate

PASS for source publication and validation CI, with no new P1/P2 findings. This does not authorize bypassing deployment gates.

Rechecked frozen package remediation: candidate dependencies, devDependencies and overrides exactly equal reviewed source 3301c3e9; parsed lock graph exactly equals source after only target top/root name and root Node engines metadata are normalized. Thus Vite 8.3.5 override and full reviewed transitive graph are preserved. Target still starts node server.mjs and declares Node >=24. Writer reports successful npm ci and locked HIGH audit exit 0, zero high / one moderate fast-uri finding; reviewer verified graph identity rather than rerunning install/full suite. Earlier preliminary dependency blocker is resolved by this reviewed change. Task state now accurately records three semantic conflicts.

All remaining application manifest files retain earlier exact approved-source comparison; correct on-site CSRF adaptation remains the sole flows difference. Validation workflow is approved as inspected, including strict audit and successful all-four-project aggregate. No existing CI workflow or external GitLab deployment recipe is changed by this review.

Before merge coordinator must verify exact GitLab-to-GitHub frontend tree identity and whole validation workflow success (frontend job plus parity build/shards/aggregate). Then staging BUILD/DEPLOY and actual target HTTP/browser evidence remain necessary. No full local suite, Git operation, deployment, server startup, or secret access performed by reviewer.


## Unicode manifest correction gate

PASS after eight source-approved Unicode snapshot paths were restored. Earlier manifest-based scope was incomplete: Git quoted non-ASCII names by default and the port script omitted eight profile-tab inventories. This caused desktop parity failures; it was a port defect, not evidence permitting new baseline generation.

Reviewer independently enumerated the complete frontend delta with `git diff --name-only -z 899d4dab 3301c3e9 -- frontend/` and decoded paths as UTF-8. Actual count is 219 paths. Compared every source blob (including expected deletions) directly with frozen candidate files after LF normalization, without using the previous manifest. Only the three already approved semantic adaptations differ: flows CSRF path, package manifest, lock metadata. Thus all eight Unicode snapshots exactly match approved source and no additional omissions remain. Application code/runtime unchanged by this correction. Require the corrected exact-target CI rerun before merge.
