# Employee ↔ User lifecycle contract

Status: **DRAFT / NON-EXECUTABLE / OWNER_PENDING**. Date: 2026-10-04.
Evidence snapshot: release validation head `dc443b4` (combined PRs 138–141 and 143).
This document describes a proposed future contract and synthetic test scenarios. It authorizes no offboarding, ACL sync, integration writes, or account changes.
No option below is an approved product decision; implementation and lifecycle tests are pending.

## 1. Observed behavior and gaps

| Observation in current code | Gap / limit of evidence |
| --- | --- |
| [EmployeeService::terminate](../../backend/app/Modules/People/Services/EmployeeService.php#L106) immediately writes terminated/fired_at and dispatches EmployeeTerminated. | No coordinated Employee + User access transition. No explicit transaction/lock in this service path; [repository update](../../backend/app/Modules/People/Repositories/EloquentEmployeeRepository.php#L121) calls fill/save. This is not a claim that SQL/framework performs no transactions. |
| [Workflows listener](../../backend/app/Modules/Workflows/Providers/WorkflowsServiceProvider.php#L87) starts offboarding; [Pulse listener](../../backend/app/Modules/Pulse/Providers/PulseServiceProvider.php#L61) starts an exit survey. | These listeners do not implement access revocation. [Survey starts_at](../../backend/app/Modules/Pulse/Services/LifecycleSurveys.php#L79) is now, even when fired_at is future. |
| [Termination validation](../../backend/app/Modules/People/Http/Requests/TerminateEmployeeRequest.php#L18) accepts a date; terminate changes status immediately. | Future-date scheduling, effective instant, timezone and late-event policy are not defined here. |
| [User administration](../../backend/app/Modules/Users/Services/UserAdminService.php#L53) independently changes status, roles and branches, protecting the last active superadmin. | No coupling to Employee termination, global session/PAT invalidation or handover. |
| [Blocked-user middleware](../../backend/app/Modules/Auth/Http/Middleware/EnsureUserIsActive.php#L20) calls web logout and invalidates the current request session. [PAT deletion](../../backend/app/Modules/Auth/Repositories/SanctumPersonalTokenRepository.php#L25) is per token name. | No application-wide revocation on status change was found. Static risk: a surviving valid PAT or untouched stored session may regain access after unblock. This is not live verification; an already invalidated session does not revive. |
| [PeopleScope](../../backend/app/Modules/People/Services/PeopleScope.php#L33) checks User activity; [findByUser](../../backend/app/Modules/People/Repositories/EloquentEmployeeRepository.php#L67) and managerMap include terminated Employees. | Termination alone does not remove contextual manager scope. Historical visibility and current authorization need separate rules. |
| [TaskStepExecutor](../../backend/app/Modules/Workflows/Executors/TaskStepExecutor.php#L31) uses stored non-null assignee; fallback only handles null. | No current activity check there; termination does not automatically transfer owned work. |
| [PATCH status validation](../../backend/app/Modules/People/Http/Requests/SaveEmployeeRequest.php#L52) permits active/on_leave; [update](../../backend/app/Modules/People/Services/EmployeeService.php#L91) saves supplied attributes. | No dedicated restore contract for dates, reviewed access, old workflows and rejoining was found. |
| [Current schema](../../backend/app/Modules/People/Database/Migrations/2026_10_02_100001_create_people_tables.php#L13) has unique nullable user_id and one position_id. | It represents at most one Employee per User; external multiposition/employment semantics are unverified. Users may exist without an Employee. |

## 2. Owner decisions — approval required before dependent implementation

| Decision | Status / owner | Options to resolve, without selecting a default | Dependent gate |
| --- | --- | --- | --- |
| D1: R0 organization, identity and employment scope | OWNER_PENDING — product owner + identity/integration owner; named approvers TBD | Restrict R0 to explicitly mapped organization and current one-Employee/one-position model, or support multiple employments/positions with an approved account-disable rule. Define field authority, stable external identity, unmapped identities, and separate admin User without Employee. | R0, I1–I3, I5, I8: no external lifecycle application until scope and mapping are approved. An unlinked admin account is not automatically a termination target. |
| D2: Termination effective date/timezone and rejoin | OWNER_PENDING — product owner + HR/security owner; named approvers TBD | Immediate-only operation, or scheduled termination with explicit effective instant/timezone; define backdating, late events, cancellation and rejoin semantics. Define which approved event disables account access and how restore reviews roles/branches and lifecycle history. | I6–I7: future dates cannot be interpreted as an approved scheduling contract from current behavior. |
| D3: Transfer owner, tasks and exit survey | OWNER_PENDING — HR/process owner + product owner; named approvers TBD | Define named accountable recipient, reviewed transfer/cancel/keep treatment, affected owned work and exceptions; define exit-survey timing/delivery and old workflow disposition on rejoin. No automatic inheritance of the departing user's permissions. | R0, I4, I6: handover completion and survey obligations require an owner answer. |

## 3. Proposed universal invariants for a future operation

These constrain a future implementation; they do not select D1–D3 or approve a source-system adapter.

- U1 — Explicit authority: only a validated, authorized lifecycle command/event may change lifecycle or access. Missing rows, 403, timeout, partial pagination and missing integration configuration are not termination, restore or permission-grant events.
- U2 — Current authorization: check actor/service identity, current permissions, target scope and explicit mapping at execution time; stale UI state or a narrowed display role is insufficient authority.
- U3 — Identity safety: resolve the approved source/namespace/external-id mapping; ambiguous or unknown identities produce a reviewable error/quarantine, not a guessed email match, new account or ACL grant.
- U4 — Idempotency/order: repeated delivery, retries and concurrent commands do not duplicate lifecycle effects or overwrite newer accepted state. Record event/operation identity and outcome; stale events cannot silently restore access.
- U5 — Local consistency: coordinate the agreed local Employee/User transition transactionally with concurrency protection. External effects require recorded state, durable retries and visible failures; do not claim atomicity across independent systems.
- U6 — Access denial: once an approved access-disable event is effective and locally committed, every supported authenticated entry point denies that user's revoked access. HR workflow, notification or survey failures do not postpone that denial.
- U7 — Credential invalidation: invalidate all affected sessions, remember access and personal tokens as part of the agreed disable operation. Unblock/restore does not make revoked credentials valid again; new credentials require normal authorized issuance.
- U8 — Authorization/history separation: loss of approved employment-derived manager scope removes that current scope; historical reporting data remains governed by explicit read permissions. Do not silently remove separately authorized admin access or grant replacement rights.
- U9 — Protected administration: preserve the existing last-active-superadmin safeguard; surface its dependency rather than silently bypass it or claim a completed disable. A required transfer must be explicitly authorized.
- U10 — Work accountability: check current assignee eligibility at execution; unavailable assignees and unresolved work remain visible. No automatic permission inheritance or false completion; transfer/cancel rules depend on D3.
- U11 — Restore review: restoration is an explicit authorized operation with reviewed identity, approved current roles/branches and effective state. Old tokens, workflows, termination dates and rejoin history are not silently reused; their treatment depends on D2/D3.
- U12 — Evidence/privacy: audit transition identity, actor, time, outcome and retry status without credentials or unnecessary personal data. Report local access denial separately from unverified external revocation.

## 4. Test scenarios — proposed, not implemented or run

Use synthetic identities, mocked integration contracts and isolated test sessions/tokens. No production writes or real offboarding.
"Universal expected" is conditional on the operation being approved and effective under D1–D3.

| ID | Given / When | Universal expected (Then) | Requires owner answer |
| --- | --- | --- | --- |
| T01 | Authorized actor and mapped target / approved immediate disable | U2/U5/U6/U7: coherent local transition; supported session, MCP and clipper credentials denied; audit outcome visible. | D1/D2: target and event actually require account disable. |
| T02 | Future termination date / command submitted before that date | U4/U6: no undocumented timing or silent access grant; accepted scheduling and effective state are distinguishable. | D2: reject versus schedule; exact instant/timezone and cancellation. |
| T03 | Backdated or late event / receive after a newer accepted event | U4: stale event cannot silently regrant or overwrite newer state; recorded result is repeatable. | D2: backdate acceptance and reconciliation policy. |
| T04 | Same operation delivered twice; concurrent duplicate requests / process both | U4/U5: one logical transition and no duplicate workflow/task effects; conflicts have explicit outcomes. | Business identity of distinct rejoin cycles under D2. |
| T05 | Committed local disable / notification, workflow or external call fails, then retries | U5/U6: revoked local access stays denied; failed external effect is visible; retry does not duplicate completed effects. | D1: external systems in scope; D3: process completion criteria. |
| T06 | Target is last active superadmin / disable requested | U9: existing safeguard is not bypassed; operation does not falsely report completed access disable. | Authorized replacement/transfer owner and exceptional procedure. |
| T07 | Valid old PAT and multiple stored sessions / disable, then approved restore | U7: revoked credentials stay rejected after restore; a session already invalidated remains invalid. Test both a session used during blocking and one untouched until after restore. | D2: restore eligibility and reviewed access. |
| T08 | Manager with direct/indirect reports / approved employment-derived access ends | U8: former contextual subtree rights are denied; permitted historical reads remain available to authorized readers. | D1/D3: independent admin rights and replacement manager. |
| T09 | Workflow step stores assignee who becomes unavailable / step executes | U10: eligibility checked; no task silently completed or rights inherited; unresolved ownership visible. | D3: transfer/cancel/keep recipient and timing. |
| T10 | Integration config missing, request returns 403, or pagination incomplete / sync attempt | U1/U3: no inferred firing, restore, account creation or scope broadening; diagnostic outcome visible. | D1: authorized retry/manual reconciliation procedure. |
| T11 | Unknown external ID, duplicate mapping or email changed / lifecycle event | U3: no guessed binding or accidental target mutation; reviewable unresolved result. | D1: identity mapping authority and correction workflow. |
| T12 | Multiple external positions or admin User without linked Employee / position terminates | U1/U3/U8: no unapproved account-wide disable, restore or role inheritance. | D1: supported model and which ended employments disable which account. |
| T13 | Terminated Employee / generic PATCH attempts active, or explicit restore submitted | U2/U11: lifecycle bypass cannot silently restore approved access; explicit restore requires current authorization and review. | D2: restore versus new employment; dates/history; D3: old tasks/workflows. |
| T14 | Exit survey requires participation / account disable becomes effective | U6: survey failure cannot defer approved access denial; no account reopened merely to complete a survey. | D3: delivery/timing and whether survey is a release requirement. |

## 5. Existing test evidence and remaining gate

These files cover portions of current behavior, not the proposed integrated lifecycle contract:

- [PeopleApiTest:118](../../backend/tests/Feature/People/PeopleApiTest.php#L118): create/update/terminate; [line 205](../../backend/tests/Feature/People/PeopleApiTest.php#L205): visibility of terminated Employees.
- [MeTest:55](../../backend/tests/Feature/Auth/MeTest.php#L55): blocked user with a live session gets 403.
- [ActiveRoleTest:74](../../backend/tests/Feature/Auth/ActiveRoleTest.php#L74): removed role fallback; [line 116](../../backend/tests/Feature/Auth/ActiveRoleTest.php#L116): last-superadmin guard.
- [WorkflowRunsTest:87](../../backend/tests/Feature/Workflows/WorkflowRunsTest.php#L87): offboarding anchored on last day; [line 162](../../backend/tests/Feature/Workflows/WorkflowRunsTest.php#L162): null login fallback; [line 385](../../backend/tests/Feature/Workflows/WorkflowRunsTest.php#L385): manual cancellation.
- [PulseTickTest:79](../../backend/tests/Feature/Pulse/PulseTickTest.php#L79): exit survey created once.
- [ExtensionApiTest:35](../../backend/tests/Feature/Recruiting/ExtensionApiTest.php#L35) and [McpServerTest:19](../../backend/tests/Feature/Assistant/McpServerTest.php#L19): explicit token lifecycle, separately from Employee termination.

Release gate: **OPEN** for lifecycle-dependent scope. D1–D3 need named owner answers; dependent acceptance criteria must then be finalized and implemented.
T01–T14 are **PENDING**. This document records no new CI pass, live integration result, credential revocation or production readiness claim.
Existing CI of separate features does not prove terminate → credential invalidation → manager-scope removal → reviewed restore or handover.
