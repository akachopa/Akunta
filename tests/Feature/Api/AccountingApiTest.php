<?php

declare(strict_types=1);

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\JournalEntryStatus;
use App\Domain\Business\Enums\RoleSlug;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/*
 * plan.md §29: API surface v1. Phase 1–2 hanya mencakup auth, businesses, accounts,
 * journals, periods, dan trial balance. Endpoint documents, transactions, review,
 * reconciliation, dan AI analyst (§29.3–§29.5, §29.8, §29.10) belum ada.
 *
 * plan.md §30 mewajibkan seluruh endpoint tenant-scoped, sehingga setiap kelompok
 * endpoint di sini diuji juga terhadap akses lintas tenant.
 */

beforeEach(function (): void {
    [$this->owner, $this->business] = $this->provisionBusinessWithOwner('Toko API');
    $this->accountant = $this->addMember($this->business, RoleSlug::Accountant);
    $this->staff = $this->addMember($this->business, RoleSlug::BusinessStaff);
});

/**
 * @return array<int, array<string, string>>
 */
function apiLines(string $debitId, string $creditId, string $amount): array
{
    return [
        ['account_id' => $debitId, 'debit' => $amount],
        ['account_id' => $creditId, 'credit' => $amount],
    ];
}

it('menerbitkan token dan menolak kredensial salah', function (): void {
    $user = User::factory()->create(['email' => 'api@akunta.test', 'password' => 'rahasia-kuat']);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'api@akunta.test',
        'password' => 'rahasia-kuat',
        'device_name' => 'pest',
    ])
        ->assertOk()
        ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'businesses']]);

    expect($user->refresh()->tokens()->count())->toBe(1);
    expect($user->last_login_at)->not->toBeNull();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'api@akunta.test',
        'password' => 'salah',
    ])->assertStatus(422);
});

it('menolak endpoint terproteksi tanpa token', function (): void {
    $this->getJson('/api/v1/me')->assertUnauthorized();
    $this->getJson('/api/v1/businesses')->assertUnauthorized();
    $this->getJson("/api/v1/businesses/{$this->business->getKey()}/accounts")->assertUnauthorized();
});

it('mengembalikan profil dan daftar bisnis milik user', function (): void {
    Sanctum::actingAs($this->accountant);

    $this->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.email', $this->accountant->email)
        ->assertJsonPath('data.businesses.0.id', $this->business->getKey())
        ->assertJsonPath('data.businesses.0.role', RoleSlug::Accountant->value);
});

it('mencabut token saat logout', function (): void {
    $token = $this->accountant->createToken('pest')->plainTextToken;

    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

    expect($this->accountant->refresh()->tokens()->count())->toBe(0);

    // Guard menyimpan user hasil resolusi request sebelumnya; lupakan dulu supaya
    // request berikutnya benar-benar memvalidasi token yang sudah dicabut.
    app('auth')->forgetGuards();

    $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
});

it('membuat bisnis lengkap dengan starter coa lewat api', function (): void {
    Sanctum::actingAs($this->owner);

    $response = $this->postJson('/api/v1/businesses', [
        'name' => 'Warung Kedua',
        'business_type' => 'restaurant',
        'opening_date' => '2026-01-01',
    ])->assertCreated();

    $businessId = $response->json('data.id');

    $accounts = $this->getJson("/api/v1/businesses/{$businessId}/accounts")->assertOk()->json('data');

    expect(collect($accounts)->pluck('code'))->toContain('1101', '4100', '6100');

    $this->getJson("/api/v1/businesses/{$businessId}/periods")
        ->assertOk()
        ->assertJsonPath('data.0.status', 'open');
});

it('membatasi bisnis yang terlihat pada pemiliknya', function (): void {
    [$lain, $businessLain] = $this->provisionBusinessWithOwner('Bisnis Lain');

    Sanctum::actingAs($this->owner);

    $this->getJson('/api/v1/businesses')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->business->getKey());

    $this->getJson("/api/v1/businesses/{$businessLain->getKey()}")->assertNotFound();
    $this->getJson("/api/v1/businesses/{$businessLain->getKey()}/accounts")->assertNotFound();
    $this->getJson("/api/v1/businesses/{$businessLain->getKey()}/journals")->assertNotFound();
    $this->getJson("/api/v1/businesses/{$businessLain->getKey()}/reports/trial-balance")->assertNotFound();
});

it('membuat akun coa lewat api dan menolak kode duplikat', function (): void {
    Sanctum::actingAs($this->accountant);

    $this->postJson("/api/v1/businesses/{$this->business->getKey()}/accounts", [
        'code' => '6950',
        'name' => 'Beban Lain-lain',
        'account_type' => AccountType::OperatingExpense->value,
    ])
        ->assertCreated()
        ->assertJsonPath('data.normal_balance', 'debit')
        ->assertJsonPath('data.is_system', false);

    $this->postJson("/api/v1/businesses/{$this->business->getKey()}/accounts", [
        'code' => '6950',
        'name' => 'Duplikat',
        'account_type' => AccountType::OperatingExpense->value,
    ])->assertStatus(422)->assertJsonValidationErrors('code');
});

it('menolak staff membuat akun coa', function (): void {
    Sanctum::actingAs($this->staff);

    $this->postJson("/api/v1/businesses/{$this->business->getKey()}/accounts", [
        'code' => '6951',
        'name' => 'Tidak Boleh',
        'account_type' => AccountType::OperatingExpense->value,
    ])->assertForbidden();
});

it('memfilter akun ke yang dapat diposting', function (): void {
    Sanctum::actingAs($this->accountant);

    $all = $this->getJson("/api/v1/businesses/{$this->business->getKey()}/accounts")->json('data');
    $postable = $this->getJson("/api/v1/businesses/{$this->business->getKey()}/accounts?postable_only=1")
        ->json('data');

    expect(count($postable))->toBeLessThan(count($all));
    expect(collect($postable)->every(fn (array $row): bool => $row['is_postable'] === true))->toBeTrue();
});

it('membuat draft journal lewat api', function (): void {
    Sanctum::actingAs($this->accountant);

    $response = $this->postJson("/api/v1/businesses/{$this->business->getKey()}/journals", [
        'entry_date' => '2026-01-15',
        'description' => 'Penjualan tunai',
        'lines' => apiLines(
            $this->account($this->business, '1102')->getKey(),
            $this->account($this->business, '4100')->getKey(),
            '1500000'
        ),
    ])->assertCreated();

    $response
        ->assertJsonPath('data.status', JournalEntryStatus::Draft->value)
        ->assertJsonPath('data.total_debit', '1500000.00')
        ->assertJsonPath('data.total_credit', '1500000.00')
        ->assertJsonPath('data.is_balanced', true)
        ->assertJsonCount(2, 'data.lines');

    expect($response->json('data.entry_number'))->toStartWith('JE-202601-');
});

it('membuat dan langsung memposting journal lewat api', function (): void {
    Sanctum::actingAs($this->accountant);

    $this->postJson("/api/v1/businesses/{$this->business->getKey()}/journals", [
        'entry_date' => '2026-01-15',
        'description' => 'Penjualan tunai langsung post',
        'post' => true,
        'lines' => apiLines(
            $this->account($this->business, '1102')->getKey(),
            $this->account($this->business, '4100')->getKey(),
            '2000000'
        ),
    ])
        ->assertCreated()
        ->assertJsonPath('data.status', JournalEntryStatus::Posted->value);

    $this->getJson("/api/v1/businesses/{$this->business->getKey()}/reports/trial-balance")
        ->assertOk()
        ->assertJsonPath('data.total_debit', '2000000.00')
        ->assertJsonPath('data.is_balanced', true);
});

it('menolak journal tidak seimbang lewat api', function (): void {
    Sanctum::actingAs($this->accountant);

    $this->postJson("/api/v1/businesses/{$this->business->getKey()}/journals", [
        'entry_date' => '2026-01-15',
        'description' => 'Tidak seimbang',
        'lines' => [
            ['account_id' => $this->account($this->business, '1102')->getKey(), 'debit' => '1000000'],
            ['account_id' => $this->account($this->business, '4100')->getKey(), 'credit' => '900000'],
        ],
    ])->assertStatus(422);
});

it('menolak nilai uang berformat float pada api', function (): void {
    Sanctum::actingAs($this->accountant);

    $this->postJson("/api/v1/businesses/{$this->business->getKey()}/journals", [
        'entry_date' => '2026-01-15',
        'description' => 'Presisi berlebih',
        'lines' => [
            ['account_id' => $this->account($this->business, '1102')->getKey(), 'debit' => '1000.001'],
            ['account_id' => $this->account($this->business, '4100')->getKey(), 'credit' => '1000.001'],
        ],
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('lines.0.debit');
});

it('mewajibkan journal memiliki minimal dua baris', function (): void {
    Sanctum::actingAs($this->accountant);

    $this->postJson("/api/v1/businesses/{$this->business->getKey()}/journals", [
        'entry_date' => '2026-01-15',
        'description' => 'Satu baris',
        'lines' => [
            ['account_id' => $this->account($this->business, '1102')->getKey(), 'debit' => '1000'],
        ],
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('lines');
});

it('menolak baris journal yang merujuk akun bisnis lain', function (): void {
    [, $businessLain] = $this->provisionBusinessWithOwner('Bisnis Lain');

    Sanctum::actingAs($this->accountant);

    $this->postJson("/api/v1/businesses/{$this->business->getKey()}/journals", [
        'entry_date' => '2026-01-15',
        'description' => 'Akun lintas tenant',
        'lines' => apiLines(
            $this->account($businessLain, '1102')->getKey(),
            $this->account($this->business, '4100')->getKey(),
            '1000000'
        ),
    ])->assertStatus(422);
});

it('menjalankan alur approve lalu post lewat api', function (): void {
    Sanctum::actingAs($this->accountant);

    $entryId = $this->postJson("/api/v1/businesses/{$this->business->getKey()}/journals", [
        'entry_date' => '2026-01-15',
        'description' => 'Alur approval',
        'lines' => apiLines(
            $this->account($this->business, '1102')->getKey(),
            $this->account($this->business, '4100')->getKey(),
            '1000000'
        ),
    ])->assertCreated()->json('data.id');

    $this->postJson("/api/v1/journals/{$entryId}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', JournalEntryStatus::Approved->value);

    $this->postJson("/api/v1/journals/{$entryId}/post")
        ->assertOk()
        ->assertJsonPath('data.status', JournalEntryStatus::Posted->value);

    // plan.md §44.6: posted journal tidak boleh diposting ulang atau di-approve lagi.
    $this->postJson("/api/v1/journals/{$entryId}/approve")->assertStatus(422);
});

it('membalik posted journal lewat api dan menolak alasan kosong', function (): void {
    Sanctum::actingAs($this->accountant);

    $entry = $this->postSimpleJournal($this->business, $this->accountant, '1102', '4100', '3000000');

    $this->postJson("/api/v1/journals/{$entry->getKey()}/reverse")
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');

    $reversal = $this->postJson("/api/v1/journals/{$entry->getKey()}/reverse", [
        'reason' => 'Salah pencatatan',
    ])
        ->assertCreated()
        ->assertJsonPath('data.status', JournalEntryStatus::Posted->value)
        ->assertJsonPath('data.reversal_of_id', $entry->getKey());

    $this->getJson("/api/v1/journals/{$entry->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.status', JournalEntryStatus::Reversed->value)
        ->assertJsonPath('data.reversed_by_entry_id', $reversal->json('data.id'));

    $this->getJson("/api/v1/businesses/{$this->business->getKey()}/reports/trial-balance")
        ->assertOk()
        ->assertJsonPath('data.total_debit', '0.00')
        ->assertJsonPath('data.is_balanced', true);
});

it('menolak staff memposting dan membalik journal', function (): void {
    $entry = $this->postSimpleJournal($this->business, $this->accountant, '1102', '4100', '1000000');

    Sanctum::actingAs($this->staff);

    $this->postJson("/api/v1/journals/{$entry->getKey()}/reverse", ['reason' => 'Coba-coba'])
        ->assertForbidden();
});

it('tidak membocorkan journal bisnis lain lewat api', function (): void {
    [, $businessLain] = $this->provisionBusinessWithOwner('Bisnis Lain');
    $akuntanLain = $this->addMember($businessLain, RoleSlug::Accountant);
    $entryLain = $this->postSimpleJournal($businessLain, $akuntanLain, '1102', '4100', '9999999');

    Sanctum::actingAs($this->accountant);

    $this->getJson("/api/v1/journals/{$entryLain->getKey()}")->assertNotFound();
    $this->postJson("/api/v1/journals/{$entryLain->getKey()}/reverse", ['reason' => 'Bukan milik saya'])
        ->assertNotFound();
});

it('memberi paginasi pada daftar journal', function (): void {
    for ($i = 0; $i < 5; $i++) {
        $this->postSimpleJournal($this->business, $this->accountant, '1102', '4100', '100000');
    }

    Sanctum::actingAs($this->accountant);

    $this->getJson("/api/v1/businesses/{$this->business->getKey()}/journals?per_page=2")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 5)
        ->assertJsonPath('meta.last_page', 3);

    $this->getJson("/api/v1/businesses/{$this->business->getKey()}/journals?status=draft")
        ->assertOk()
        ->assertJsonPath('meta.total', 0);
});

it('menutup dan membuka kembali periode lewat api', function (): void {
    $period = $this->periodFor($this->business, '2026-01-15');

    Sanctum::actingAs($this->owner);

    $this->postJson("/api/v1/periods/{$period->getKey()}/close", ['reason' => 'Tutup buku Januari'])
        ->assertOk()
        ->assertJsonPath('data.status', 'closed');

    // plan.md §44.10: journal tidak boleh masuk ke periode yang sudah ditutup.
    Sanctum::actingAs($this->accountant);
    $this->postJson("/api/v1/businesses/{$this->business->getKey()}/journals", [
        'entry_date' => '2026-01-15',
        'description' => 'Masuk periode tertutup',
        'post' => true,
        'lines' => apiLines(
            $this->account($this->business, '1102')->getKey(),
            $this->account($this->business, '4100')->getKey(),
            '500000'
        ),
    ])->assertStatus(422);

    // Reopen hanya untuk owner-accountant sesuai policy, dan wajib beralasan.
    Sanctum::actingAs($this->accountant);
    $this->postJson("/api/v1/periods/{$period->getKey()}/reopen")
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');

    $this->postJson("/api/v1/periods/{$period->getKey()}/reopen", ['reason' => 'Koreksi audit'])
        ->assertOk()
        ->assertJsonPath('data.status', 'reopened');
});

it('menolak akses periode bisnis lain lewat api', function (): void {
    [, $businessLain] = $this->provisionBusinessWithOwner('Bisnis Lain');
    $periodeLain = $this->periodFor($businessLain, '2026-01-15');

    Sanctum::actingAs($this->owner);

    $this->postJson("/api/v1/periods/{$periodeLain->getKey()}/close", ['reason' => 'Bukan milik saya'])
        ->assertNotFound();
});

it('menyajikan trial balance per periode dan per rentang tanggal', function (): void {
    $this->postSimpleJournal($this->business, $this->accountant, '1102', '4100', '1000000', '2026-01-10');
    $this->postSimpleJournal($this->business, $this->accountant, '1102', '4100', '2000000', '2026-02-10');

    Sanctum::actingAs($this->accountant);

    $period = $this->periodFor($this->business, '2026-01-15');

    $this->getJson("/api/v1/businesses/{$this->business->getKey()}/reports/trial-balance?period_id={$period->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.total_debit', '1000000.00');

    $this->getJson(
        "/api/v1/businesses/{$this->business->getKey()}/reports/trial-balance?from=2026-01-01&to=2026-02-28"
    )
        ->assertOk()
        ->assertJsonPath('data.total_debit', '3000000.00');

    $this->getJson(
        "/api/v1/businesses/{$this->business->getKey()}/reports/trial-balance?from=2026-02-01&to=2026-01-01"
    )->assertStatus(422);
});

it('menolak staff melihat laporan', function (): void {
    Sanctum::actingAs($this->staff);

    $this->getJson("/api/v1/businesses/{$this->business->getKey()}/reports/trial-balance")
        ->assertForbidden();
});

it('belum menyediakan endpoint phase berikutnya', function (): void {
    /*
     * Penanda eksplisit bahwa endpoint plan.md §29.3–§29.5, §29.8, dan §29.10 memang
     * belum dibangun, bukan terlewat: dokumen, transaksi, review queue, rekonsiliasi,
     * dan AI analyst adalah Phase 3 ke atas.
     */
    Sanctum::actingAs($this->accountant);

    $businessId = $this->business->getKey();

    foreach ([
        "/api/v1/businesses/{$businessId}/documents",
        "/api/v1/businesses/{$businessId}/transactions",
        "/api/v1/businesses/{$businessId}/review-queue",
        "/api/v1/businesses/{$businessId}/reconciliation",
        "/api/v1/businesses/{$businessId}/ai/ask",
    ] as $url) {
        $this->getJson($url)->assertNotFound();
    }
});
