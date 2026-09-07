# Akunta

AI Accounting Workspace untuk UMKM Indonesia. Pemilik usaha mengunggah bukti transaksi,
Akunta menghasilkan pembukuan double-entry yang benar, dan akuntan memverifikasinya.

`plan.md` adalah source of truth untuk produk ini. Status implementasi per phase dicatat
di [`docs/IMPLEMENTATION_CHECKLIST.md`](docs/IMPLEMENTATION_CHECKLIST.md).

**Status saat ini: Phase 0, 1, 2, dan 3 selesai.** Fondasinya kini multi-tenant,
accounting-safe, punya source document yang immutable, dan punya queue processing —
target pertama pada `plan.md` §48.

Phase 4 ke atas belum dikerjakan. Batasnya: Phase 3 mengubah berkas menjadi teks dan
tabel per halaman secara deterministik, sedangkan **menafsirkan** isinya (menentukan
jenis dokumen, mengekstraksi field, memberi confidence) adalah Phase 4. Karena itu
dokumen berhenti di status `classifying` setelah parse berhasil, dan `document_type`
hanya terisi bila user menyebutkannya saat upload.

## Arsitektur

| Lapisan | Teknologi |
| --- | --- |
| Aplikasi | Laravel 12 (PHP 8.3) |
| Frontend | Inertia.js + React 19 + TypeScript + Tailwind CSS 4 |
| Database | PostgreSQL 16 |
| Queue & cache | Redis 7 |
| Object storage | S3-compatible (MinIO untuk lokal), disk private |
| AI worker | Python 3.12 + FastAPI (`ai-worker/`) |

PostgreSQL bukan pilihan bebas: sebagian invariant akuntansi ditegakkan sebagai CHECK
constraint dan partial unique index, sehingga test suite pun berjalan di PostgreSQL, bukan
SQLite.

### Struktur direktori

```
app/Domain/          Model, enum, dan value object per domain (Accounting, Business, Documents, Tenancy, Audit)
app/Services/        Logika bisnis: posting journal, provisioning bisnis, pemrosesan dokumen, audit logger
app/Jobs/            Queue job, termasuk pipeline pemrosesan dokumen
app/Http/            Controller web (Inertia) dan API v1, middleware, form request, presenter
ai-worker/           FastAPI worker: parser dokumen deterministik dan abstraksi provider AI
resources/js/        Halaman, layout, dan komponen React
database/migrations Schema PostgreSQL
tests/               Pest: Unit dan Feature
docs/                Checklist implementasi
```

## Menjalankan dengan Docker

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

Aplikasi tersedia di `http://localhost:8000`, Vite di `http://localhost:5173`, konsol
MinIO di `http://localhost:9001`, dan AI worker di `http://localhost:8001/health`.

## Menjalankan tanpa Docker

Prasyarat: PHP 8.3 dengan ekstensi `bcmath`, `intl`, `pdo_pgsql`, dan `redis`; Composer;
Node.js 22; Python 3.12; PostgreSQL 16; Redis 7.

Ekstensi `bcmath` wajib ada. Seluruh aritmetika uang memakainya karena nilai uang
diperlakukan sebagai string desimal, bukan float (`plan.md` §44.14).

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate

createdb akunta && createdb akunta_testing
php artisan migrate --seed

npm run dev
php artisan serve
php artisan queue:work

cd ai-worker
python -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt -r requirements-dev.txt
uvicorn app.main:app --port 8001
```

Periksa kesiapan seluruh dependensi:

```bash
php artisan akunta:health --dispatch-queue-probe
```

## Test dan lint

```bash
php artisan test                    # Pest: unit + feature
composer lint                       # Pint + PHPStan level 6
npm run lint && npm run types       # ESLint + tsc
npm run format:check                # Prettier
cd ai-worker && ruff check . && pytest -q
```

Test suite memakai database `akunta_testing` (lihat `phpunit.xml`). CI menjalankan
seluruh perintah di atas plus satu job integrasi yang memverifikasi health check, queue,
dan AI worker terhadap service yang benar-benar berjalan.

## Prinsip yang dijaga oleh test

Aturan berikut ada di `plan.md` §44 dan §45, dan setiap aturan punya test yang menjaganya:

- Uang tidak pernah melewati float. Kolom bertipe `NUMERIC(20,2)`, PHP memakai BCMath
  lewat `App\Support\Money`, dan frontend menerimanya sebagai string.
- Setiap journal entry harus seimbang. Dijaga di service maupun oleh CHECK constraint di
  level database.
- Posted journal bersifat immutable. Koreksi hanya lewat reversal entry, tidak dengan
  mengubah atau menghapus entry aslinya.
- Periode yang sudah ditutup tidak menerima aktivitas journal, dan reopen wajib beralasan
  serta tercatat di audit log.
- Data selalu terikat pada satu tenant. Global scope, middleware, dan policy memastikan
  data bisnis lain tidak pernah terbaca.
- Laporan hanya dihitung dari journal yang sudah masuk ledger, bukan dari raw transaction.
- Berkas dokumen asli bersifat immutable dan tidak dapat dihapus. Reprocess selalu
  membaca berkas yang sama, dan dokumen hanya dapat diarsipkan.
- Dokumen disimpan di storage private yang dipartisi per bisnis, dan diakses lewat
  signed URL berumur pendek.

## Pipeline dokumen

Upload tidak memparse apa pun di dalam request: berkas asli disimpan lebih dulu, lalu
`ProcessDocumentJob` mengantre. Job mengirim berkas ke AI worker sebagai multipart,
sehingga worker tidak perlu kredensial object storage.

Worker memilih parser berdasarkan ekstensi dan MIME (`pypdf`, `openpyxl`, CSV, `Pillow`)
lalu mengembalikan teks atau baris tabel per halaman. Halaman hasil pindaian ditandai
`needs_ocr` agar Phase 4 tahu mana yang perlu OCR. Berkas tanpa parser (HTTP 415) dan
berkas rusak (HTTP 422) dibedakan: yang pertama menjadi status `unsupported`, yang kedua
gagal dan dapat diproses ulang.

Setiap percobaan tercatat di `document_processing_jobs` beserta durasi dan pesan
kesalahannya, sehingga status di inbox dapat dipolling dan kegagalan dapat diusut.

## Lisensi

Proprietary.
