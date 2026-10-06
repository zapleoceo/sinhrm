# PROD-17 — Hiring HTTP API acceptance

## Матрица покрытия до изменения
| Contract | Existing proof | Gap |
|---|---|---|
| Request route → automatic linked vacancy | HiringRequestApiTest::test_route_transitions_sla_and_auto_vacancy | Subsequent hires directly update DB status |
| Candidate normalize/deduplicate/create+apply | CandidateApiTest | Not linked to approved request/report |
| Apply duplicate/conflict/scope | VacancyApiTest | Isolated fixture vacancy |
| Stage/reject/hire/same-stage/roles | MoveApplicationTest | Applications created by service fixtures |
| Scoped recruiting/catalog reports | Recruiting/ReportsApiTest; Reports/ReportsApiTest | Direct fixture/service status changes; no exact report after full HTTP flow |

## План
One synthetic HTTP journey: existing configurable approval → auto-vacancy → candidate create → apply → stage → hire/reject → request progress and exact scoped Recruiting/Reports output.
Crossbranch decoy; viewer/foreign mutation denial, duplicate/repeated mutations and invalid rejection preserve state.
Reuse fixtures only for identities/branches/default dictionary; no new business policy, provider or frontend mocks.

## Контекст з KB
Previous Rovo/Drive SinHRM returned no relevant project context; cloudId unavailable. Vera backup lookup was unrelated.
Queries planned: SinHRM recruitment API acceptance approval scoped reports. Primary evidence: current tests, controllers, policies and UNIFIED-TZ.

## Стан
- Step: matrix gap confirmed, test implementation.
- Base: origin/main 3b4ac30.
- Scope: existing HTTP contracts only; pilot scope owner answer pending, no production-policy change.
- Next: full CI and independent review; no deploy/preview/merge.
- Blocker: PHP unavailable locally; runtime assertions require CI.
- Timestamp: 2026-10-05 Asia/Saigon.

## Handoff
One HTTP journey added in HiringApiAcceptanceTest; businesses seeded only via endpoint, except identities/branches/default dictionary and crossbranch vacancy fixture.
Exact Reports/Recruiting rows, request progress, crossbranch/viewer denials and sequential retries with count/history invariants covered.
Local PHP unavailable; git whitespace check only, full runtime proof in CI. No implementation/product policy changes.
Next: CI and Astra review, pilot owner answer remains independent release prerequisite.
