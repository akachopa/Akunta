<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Business\BusinessProvisioningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    public function __construct(private readonly BusinessProvisioningService $provisioning)
    {
    }

    public function create(): Response
    {
        return Inertia::render('auth/Register');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = User::create($data);

        /*
         * Setiap user langsung memiliki organization sendiri agar bisnis pertama yang ia
         * buat punya tenant induk (plan.md §3.1 "multi-business per user").
         */
        $this->provisioning->ensurePersonalOrganization($user);

        Auth::login($user);

        $request->session()->regenerate();

        return redirect()->route('businesses.create');
    }
}
