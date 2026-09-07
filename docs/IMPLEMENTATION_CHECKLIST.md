# Implementation Checklist — Akunta

Dokumen ini adalah hasil audit repository dan checklist implementasi yang diturunkan
langsung dari `plan.md`. `plan.md` adalah **source of truth**. Checklist ini hanya
mencatat status implementasi; ia tidak menambah atau mengurangi scope dari plan.

## 1. Hasil Audit Repository (baseline)

Audit dilakukan pada commit awal repository (`fcd48d2 Initial commit`).

| Aspek | Temuan |
| --- | --- |
| Isi repository | Hanya `README.md` berisi satu baris (`# Akunta`) |
| Backend | Tidak ada. Belum ada Laravel, `composer.json`, atau `artisan` |
| Frontend | Tidak ada. Belum ada `package.json`, Inertia, atau React |
| Database | Tidak ada migration, schema, maupun konfigurasi PostgreSQL |
| Queue | Tidak ada konfigurasi Redis/queue |
| AI Worker | Tidak ada direktori `ai-worker/` maupun FastAPI app |
| Object storage | Tidak ada konfigurasi S3-compatible storage |
| CI/CD | Tidak ada `.github/workflows` |
| Docker | Tidak ada `Dockerfile` maupun `docker-compose.yml` |
| Tests | Tidak ada test suite |
| Coding standard | Tidak ada Pint, ESLint, Prettier, PHPStan, atau Ruff |

Kesimpulan audit: project bersifat **greenfield**. Tidak ada arsitektur lama yang
perlu dipertahankan atau dimigrasikan, sehingga stack pada `plan.md` §26.1
(Laravel + Inertia/React + PostgreSQL + Redis + Python AI Worker) diadopsi langsung.

Keputusan struktur yang mengikuti `plan.md` §27 dan §28:

- Laravel berada di root repository (`app/Domain/...`, `app/Services/...`, `app/Jobs/...`).
- Python AI Worker berada di `ai-worker/` sebagai sibling, bukan microservice tambahan
  (`plan.md` §26.2: "Jangan membuat microservices banyak pada MVP").

## 2. Scope Iterasi Ini

Hanya **Phase 0, Phase 1, dan Phase 2**. Phase 3 ke atas (Document Inbox, Document
Intelligence, dan seluruh AI document processing) **tidak** dikerjakan pada iterasi ini,
sesuai `plan.md` §38 dan §48 yang mewajibkan Accounting Foundation lulus test lebih dulu.

---

## 3. Phase 0 — Project Foundation

Deliverables per `plan.md` §37 Phase 0.

| # | Item | Status |
| --- | --- | --- |
| 0.1 | Repository + struktur direktori domain/service | Selesai |
| 0.2 | Coding standard (Laravel Pint, PHPStan/Larastan, ESLint, Prettier, Ruff) | Selesai |
| 0.3 | Environment (`.env.example`, config PostgreSQL/Redis/S3/AI worker) | Selesai |
| 0.4 | CI/CD (GitHub Actions: PHP tests, static analysis, lint, frontend build, worker tests) | Selesai |
| 0.5 | Docker (`docker-compose.yml`: app, queue worker, postgres, redis, minio, ai-worker) | Selesai |
| 0.6 | Laravel application skeleton | Selesai |
| 0.7 | PostgreSQL sebagai default database connection | Selesai |
| 0.8 | Redis sebagai queue + cache driver | Selesai |
| 0.9 | Frontend shell (Inertia + React + TypeScript + Vite + Tailwind) | Selesai |
| 0.10 | Python AI Worker (FastAPI) dengan health endpoint + provider abstraction stub | Selesai |
| 0.11 | Object storage (S3-compatible, private disk, signed URL) | Selesai |
| 0.12 | Money handling: `NUMERIC(20,2)` + integer/decimal casting, tanpa float (`plan.md` §44.14) | Selesai |
| 0.13 | Audit log foundation (`audit_logs` + `RecordsAuditTrail`) | Selesai |

Acceptance `plan.md` §37 Phase 0:

| Acceptance | Status | Bukti |
| --- | --- | --- |
| App deploy ke staging | Sebagian | Docker image + compose + CI build tersedia. Deployment aktual ke staging butuh infrastruktur/kredensial di luar repo, jadi ditandai belum selesai di §7. |
| Queue berjalan | Selesai | `QueueSmokeTest` memverifikasi job ter-dispatch dan ter-handle; service `queue` di compose |
| AI worker reachable | Selesai | `AiWorkerClient` + `/health` endpoint + `AiWorkerClientTest` (HTTP fake) + `test_health.py` |
| Tests berjalan di CI | Selesai | `.github/workflows/ci.yml` menjalankan Pest, PHPStan, Pint, ESLint, tsc, Vite build, pytest, Ruff |

---

## 4. Phase 1 — Authentication & Multi-Tenant Workspace

Build items per `plan.md` §37 Phase 1.

| # | Item | Status |
| --- | --- | --- |
| 1.1 | Authentication (login, logout, register, session, password hashing) | Selesai |
| 1.2 | Organizations + `organization_users` | Selesai |
| 1.3 | Business workspace + multi-business per user (`plan.md` §3.1) | Selesai |
| 1.4 | Members (`business_users`) + invite/attach member | Selesai |
| 1.5 | Roles & permissions (`roles`, `permissions`, `permission_role`) per `plan.md` §4 | Selesai |
| 1.6 | Business profile (`business_profiles`) per `plan.md` §5.1 | Selesai |
| 1.7 | Accounting periods (`accounting_periods`) + state machine `plan.md` §20.1 | Selesai |
| 1.8 | Tenant isolation: global scope, resolver, middleware, policies | Selesai |
| 1.9 | Audit logging pada operasi tenant sensitif | Selesai |
| 1.10 | API `/api/v1` untuk auth & businesses (`plan.md` §29.1, §29.2) | Selesai |

Acceptance `plan.md` §37 Phase 1:

| Acceptance | Status | Bukti |
| --- | --- | --- |
| 1 user dapat memiliki beberapa bisnis | Selesai | `MultiBusinessTest` |
| Accountant dapat mengakses beberapa client | Selesai | `AccountantMultiClientTest` |
| Tenant isolation tested | Selesai | `TenantIsolationTest`, `CrossTenantApiTest` |

---

## 5. Phase 2 — COA & Accounting Foundation

Build items per `plan.md` §37 Phase 2.

| # | Item | Status |
| --- | --- | --- |
| 2.1 | Chart of accounts (`chart_of_accounts`) per `plan.md` §12.2 | Selesai |
| 2.2 | COA templates (`coa_templates`, `coa_template_accounts`) + seeder starter `plan.md` §12.1 | Selesai |
| 2.3 | Account types (asset, liability, equity, revenue, cost_of_sales, expense, other_expense, other_income) | Selesai |
| 2.4 | Account roles / system role (`CASH_OR_BANK`, `ACCOUNTS_RECEIVABLE`, `INVENTORY`, dst. per `plan.md` §11) | Selesai |
| 2.5 | Journal schema (`journal_entries`, `journal_entry_lines`, `journal_entry_sources`) per `plan.md` §24.2–24.3 | Selesai |
| 2.6 | DB constraints: `debit >= 0`, `credit >= 0`, `NOT (debit > 0 AND credit > 0)` | Selesai |
| 2.7 | Journal posting service (DB transaction, debit=credit, period lock, immutability) | Selesai |
| 2.8 | Journal state machine `DRAFT → PENDING_APPROVAL → APPROVED → POSTED → REVERSED` (`plan.md` §25.4) | Selesai |
| 2.9 | Reversal-only correction untuk posted journal (`plan.md` §44.6) | Selesai |
| 2.10 | Trial balance calculation dari posted journals saja (`plan.md` §44.5, §45.11) | Selesai |
| 2.11 | Period closing/lock + reopen dengan audit log (`plan.md` §20.4) | Selesai |
| 2.12 | COA onboarding: generate starter COA dari template sesuai jenis usaha (`plan.md` §5.1) | Selesai |
| 2.13 | API `/api/v1` accounts + journals (`plan.md` §29.6) | Selesai |
| 2.14 | UI Inertia: Chart of Accounts, Journal, Trial Balance | Selesai |

Acceptance `plan.md` §37 Phase 2:

| Acceptance | Status | Bukti |
| --- | --- | --- |
| Manual journal dapat dipost | Selesai | `JournalPostingTest`, `JournalApiTest` |
| Debit = credit enforced | Selesai | `JournalBalanceTest` (service level) + `JournalLineConstraintTest` (DB level) |
| Trial balance benar | Selesai | `TrialBalanceTest`, `AccountingGoldenDatasetTest` |
| Closed period tidak dapat diubah | Selesai | `PeriodLockTest` |

---

## 6. Testing Strategy Coverage (`plan.md` §33)

Unit test wajib per `plan.md` §33.1, dibatasi pada area yang masuk Phase 0–2:

| Area | Status |
| --- | --- |
| Accounting rules | Belum — rule engine adalah Phase 9 |
| Journal balancing | Selesai |
| Period lock | Selesai |
| COA behavior | Selesai |
| Report calculations | Sebagian — hanya trial balance (Phase 2). Income statement/balance sheet/cash flow adalah Phase 12 |
| Matching score | Belum — Phase 7 |
| Permissions | Selesai |

`plan.md` §33.3 Accounting Golden Dataset: tersedia versi manual-journal
(`AccountingGoldenDatasetTest` + `GoldenDatasetSeeder`) yang mencakup sales, purchases,
operating expenses, owner transactions, loans, receivable/payable payments, QRIS
settlement dengan MDR, dan internal bank transfer. Bagian dataset yang bergantung pada
AI pipeline (upload dokumen, ekstraksi) belum dikerjakan karena masuk Phase 3+.

---

## 7. Item `plan.md` yang BELUM Dikerjakan

Sengaja tidak dikerjakan karena berada di luar Phase 0–2.

### Di luar phase (Phase 3 ke atas)

- `plan.md` §37 Phase 3 — Document Inbox (upload, viewer, processing status, retry).
- `plan.md` §37 Phase 4 — Document Intelligence (classifier, extraction, confidence, review UI).
- `plan.md` §37 Phase 5 — Transaction Normalization (bank row parser, canonical transaction).
- `plan.md` §37 Phase 6 — Entity Resolution (entity master, aliases, fuzzy/AI matching).
- `plan.md` §37 Phase 7 — Duplicate & Related Matching.
- `plan.md` §37 Phase 8 — Economic Event Classification.
- `plan.md` §37 Phase 9 — Accounting Rules engine (`accounting_rules`, `accounting_rule_lines`).
- `plan.md` §37 Phase 10 — Review Center.
- `plan.md` §37 Phase 11 — Reconciliation Engine.
- `plan.md` §37 Phase 12 — Reporting (income statement, balance sheet, cash flow, AP/AR, drill-down).
- `plan.md` §37 Phase 13 — Closing Center (readiness score, checklist, issue detection).
- `plan.md` §37 Phase 14 — AI Financial Analyst.
- `plan.md` §14 — Prompt contracts (`classify_document`, `extract_invoice`, `classify_economic_event`).
- `plan.md` §15 — Confidence Engine.
- `plan.md` §17 — Feedback & learning layers.
- `plan.md` §18 — Duplicate vs related document.
- `plan.md` §22 — AI Financial Analyst insight cards.
- `plan.md` §32 — Observability metrics & AI quality metrics.
- `plan.md` §34 — Sample dataset 3 tipe bisnis berbasis dokumen nyata.
- `plan.md` §36 — Dashboard MVP angka finansial (butuh posted journal dari pipeline AI).
- `plan.md` §42, §43 — Future integrations & product evolution.
- `plan.md` §47 — UAT checklist end-to-end.

Tabel `plan.md` §23 yang belum dibuat karena milik phase berikutnya:
`documents`, `document_files`, `document_pages`, `document_extractions`, `document_fields`,
`document_processing_jobs`, `entities`, `entity_aliases`, `entity_identifiers`,
`entity_relationships`, `transactions`, `transaction_sources`, `transaction_evidence`,
`transaction_relations`, `transaction_tags`, `ai_predictions`, `ai_prediction_candidates`,
`ai_feedback`, `ai_model_runs`, `ai_usage_logs`, `economic_event_types`,
`economic_event_predictions`, `accounting_rules`, `accounting_rule_lines`, `review_tasks`,
`review_actions`, `review_comments`, `reconciliations`, `reconciliation_items`,
`reconciliation_matches`, `closing_periods`, `closing_checklists`, `closing_issues`.

Catatan: `bank_accounts` (`plan.md` §23.2) **sudah** dibuat pada Phase 1 karena menjadi
bagian onboarding bisnis di `plan.md` §5.1.

### Dalam scope Phase 0–2 tetapi butuh infrastruktur eksternal

- `plan.md` §37 Phase 0 — deploy aktual ke staging. Repo sudah menyediakan Docker image,
  compose, dan CI, tetapi eksekusi deployment butuh host/registry/kredensial.
- `plan.md` §30 — malware scanning, backup + restore test, dan secret manager produksi.
  Rate limiting, MIME validation dasar, private storage, signed URL, dan audit log sudah ada
  di kode; sisanya adalah tugas platform/operasional.
- `plan.md` §41 — daily PostgreSQL backup dan object storage versioning (operasional).
