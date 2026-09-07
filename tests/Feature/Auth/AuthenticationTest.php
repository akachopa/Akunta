<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;

/*
 * plan.md §37 Phase 1: authentication.
 */

it('menampilkan halaman login untuk guest', function (): void {
    $this->get('/login')->assertOk();
});

it('mengalihkan guest yang membuka dashboard ke login', function (): void {
    $this->get('/dashboard')->assertRedirect('/login');
});

it('mengizinkan user login dengan kredensial benar', function (): void {
    $user = User::factory()->create(['password' => Hash::make('rahasia-uji')]);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'rahasia-uji',
    ])->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($user);
});

it('menolak login dengan password salah', function (): void {
    $user = User::factory()->create(['password' => Hash::make('rahasia-uji')]);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password-salah',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('mendaftarkan user baru dengan password terhash', function (): void {
    $this->post('/register', [
        'name' => 'Pemilik Toko',
        'email' => 'pemilik@akunta.test',
        'password' => 'rahasia-uji-123',
        'password_confirmation' => 'rahasia-uji-123',
    ])->assertRedirect();

    $user = User::query()->where('email', 'pemilik@akunta.test')->sole();

    expect($user->password)->not->toBe('rahasia-uji-123');
    expect(Hash::check('rahasia-uji-123', $user->password))->toBeTrue();
    $this->assertAuthenticatedAs($user);
});

it('melogout user', function (): void {
    $this->actingAs(User::factory()->create())
        ->post('/logout')
        ->assertRedirect('/login');

    $this->assertGuest();
});
