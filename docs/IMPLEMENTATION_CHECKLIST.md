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

**Phase 0, Phase 1, Phase 2, Phase 3, dan Phase 4.** Phase 3 baru dimulai setelah
seluruh test Accounting Foundation Phase 2 lulus, sesuai `plan.md` §38 dan §48, dan
Phase 4 dimulai setelah pipeline parsing Phase 3 lulus.

Phase 5 ke atas (Transaction Normalization dan seterusnya) **tidak** dikerjakan. Batas
Phase 4 adalah **data dokumen**, bukan transaksi: menentukan jenis dokumen, membaca
field beserta buktinya, menilai keyakinannya, dan menyediakan jalur review manusia.
Mengubah data itu menjadi canonical transaction, entity, economic event, dan jurnal
adalah phase berikutnya.

Konsekuensi batas tersebut pada kode:

- Tahap pipeline `plan.md` §13.1 yang dieksekusi adalah `parse`, `classify`, dan
  `extract`. Tahap `normalize` dan `match` dicatat sebagai `pending` supaya jejaknya
  terlihat, bukan diam-diam dilewati.
- Dokumen berakhir di `ready` atau `need_review`. Status `normalizing` dan `matching`
  tetap ada sebagai state `plan.md` §25.1, tetapi tidak ada dokumen yang memasukinya.
- Tidak ada jurnal yang dihasilkan dari dokumen. `Document::readyForTransactionPipeline()`
  adalah satu-satunya pintu yang akan dibaca Phase 5, dan pintu itu menuntut dokumen
  READY **dan** ekstraksi berstatus `accepted`.
- Provider AI default-nya heuristik berbasis aturan, sehingga seluruh pipeline dapat
  dijalankan dan diuji tanpa kredensial vendor. Provider OpenAI tersedia lewat
  konfigurasi (`plan.md` §13.3, §44.12).
- `plan.md` §17.2 (learning layer) belum dibangun: koreksi reviewer tersimpan lengkap di
  `ai_feedback`, tetapi belum ada kode yang membacanya sebagai masukan prediksi
  berikutnya.

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
| 0.11 | Object storage (S3-compatible, disk `documents` private-only) | Selesai |
| 0.12 | Money handling: `NUMERIC(20,2)` + integer/decimal casting, tanpa float (`plan.md` §44.14) | Selesai |
| 0.13 | Audit log foundation (`audit_logs` + `RecordsAuditTrail`) | Selesai |

Acceptance `plan.md` §37 Phase 0:

| Acceptance | Status | Bukti |
| --- | --- | --- |
| App deploy ke staging | Sebagian | Docker image + compose + CI build tersedia. Deployment aktual ke staging butuh infrastruktur/kredensial di luar repo, jadi ditandai belum selesai di §7. |
| Queue berjalan | Selesai | `QueueSmokeTest`; job `akunta:health --dispatch-queue-probe` lalu `queue:work --stop-when-empty` di job CI `integration` membuktikan job benar-benar dieksekusi worker |
| AI worker reachable | Selesai | `AiWorkerClient` + `/health` endpoint + `AiWorkerClientTest` (HTTP fake) + `test_health.py`; job CI `integration` memanggil worker yang benar-benar berjalan |
| Tests berjalan di CI | Selesai | `.github/workflows/ci.yml` menjalankan Pint, PHPStan, migration, Pest, ESLint, Prettier, tsc, Vite build, Ruff, dan pytest |

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
| Tenant isolation tested | Selesai | `TenantIsolationTest` (model, HTTP) dan `AccountingApiTest` (API v1) |

---

## 5. Phase 2 — COA & Accounting Foundation

Build items per `plan.md` §37 Phase 2.

| # | Item | Status |
| --- | --- | --- |
| 2.1 | Chart of accounts (`chart_of_accounts`) per `plan.md` §12.2 | Selesai |
| 2.2 | COA templates (`coa_templates`, `coa_template_accounts`) + seeder starter `plan.md` §12.1 | Selesai |
| 2.3 | Account types (asset, liability, equity, revenue, cost_of_sales, operating_expense, other_income, other_expense) | Selesai |
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
| Manual journal dapat dipost | Selesai | `JournalPostingTest` (service) dan `AccountingApiTest` (API v1) |
| Debit = credit enforced | Selesai | `JournalPostingTest` dan `JournalEntryDataTest` (service), `JournalLineConstraintTest` (CHECK constraint PostgreSQL) |
| Trial balance benar | Selesai | `TrialBalanceTest` dan `GoldenDatasetTest` (angka pembanding dikunci sebagai literal) |
| Closed period tidak dapat diubah | Selesai | `PeriodLockTest`, `JournalImmutabilityTest`, dan `AccountingApiTest` |

---

## 6. Phase 3 — Document Inbox

Build items per `plan.md` §37 Phase 3.

| # | Item | Status |
| --- | --- | --- |
| 3.1 | Upload multi-file + validasi MIME/ukuran + sanitasi nama berkas | Selesai |
| 3.2 | Object storage private per bisnis (`documents/{business_id}/...`) | Selesai |
| 3.3 | Processing status + state machine `plan.md` §25.1 | Selesai |
| 3.4 | Parser PDF (`pypdf`, teks per halaman, flag `needs_ocr`) | Selesai |
| 3.5 | Parser Excel (`openpyxl`, multi-sheet, presisi desimal dipertahankan) | Selesai |
| 3.6 | Parser CSV (deteksi delimiter + encoding) | Selesai |
| 3.7 | Parser JPG/PNG (`Pillow`, metadata dimensi; teksnya menunggu OCR Phase 4) | Selesai |
| 3.8 | Document viewer (halaman teks/tabel + timeline pemrosesan) | Selesai |
| 3.9 | Retry/reprocess dokumen gagal maupun terarsip | Selesai |
| 3.10 | Tabel `documents`, `document_files`, `document_pages`, `document_processing_jobs` (`plan.md` §23.1) | Selesai |
| 3.11 | Immutability berkas asli (partial unique index + guard model) per `plan.md` §44.7 | Selesai |
| 3.12 | Queue job idempotent + unik per dokumen + retry berjenjang | Selesai |
| 3.13 | Endpoint `/v1/parse` pada AI worker + internal token auth | Selesai |
| 3.14 | Signed URL download berumur pendek dengan fallback streaming (`plan.md` §30) | Selesai |
| 3.15 | Permission `document.view`, `document.upload`, `document.manage` | Selesai |
| 3.16 | Audit log upload, reprocess, archive, dan download (`plan.md` §31) | Selesai |
| 3.17 | API `/api/v1` documents (`plan.md` §29.3) | Selesai |
| 3.18 | UI Inertia: inbox dengan tab status, drag-and-drop upload, polling | Selesai |

Acceptance `plan.md` §37 Phase 3:

| Acceptance | Status | Bukti |
| --- | --- | --- |
| Multi-file upload berhasil | Selesai | `DocumentUploadTest` (satu dan banyak berkas, referensi berurutan per bisnis, penolakan tipe/ukuran/ekstensi yang dipalsukan) |
| Original file tetap tersimpan | Selesai | `DocumentFileImmutabilityTest` (update/delete ditolak model dan constraint DB, berkas asli utuh setelah beberapa kali reprocess) |
| Processing status real-time/polling | Selesai | `DocumentInboxTest` (status pipeline terekspos ke klien, tab tersaring di sisi server) + polling di `pages/documents/Index.tsx` |
| Failure dapat diretry | Selesai | `DocumentProcessingTest` (percobaan gagal dicatat lalu dilempar ulang ke queue, kegagalan permanen setelah percobaan habis, reprocess memakai berkas asli yang sama tanpa menduplikasi halaman) |

Catatan implementasi yang perlu diketahui saat melanjutkan ke Phase 4:

- Berkas dikirim ke worker sebagai multipart dari storage, bukan lewat path bersama,
  supaya worker tidak perlu kredensial object storage (diuji `DocumentProcessingTest`).
- Worker membedakan berkas tanpa parser (HTTP 415 → status `unsupported`) dari berkas
  rusak (HTTP 422 → gagal dan dapat diretry). Keduanya bukan kegagalan sistem.
- Kegagalan menghubungi worker dilempar ulang agar queue melakukan retry; dokumen baru
  ditandai `failed` setelah seluruh percobaan habis.
- Dokumen tidak pernah dapat dihapus. Yang tersedia hanya arsip, dan `DocumentPolicy`
  menutup jalur `delete` untuk semua role (`plan.md` §44.7).

---

## 7. Phase 4 — Document Intelligence

Build items per `plan.md` §37 Phase 4.

| # | Item | Status |
| --- | --- | --- |
| 4.1 | Abstraksi provider AI di worker (`AIProviderInterface`, registry, routing per task) — `plan.md` §13.3, §44.12 | Selesai |
| 4.2 | Provider heuristik berbasis aturan sebagai default tanpa kredensial vendor | Selesai |
| 4.3 | Provider OpenAI dengan JSON schema, pencatatan token dan biaya | Selesai |
| 4.4 | Prompt contract berversi untuk `classify_document` dan `extract_document` (`plan.md` §14, §45.8, §45.9) | Selesai |
| 4.5 | Taksonomi jenis dokumen `plan.md` §7.1 sebagai kontrak worker–Laravel | Selesai |
| 4.6 | Endpoint `/v1/classify`, `/v1/classify/taxonomy`, `/v1/extract` | Selesai |
| 4.7 | Extractor faktur, struk, rekening koran, dan settlement QRIS/e-wallet | Selesai |
| 4.8 | Parsing angka dan tanggal format Indonesia menjadi string desimal (`plan.md` §44.14) | Selesai |
| 4.9 | Tahap `classify` di Laravel: prediksi, alternatif, dan status dokumen | Selesai |
| 4.10 | Tahap `extract` di Laravel: field, baris mutasi, proyeksi canonical `plan.md` §8.1 | Selesai |
| 4.11 | Validator output independen di Laravel (bentuk nilai, kelengkapan canonical, aritmetika bcmath) — `plan.md` §44.2 | Selesai |
| 4.12 | Confidence engine `plan.md` §15 dengan ambang per bisnis (`business_profiles`) | Selesai |
| 4.13 | Tabel `ai_model_runs`, `ai_usage_logs`, `ai_predictions`, `ai_prediction_candidates`, `document_extractions`, `document_fields`, `ai_feedback` (`plan.md` §23.3, §23.6) | Selesai |
| 4.14 | Immutability prediksi dan sifat append-only `ai_feedback` (`plan.md` §31) | Selesai |
| 4.15 | Service review: konfirmasi jenis dokumen, koreksi field, persetujuan dokumen (`plan.md` §16, §17.1) | Selesai |
| 4.16 | Permission `document.review` + policy + endpoint web dan API v1 | Selesai |
| 4.17 | UI review: pilihan jenis dokumen, koreksi field, confidence badge, cuplikan sumber | Selesai |
| 4.18 | Rantai job `parse → classify → extract` dengan retry berjenjang dan unik per dokumen | Selesai |

Acceptance `plan.md` §37 Phase 4:

| Acceptance | Status | Bukti |
| --- | --- | --- |
| Output terstruktur tersimpan | Selesai | `DocumentExtractionTest` (field per kunci canonical, baris mutasi per `row_index`, kunci di luar taksonomi dibuang), `test_extract_api.py` |
| Confidence tersimpan | Selesai | `DocumentExtractionTest` (confidence per field, per ekstraksi, dan pada dokumen), `ConfidenceEngineTest` (agregasi komponen terlemah dan ambang per bisnis) |
| Raw + parsed evidence tersedia | Selesai | `DocumentExtractionTest` (`raw_output` tersimpan pada percobaan diterima maupun ditolak, `page_number` dan `source_text` per field), `DocumentClassificationTest` (raw output prediksi dan alternatifnya) |
| Output invalid tidak masuk transaction pipeline | Selesai | `DocumentExtractionTest` (verdict worker, aritmetika yang tidak konsisten, nilai uang tak terbaca, tanggal tak ada, field canonical hilang — semuanya nol `document_fields` dan `readyForTransactionPipeline()` kosong) |

Catatan implementasi yang perlu diketahui saat melanjutkan ke Phase 5:

- Verdict `valid` dari worker **tidak** dipakai sebagai izin masuk. Laravel memvalidasi
  ulang secara independen lewat `ExtractionValidator`, karena yang tersimpan di database
  adalah string yang dikirim worker, bukan objek Decimal di dalam prosesnya.
- Worker mengembalikan HTTP 200 dengan `valid: false` untuk kesalahan logis, dan 422
  hanya ketika output provider melanggar schema. Pembedaan itu menentukan apa yang
  diretry queue: 503 layak diretry, 415 dan 422 tidak.
- Confidence diagregasi dengan **minimum**, bukan rata-rata. Rata-rata membiarkan satu
  komponen yang sangat yakin menutupi komponen yang tidak yakin, dan `plan.md` §32.2
  menetapkan false auto approval harus sangat rendah.
- Pita `review_recommended` mengarah ke `need_review`, bukan ke `ready`. "Disarankan
  diperiksa" tetapi lolos tanpa diperiksa adalah kombinasi yang tidak berguna
  (`plan.md` §16.1).
- Koreksi reviewer melewati validator yang sama dengan output model. Yang dibedakan
  hanyalah confidence-nya.
- `document_fields` ditulis ulang setiap ekstraksi yang diterima. Buktinya tidak hilang:
  setiap percobaan tetap tersimpan di `document_extractions.raw_output`.

---

## 8. Testing Strategy Coverage (`plan.md` §33)

Unit test wajib per `plan.md` §33.1, dibatasi pada area yang masuk Phase 0–4:

| Area | Status |
| --- | --- |
| Accounting rules | Belum — rule engine adalah Phase 9 |
| Journal balancing | Selesai |
| Period lock | Selesai |
| COA behavior | Selesai |
| Report calculations | Sebagian — hanya trial balance (Phase 2). Income statement/balance sheet/cash flow adalah Phase 12 |
| Matching score | Belum — Phase 7 |
| Permissions | Selesai |
| Document extraction validation | Selesai — `ExtractionValidator` diuji lewat `DocumentExtractionTest` |
| Confidence engine | Selesai — `ConfidenceEngineTest` |

`plan.md` §33.3 Accounting Golden Dataset: tersedia versi manual-journal
(`GoldenDatasetTest` + `GoldenDatasetSeeder`) yang mencakup sales, purchases,
operating expenses, owner transactions, loans, receivable/payable payments, QRIS
settlement dengan MDR, dan internal bank transfer. Bagian dataset yang menuntut jurnal
dihasilkan dari dokumen belum dikerjakan karena pembuatan transaksinya masuk Phase 5+.

---

## 9. Item `plan.md` yang BELUM Dikerjakan

Sengaja tidak dikerjakan karena berada di luar Phase 0–4.

### Di luar phase (Phase 5 ke atas)

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
- `plan.md` §13.1 — tahap pipeline `normalize`, `entity_resolution`, `duplicate_check`,
  `event_classification`, `journal_generation`, dan `review_routing`. Yang dieksekusi
  sampai Phase 4 adalah `parse`, `classify`, dan `extract`.
- `plan.md` §14 — prompt contract `classify_economic_event`. Kontraknya sudah ada di
  worker sebagai antarmuka provider, tetapi belum dipakai karena economic event adalah
  Phase 8. `classify_document` dan `extract_document` sudah dipakai.
- `plan.md` §15 — komponen confidence `entity_confidence`, `duplicate_confidence`,
  `related_document_confidence`, `economic_event_confidence`, dan
  `account_mapping_confidence`. Engine-nya menerima komponen apa pun tanpa perubahan;
  yang belum ada adalah phase yang menghasilkannya.
- `plan.md` §16 — OCR untuk dokumen hasil pindaian. Halaman yang membutuhkannya sudah
  ditandai `needs_ocr` oleh parser PDF dan gambar, dan dokumennya tetap dapat dilengkapi
  lewat review manual. Vision model untuk membacanya belum dipasang.
- `plan.md` §17.2 — learning layer. `ai_feedback` sudah terisi setiap koreksi reviewer,
  tetapi belum ada kode yang membacanya sebagai masukan prediksi berikutnya.
- `plan.md` §18 — Duplicate vs related document.
- `plan.md` §22 — AI Financial Analyst insight cards.
- `plan.md` §32 — agregasi observability metrics & AI quality metrics. Bahannya sudah
  lengkap: durasi tiap tahap di `document_processing_jobs`, biaya per dokumen di
  `ai_usage_logs`, prediksi di `ai_predictions`, dan koreksi di `ai_feedback`. Yang belum
  dibuat adalah query/dashboard yang menjumlahkannya.
- `plan.md` §34 — Sample dataset 3 tipe bisnis berbasis dokumen nyata.
- `plan.md` §36 — Dashboard MVP angka finansial (butuh posted journal dari pipeline AI).
- `plan.md` §42, §43 — Future integrations & product evolution.
- `plan.md` §47 — UAT checklist end-to-end.

Tabel `plan.md` §23 yang belum dibuat karena milik phase berikutnya:
`entities`, `entity_aliases`, `entity_identifiers`, `entity_relationships`,
`transactions`, `transaction_sources`, `transaction_evidence`, `transaction_relations`,
`transaction_tags`, `economic_event_types`, `economic_event_predictions`,
`accounting_rules`, `accounting_rule_lines`, `review_tasks`, `review_actions`,
`review_comments`, `reconciliations`, `reconciliation_items`, `reconciliation_matches`,
`closing_periods`, `closing_checklists`, `closing_issues`.

Catatan: `bank_accounts` (`plan.md` §23.2) **sudah** dibuat pada Phase 1 karena menjadi
bagian onboarding bisnis di `plan.md` §5.1. `documents`, `document_files`,
`document_pages`, dan `document_processing_jobs` dibuat pada Phase 3.
`document_extractions`, `document_fields`, `ai_model_runs`, `ai_usage_logs`,
`ai_predictions`, `ai_prediction_candidates`, dan `ai_feedback` dibuat pada Phase 4.

### Dalam scope Phase 0–4 tetapi butuh infrastruktur eksternal

- `plan.md` §37 Phase 0 — deploy aktual ke staging. Repo sudah menyediakan Docker image,
  compose, dan CI, tetapi eksekusi deployment butuh host/registry/kredensial.
- `plan.md` §30 — malware scanning dokumen yang diunggah. Validasi MIME berbasis isi
  berkas dan sanitasi nama sudah ada, tetapi pemindaian antivirus butuh layanan eksternal.
- `plan.md` §30 — backup + restore test dan secret manager produksi. Rate limiting,
  private storage, signed URL, dan audit log sudah ada di kode; sisanya adalah tugas
  platform/operasional.
- `plan.md` §41 — daily PostgreSQL backup dan object storage versioning (operasional).

---

## 10. Verifikasi Iterasi Ini

Perintah di bawah dijalankan pada commit terakhir branch ini, terhadap PostgreSQL 16 dan
Redis 7 yang benar-benar berjalan.

| Perintah | Hasil |
| --- | --- |
| `php artisan migrate:fresh --seed` | Seluruh 28 migration dan kedua seeder jalan tanpa error |
| `php artisan test` | 325 test, 1557 assertion, seluruhnya lulus |
| `vendor/bin/pint --test` | Lolos |
| `vendor/bin/phpstan analyse` | Level 6, tanpa error |
| `npm run lint` / `format:check` / `types` / `build` | Seluruhnya lolos |
| `ruff check .` / `ruff format --check .` / `pytest -q` | Lolos; 138 test worker lulus |
| `php artisan akunta:health --dispatch-queue-probe` | database, cache, object_storage, ai_worker, dan queue berstatus OK |
| `php artisan queue:work --stop-when-empty` | `QueueHeartbeatJob` dieksekusi sampai selesai |

Distribusi test:

| Area | Berkas |
| --- | --- |
| Phase 0 | `MoneyTest`, `AccountingEnumTest`, `QueueSmokeTest`, `AiWorkerClientTest`, `HealthCheckCommandTest` |
| Phase 1 | `AuthenticationTest`, `BusinessOnboardingTest`, `MembershipTest`, `RolePermissionTest`, `MultiBusinessTest`, `AccountantMultiClientTest`, `TenantIsolationTest`, `AuditTrailTest` |
| Phase 2 | `ChartOfAccountsTest`, `JournalEntryDataTest`, `JournalPostingTest`, `JournalLineConstraintTest`, `JournalImmutabilityTest`, `PeriodLockTest`, `TrialBalanceTest`, `GoldenDatasetTest`, `AccountingApiTest` |
| Phase 3 | `DocumentEnumTest`, `DocumentUploadTest`, `DocumentProcessingTest`, `DocumentInboxTest`, `DocumentFileImmutabilityTest`, `DocumentIsolationTest`, `DocumentAuditTest` |
| Phase 3 (worker) | `test_parsers.py`, `test_parse_api.py`, `test_health.py` |
| Phase 4 | `DocumentClassificationTest`, `DocumentExtractionTest`, `DocumentReviewTest`, `ConfidenceEngineTest`, tambahan pada `DocumentEnumTest` |
| Phase 4 (worker) | `test_classifier.py`, `test_extractors.py`, `test_intelligence_api.py`, `test_providers.py`, `test_numbers.py` |

Catatan koreksi accounting yang muncul dari test Phase 2: trial balance semula hanya
membaca `journal_entries.status = 'posted'`, sehingga sebuah reversal entry ikut terhitung
tanpa entry aslinya dan membalik saldo akun. Laporan kini membaca
`JournalEntryStatus::ledgerStatuses()` (`posted` dan `reversed`), sesuai `plan.md` §44.6
yang mewajibkan koreksi lewat reversal entry, bukan lewat penghapusan entry asli.

Koreksi yang muncul dari test Phase 3:

- `DocumentProcessingService::queue()` semula menilai transisi state machine terhadap
  salinan model di memori. Karena status dokumen berubah di queue worker, dokumen yang
  sudah ditandai gagal permanen dapat gagal keluar dari state `failed` saat diproses
  ulang. Model kini disegarkan lebih dulu.
- Timeline pemrosesan pada viewer semula diurutkan menurut `created_at`. Tahap-tahap
  phase berikutnya dicatat sebagai `pending` di dalam satu transaksi, sehingga timestamp
  tidak dapat membedakan urutannya. Urutan sekarang mengikuti urutan pipeline
  `plan.md` §13.1.

Koreksi yang muncul dari test Phase 4:

- `Document::acceptedExtraction()` semula memakai `latestOfMany('attempt')`. Eloquent
  selalu menambahkan primary key sebagai pemecah seri pada relasi one-of-many, dan
  `MAX(uuid)` tidak ada di PostgreSQL, sehingga setiap tindakan review gagal dengan error
  SQL. Relasinya kini diurutkan menurut `attempt`; pemecah seri tidak diperlukan karena
  pasangan `(document_id, attempt)` sudah unik.
- `normalize_amount` pada worker semula memotong "1500" menjadi "150" karena cabang regex
  berpemisah ribuan menang lebih dulu. Cabang itu kini mewajibkan pemisahnya benar-benar
  ada.

Test Laravel tidak pernah memanggil worker Python: respons `/v1/parse`, `/v1/classify`,
dan `/v1/extract` di-fake lewat `Tests\Support\UploadsDocuments`, sedangkan parser,
classifier, dan extractor aslinya diuji pytest di `ai-worker`. Pemisahan ini menjaga test
Laravel tetap deterministik dan tidak bergantung pada proses eksternal maupun pada
provider AI.

`tests/Feature` tidak dianalisis PHPStan karena Pest mem-bind `$this` di dalam closure
saat runtime; hal itu didokumentasikan di `phpstan.neon`.
