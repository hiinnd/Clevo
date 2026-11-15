# Payment phase validation results

Executed 2026-11-16 against disposable databases and loopback PHP/Apache servers. See [PAYMENT_INTEGRATION.md](PAYMENT_INTEGRATION.md) for financial semantics, migration 006, provider research and commercial release blockers.

## Environment and isolation

Observed tools: PHP CLI/mod_php 8.2.12, MariaDB 10.4.32, Windows PowerShell, Node 24.22.3, development-only Redocly CLI 2.78.0. These local versions are evidence of the tests, not production patch-level recommendations. The exact deployed MySQL release and production proxy/SAPI still require restored-copy rehearsal.

`tests/run-isolated.ps1` uses fresh random database names in the dedicated workspace `.security-test/mysql` datadir on loopback 13379. It verifies that actual datadir before migrations, starts PHP HTTP 18089 and isolated Apache 18090, uses generated private identities/credentials, and stops only its own processes. No production database, real user, bank account, merchant account or external payment service was modified. The database suite exercises both empty and populated legacy copies, checksum reapplication/refusal, unchanged original-column hashes, constraints, concurrent operations and actual logical backup/restore.

Provider tests inject a transport closure directly into the sandbox adapter for backend create/query/refund calls. HTTP webhook requests exercise the actual PHP signature/parser/domain/DB path on localhost using ephemeral synthetic signing material. Runtime provider selection has no fake-success mode. Generated credentials/cookie jars live only in ignored, HTTP-denied test storage and are never included in this report. The synthetic environment file is restored after financial tests.

## Executed checks

The final complete runner exited successfully. Individual suite assertions include positive behavior, expected rejection and concurrency invariants; they are not independent penetration tests.

| Check | Result |
|---|---|
| Security unit suite | 47/47; zero failures |
| Credential source scan | 135 files; zero findings |
| OpenAPI/runtime contract suite | 821/821; zero failures |
| Authentication/security integration | 172/172; zero failures |
| REST API regression | 1,630/1,630; 584 helper HTTP requests, 134 registry operations exercised |
| Shared booking workflow | 121/121; 18 helper HTTP requests |
| Financial/webhook suite | 161/161; zero failures; 45 helper HTTP requests; no external payment service contacted |
| Database migration/concurrency/recovery | 173/173; zero failures; includes migration 006 and backup/restore |
| SQL compatibility preparation | 457 complete static statements prepared; zero candidates requiring fragment review |
| Isolated Apache rules and bearer API | 14/14 rules and 2/2 bearer/API assertions; zero failures |
| First-party PHP syntax | 139/139 files |
| Standalone JavaScript syntax | 3/3 files |
| Rendered financial-page JavaScript | Parsed during actual session-authenticated PHP page tests |
| OpenAPI 3.1.1 / Redocly | Valid after correction of webhook request-body description placement |
| Composer manifest / platform | Strict validation and installed platform checks passed |
| Composer locked dependency advisory audit | Empty advisory and abandoned-package lists |
| Development OpenAPI tool dependency audit | Zero vulnerabilities reported |
| Git whitespace check | Passed after removal of added trailing spaces |

The API specification has 145 operations across 125 paths, including 11 preserved authentication operations in addition to 134 registry operations. The static provider HTML return page is tested separately. The financial suite supplements the generic route/contract regression with semantic accounting and signed-webhook scenarios. HTTP helper counts exclude some page-login/upload/concurrent-worker traffic; they are not load measurements.

## Financial scenarios covered

- Integer JOD fils, canonical amounts, excessive precision/negative/scientific-string rejection, approved 5% half-up calculation, exact owner remainder, new month-end/leap-year subscription expiry.
- Missing credentials/configuration refuses checkout without marking a booking paid; production factory, deep evidence application and subscription activation guards refuse sandbox promotion.
- Historical wallet stays read-only: actual API spending/subscription purchase and legacy credit/debit/record helpers cannot bypass the freeze. Loyalty conversion has a shared guard; an actual legacy PHP POST cannot mutate the frozen wallet.
- Backend-authoritative checkout price, encrypted stored hosted URL, same-key retry, different-key active-intent deduplication, independent-process concurrent creation with one provider create attempt, and cash/online conflict.
- Wrong signature, amount, currency, merchant, unsupported authorization transaction and partial refund are rejected. Browser return and query-string subscription success are not evidence. Local cancellation does not claim provider confirmation or keep exposing checkout.
- Hold/pending, decline, provider cancellation, type-omitted non-success bound to a known reference, late failure, ambiguous timeout, authenticated-query recovery, expired/cancelled target and capture after local cancellation.
- Verified sale records one capture/receipt, holds funds until fulfillment, and does not spend a legacy wallet. Exact/semantic/concurrent webhook replay cannot duplicate financial effects. Out-of-order refund-before-sale is retriable and then compensates exactly once.
- Cash selection moves no money. Authorized completion records physical collection evidence and a platform commission receivable without a duplicate owner payout.
- Customer/owner/admin role and object scoping for attempts, receipts, statements and reconciliation; a settlement request explicitly records blocked/no transfer.
- Owner statement includes compensating refunds through the owner's own ledger accounts, reconciles its transaction deltas to net payable, and returns no other owner's entries.
- Refund request/submission versus confirmation, one outbound refund attempt, pending refund blocking service work, signed full reversal, central pre-service cancellation and refund replay. A concurrent completion/refund race serializes and leaves no earnings for refunded money.
- Provider-reported refunds after completion reverse earned allocations while preserving service history. Full package refund compensates all recognized allocations and revokes entitlement. Used-package automatic refund is rejected for manual review.
- No package activation before verified funding; one funding order activates once. Explicit sandbox eligibility, quota enforcement, fixed funded-slot remainder allocation, cancellation/reuse conservation and no owner subsidy from nominal service prices. Unsupported duration is rejected before funding.
- Balanced immutable journals, replay content conflicts, denied runtime UPDATE/DELETE of financial records, internal receipt/order agreement, and explicit absence of external bank reconciliation.
- Actual legacy PHP login, hosted checkout without PAN/CVV fields, subscription page, owner statement page and loyalty POST render with parseable inline JavaScript.

## Failures found and resolved during development

1. An initial legacy public-registration assertion returned CSRF 403 because a fixed cookie-jar path retained a session from an earlier disposable database. Cookie jars now include the random test database name. The complete authentication suite subsequently passed 172/172; CSRF enforcement was retained.
2. Redocly initially rejected a description placed directly on a media-type object in the new webhook request body. The generator now places it on the request-body object; regeneration and validation passed. The earlier combined lint/audit shell command's final audit exit code was not treated as evidence that lint passed.
3. Git whitespace validation found added trailing spaces in checkout/owner-page changes; these were removed and the check rerun.
4. The intentional injected provider timeout produces a reconciliation-required log with only the exception class. That scenario is expected, not a provider success. Its expected CLI log is directed to protected test storage so PowerShell does not present it as a spurious native-command error. Apache's successful `Syntax OK` diagnostic is emitted on stderr by Apache; the runner checks the process exit code and HTTP assertions.

Composer reported that its normal user cache directory was not writable and continued without cache; the advisory request completed successfully with empty lists. This warning did not change runtime dependencies. No unresolved assertion failure is accepted as a pass.

## Not verified / release blockers

No real PayTabs sandbox session, bank authorization, 3-D Secure journey, external signed callback, refund settlement, dispute/chargeback, provider statement or bank transfer was executed. No approved CLEVO merchant/profile credentials or marketplace agreement was available. Transport fixtures establish local invariants, not provider certification. Live online payments, commercial wallet redemption, subscriptions and owner payouts remain disabled as documented.

Partial refunds, provider fees/taxes, fiscal invoices, split settlement, reserves and funded promotional subsidies need approved rules and implementation/certification before real-money enablement. Silver/Gold/Diamond are policy labels only until the actual catalog and benefits are approved. Existing unproven balances/entitlements require evidence-based reconciliation, not fabricated migration credits.

There was no production-sized load test, independent penetration test, PCI-scope certification, offsite production restore drill or Flutter test. No claim of complete production security or commercial readiness is made.

Reproduce with `powershell -NoProfile -ExecutionPolicy Bypass -File tests/run-isolated.ps1`, then run Composer validation/platform/advisory checks, `npm.cmd --prefix tests/openapi run validate`, PHP/JavaScript syntax and `git diff --check`. Use the isolated runner rather than pointing individual financial tests at an existing database.
