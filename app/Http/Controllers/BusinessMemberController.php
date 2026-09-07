<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Business\Enums\RoleSlug;
use App\Domain\Business\Models\Business;
use App\Domain\Business\Models\BusinessUser;
use App\Models\User;
use App\Services\Business\MembershipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BusinessMemberController extends Controller
{
    public function __construct(private readonly MembershipService $memberships)
    {
    }

    public function index(Business $business): Response
    {
        $this->authorize('viewMembers', $business);

        return Inertia::render('businesses/Members', [
            'business' => ['id' => $business->getKey(), 'name' => $business->name],
            'members' => $business->memberships()
                ->with(['user:id,name,email', 'role:id,slug,name'])
                ->get()
                ->map(static fn (BusinessUser $membership): array => [
                    'id' => $membership->getKey(),
                    'user_id' => $membership->user_id,
                    'name' => $membership->user->name,
                    'email' => $membership->user->email,
                    'role' => $membership->role->slug->value,
                    'role_label' => $membership->role->name,
                    'is_external' => $membership->is_external,
                    'joined_at' => $membership->joined_at?->toIso8601String(),
                ]),
            'assignableRoles' => array_values(array_map(
                static fn (RoleSlug $role): array => ['value' => $role->value, 'label' => $role->label()],
                array_filter(RoleSlug::cases(), static fn (RoleSlug $role): bool => $role->isBusinessScoped())
            )),
        ]);
    }

    public function store(Request $request, Business $business): RedirectResponse
    {
        $this->authorize('manageMembers', $business);

        $validated = $request->validate([
            'email' => ['required', 'email'],
            'role' => ['required', Rule::in(array_map(
                static fn (RoleSlug $role): string => $role->value,
                array_filter(RoleSlug::cases(), static fn (RoleSlug $role): bool => $role->isBusinessScoped())
            ))],
            'is_external' => ['nullable', 'boolean'],
        ]);

        $user = User::query()->where('email', $validated['email'])->first();

        if ($user === null) {
            throw ValidationException::withMessages([
                'email' => 'User dengan email tersebut belum terdaftar.',
            ]);
        }

        $this->memberships->attach(
            $business,
            $user,
            RoleSlug::from($validated['role']),
            (bool) ($validated['is_external'] ?? false),
            $request->user()
        );

        return back()->with('success', "{$user->name} ditambahkan sebagai member.");
    }

    public function update(Request $request, Business $business, BusinessUser $member): RedirectResponse
    {
        $this->authorize('manageMembers', $business);
        $this->assertMemberBelongsTo($business, $member);

        $validated = $request->validate([
            'role' => ['required', Rule::in(array_map(
                static fn (RoleSlug $role): string => $role->value,
                array_filter(RoleSlug::cases(), static fn (RoleSlug $role): bool => $role->isBusinessScoped())
            ))],
        ]);

        $this->memberships->changeRole($member, RoleSlug::from($validated['role']), $request->user());

        return back()->with('success', 'Role member diperbarui.');
    }

    public function destroy(Request $request, Business $business, BusinessUser $member): RedirectResponse
    {
        $this->authorize('manageMembers', $business);
        $this->assertMemberBelongsTo($business, $member);

        $this->memberships->detach($member, $request->user());

        return back()->with('success', 'Member dilepas dari bisnis.');
    }

    /**
     * BusinessUser tidak memakai global scope tenant, sehingga keterikatannya ke business
     * pada route harus diperiksa manual (plan.md §44.15).
     */
    private function assertMemberBelongsTo(Business $business, BusinessUser $member): void
    {
        abort_unless($member->business_id === $business->getKey(), 404);
    }
}
