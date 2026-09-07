# Akunta

AI Accounting Workspace untuk UMKM Indonesia. Pemilik usaha mengunggah bukti transaksi,
Akunta menghasilkan pembukuan double-entry yang benar, dan akuntan memverifikasinya.

`plan.md` adalah source of truth untuk produk ini. Status implementasi per phase dicatat
di [`docs/IMPLEMENTATION_CHECKLIST.md`](docs/IMPLEMENTATION_CHECKLIST.md).

**Status saat ini: Phase 0–10 selesai.** Fondasinya multi-tenant, accounting-safe, punya
source document yang immutable dan queue processing, membaca isi dokumen menjadi field
terstruktur, menormalisasi transaksi canonical, meresolusi pihak lawan, mendeteksi
duplikat/dokumen terkait, mengklasifikasi peristiwa ekonomi, mengusulkan jurnal draft
dari accounting rule, dan meninjau transaksi di Review Center. Menyetujui transaksi tidak
memposting jurnal (`plan.md` §15.2).

Phase 11 ke atas belum dikerjakan: rekonsiliasi, laporan lengkap, closing, dan AI analyst.

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
app/Domain/          Model, enum, dan value object per domain (Accounting, Business, Documents, Transactions, Review, Tenancy, Audit)
app/Services/        Logika bisnis: posting journal, provisioning, pemrosesan dokumen, normalisasi, entity, matching, economic event, accounting rules, review
app/Jobs/            Queue job, termasuk pipeline pemrosesan dokumen
app/Http/            Controller web (Inertia) dan API v1, middleware, form request, presenter
ai-worker/           FastAPI worker: parser, classifier dokumen, extractor, klasifikasi peristiwa ekonomi
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
- Output model tidak pernah masuk ke data akuntansi tanpa melewati lapisan aturan.
  Ekstraksi yang gagal validasi tersimpan sebagai bukti, tetapi tidak menghasilkan satu
  pun nilai yang dapat terbaca sebagai data sah.
- Setiap nilai hasil ekstraksi membawa halaman dan cuplikan teks sumbernya, sehingga
  reviewer dapat memeriksanya alih-alih hanya mempercayainya.
- Pernyataan manusia mengalahkan prediksi AI, dan prediksi yang dikalahkan tidak dihapus:
  ia menjadi bahan pengukuran akurasi.
- Setiap transaksi menunjuk dokumen, baris, dan halaman asalnya. Tidak ada transaksi yang
  muncul tanpa berkas yang dapat dibuka user.
- Sebuah dokumen menghasilkan seluruh transaksinya atau tidak menghasilkan satu pun.
  Rekening koran yang satu barisnya tidak konsisten dikembalikan ke review, bukan dipakai
  separuh.
- Nominal transaksi selalu positif; arah masuk atau keluarnya yang membawa tanda.
- Nomor transaksi hanya maju. Normalisasi ulang tidak pernah memakai kembali nomor yang
  sudah pernah dilihat user untuk isi yang berbeda, dan tidak menimpa transaksi yang sudah
  disetujui, diposting, atau ditolak.

## Pipeline dokumen

Upload tidak memparse apa pun di dalam request: berkas asli disimpan lebih dulu, lalu
`ProcessDocumentJob` mengantre. Job mengirim berkas ke AI worker sebagai multipart,
sehingga worker tidak perlu kredensial object storage.

Tahapnya berurutan `parse → classify → extract → normalize`, masing-masing satu job
tersendiri.
Pemisahan itu membuat kesalahan dapat dilokalisasi: dokumen yang salah dikenali jenisnya
terlihat berbeda dari dokumen yang jenisnya benar tetapi angkanya salah baca.

**Parse.** Worker memilih parser berdasarkan ekstensi dan MIME (`pypdf`, `openpyxl`, CSV,
`Pillow`) lalu mengembalikan teks atau baris tabel per halaman. Halaman hasil pindaian
ditandai `needs_ocr`. Berkas tanpa parser (HTTP 415) dan berkas rusak (HTTP 422)
dibedakan: yang pertama menjadi status `unsupported`, yang kedua gagal dan dapat diproses
ulang.

**Classify.** Halaman hasil parse — bukan berkasnya lagi — dikirim ke worker untuk
menentukan jenis dokumen beserta confidence dan alternatifnya. Jenis dokumen yang sudah
dinyatakan manusia tidak ditimpa; prediksinya tetap disimpan berstatus `superseded`.

**Extract.** Worker mengembalikan field terstruktur beserta halaman dan cuplikan
sumbernya. Laravel memvalidasinya ulang secara independen: bentuk nilai, kelengkapan
proyeksi canonical, dan identitas aritmetika dokumen (subtotal + pajak = total, bruto −
MDR = neto, saldo awal + kredit − debit = saldo akhir) dihitung ulang dengan BCMath.
Ekstraksi yang lolos menghasilkan `document_fields`; yang tidak lolos tersimpan sebagai
percobaan `rejected` lengkap dengan raw output dan alasannya.

Confidence engine kemudian mengambil komponen terlemah — bukan rata-rata — dan
membandingkannya terhadap ambang yang dapat diatur per bisnis. Hanya dokumen di pita
`ready` yang lanjut tanpa manusia; sisanya masuk antrean review, tempat reviewer
menetapkan jenis dokumen, mengoreksi field, atau menyetujui dokumen. Setiap koreksi
tercatat di `ai_feedback`, dan setiap panggilan provider tercatat di `ai_model_runs`
beserta biayanya di `ai_usage_logs`.

**Normalize.** Tahap ini deterministik dan tidak memanggil AI: ia membaca
`document_fields` milik ekstraksi yang diterima lalu membentuk transaksi canonical. Satu
rekening koran menghasilkan satu transaksi per baris mutasi, satu faktur atau struk
menghasilkan satu transaksi, dan settlement QRIS menghasilkan satu transaksi bernominal
neto dengan bruto serta MDR menempel sebagai bukti. Setiap transaksi menyimpan penunjuk ke
dokumen, ekstraksi, baris, dan halaman asalnya, sehingga user dapat berjalan dua arah
antara berkas dan transaksinya.

Setiap percobaan tercatat di `document_processing_jobs` beserta durasi dan pesan
kesalahannya, sehingga status di inbox dapat dipolling dan kegagalan dapat diusut.

Provider AI berada di balik abstraksi di dalam worker. Default-nya heuristik berbasis
aturan sehingga seluruh pipeline dapat dijalankan tanpa kredensial vendor; provider
OpenAI tersedia lewat konfigurasi, dan routing-nya dapat berbeda per task.

## Lisensi

Proprietary.
