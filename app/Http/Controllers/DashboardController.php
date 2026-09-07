<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Business\Models\Business;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Landing setelah login.
 *
 * plan.md §35.2 dan §36 mendeskripsikan dashboard yang menonjolkan upload data, need
 * review, dan accounting readiness. Angka-angka tersebut berasal dari pipeline dokumen
 * (Phase 3+) dan closing center (Phase 13), sehingga dashboard pada Phase 2 hanya
 * menampilkan status fondasi akuntansi yang benar-benar sudah ada datanya.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        $businesses = $user->businesses()->orderBy('name')->get();

        return Inertia::render('Dashboard', [
            'businesses' => $businesses->map(fn (Business $business): array => [
                'id' => $business->getKey(),
                'name' => $business->name,
                'business_type' => $business->business_type->label(),
                'role' => $user->roleIn($business)?->name,
                'accounts_count' => $business->accounts()->count(),
                'posted_entries_count' => $business->journalEntries()->posted()->count(),
                'open_periods_count' => $business->accountingPeriods()
                    ->whereIn('status', ['open', 'reviewing', 'ready_to_close', 'reopened'])
                    ->count(),
            ]),
        ]);
    }
}
