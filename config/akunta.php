<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Currency & Money
    |--------------------------------------------------------------------------
    |
    | plan.md §44.14 melarang penggunaan floating point untuk uang. Seluruh nilai
    | uang disimpan sebagai NUMERIC(20,2) di PostgreSQL dan dioperasikan lewat
    | App\Support\Money yang memakai BCMath.
    |
    */
    'money' => [
        'scale' => 2,
        'default_currency' => env('AKUNTA_DEFAULT_CURRENCY', 'IDR'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Accounting
    |--------------------------------------------------------------------------
    |
    | plan.md §2.2: basis akrual sebagai default, periode akuntansi bulanan.
    |
    */
    'accounting' => [
        'default_basis' => 'accrual',
        'default_period_length' => 'monthly',
        'journal_entry_number_prefix' => 'JE',
    ],

    /*
    |--------------------------------------------------------------------------
    | Confidence Engine
    |--------------------------------------------------------------------------
    |
    | plan.md §15.2. Nilainya string, bukan float: perbandingan terhadap ambang
    | dilakukan pada desimal keempat dengan bcmath, dan pembulatan float dapat
    | memindahkan sebuah nilai melintasi ambang. Bisnis dapat menimpa keduanya lewat
    | business_profiles, sesuai §15.2 "configurable per business".
    |
    */
    'confidence' => [
        'auto_ready' => (string) env('ACCOUNTING_AUTO_READY_THRESHOLD', '0.95'),
        'review_recommended' => (string) env('ACCOUNTING_REVIEW_THRESHOLD', '0.80'),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Worker
    |--------------------------------------------------------------------------
    |
    | plan.md §26: Python AI Worker berjalan sebagai service terpisah yang diakses
    | Laravel melalui HTTP.
    |
    */
    'ai_worker' => [
        'url' => env('AI_WORKER_URL', 'http://127.0.0.1:8001'),
        'token' => env('AI_WORKER_TOKEN'),
        'timeout' => (int) env('AI_WORKER_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Document Storage
    |--------------------------------------------------------------------------
    |
    | plan.md §30: private object storage + signed URL. Upload sendiri baru
    | dikerjakan pada Phase 3.
    |
    */
    'documents' => [
        'disk' => env('DOCUMENT_DISK', 'documents'),
        'signed_url_ttl' => (int) env('DOCUMENT_SIGNED_URL_TTL', 300),
        'max_upload_size_kb' => (int) env('DOCUMENT_MAX_UPLOAD_SIZE_KB', 25600),
        'allowed_mime_types' => [
            'application/pdf',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/csv',
            'text/plain',
            'image/jpeg',
            'image/png',
            'application/zip',
        ],
    ],
];
