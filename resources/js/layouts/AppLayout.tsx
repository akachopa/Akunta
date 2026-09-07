import { Link, usePage, router } from '@inertiajs/react';
import type { ReactNode } from 'react';

import type { SharedPageProps } from '@/types';

interface NavItem {
    label: string;
    href: string;
    permission?: string;
}

export default function AppLayout({ children }: { children: ReactNode }) {
    const { auth, currentBusiness, businesses, permissions, flash } =
        usePage<SharedPageProps>().props;

    /*
     * Menu mengikuti plan.md §6, tetapi hanya memuat cabang yang sudah dibangun pada
     * Phase 0–5. Review Center, Reconciliation, Closing, dan AI Analyst belum ditampilkan
     * karena halamannya belum ada.
     */
    const navigation: NavItem[] = currentBusiness
        ? [
              { label: 'Dashboard', href: '/dashboard' },
              {
                  label: 'Inbox',
                  href: `/businesses/${currentBusiness.id}/documents`,
                  permission: 'document.view',
              },
              {
                  label: 'Transaksi',
                  href: `/businesses/${currentBusiness.id}/transactions`,
                  permission: 'transaction.view',
              },
              { label: 'Profil Bisnis', href: `/businesses/${currentBusiness.id}` },
              {
                  label: 'Chart of Accounts',
                  href: `/businesses/${currentBusiness.id}/accounts`,
                  permission: 'account.view',
              },
              {
                  label: 'Journal',
                  href: `/businesses/${currentBusiness.id}/journals`,
                  permission: 'journal.view',
              },
              {
                  label: 'Neraca Saldo',
                  href: `/businesses/${currentBusiness.id}/reports/trial-balance`,
                  permission: 'report.view',
              },
              {
                  label: 'Periode Akuntansi',
                  href: `/businesses/${currentBusiness.id}/periods`,
                  permission: 'period.view',
              },
              {
                  label: 'Member & Role',
                  href: `/businesses/${currentBusiness.id}/members`,
                  permission: 'member.view',
              },
          ]
        : [
              { label: 'Dashboard', href: '/dashboard' },
              { label: 'Bisnis Saya', href: '/businesses' },
          ];

    const visibleNavigation = navigation.filter(
        (item) => !item.permission || permissions.includes(item.permission),
    );

    return (
        <div className="flex min-h-full flex-col">
            <header className="border-b border-slate-200 bg-white">
                <div className="mx-auto flex max-w-7xl items-center justify-between px-4 py-3">
                    <div className="flex items-center gap-6">
                        <Link href="/dashboard" className="text-lg font-semibold text-teal-700">
                            Akunta
                        </Link>

                        {businesses.length > 0 && (
                            <select
                                className="rounded-md border border-slate-300 px-2 py-1 text-sm"
                                value={currentBusiness?.id ?? ''}
                                onChange={(event) => {
                                    if (event.target.value) {
                                        router.visit(`/businesses/${event.target.value}`);
                                    }
                                }}
                            >
                                <option value="">Pilih bisnis…</option>
                                {businesses.map((business) => (
                                    <option key={business.id} value={business.id}>
                                        {business.name}
                                    </option>
                                ))}
                            </select>
                        )}
                    </div>

                    <div className="flex items-center gap-4 text-sm">
                        <span className="text-slate-600">{auth.user?.name}</span>
                        <Link
                            href="/logout"
                            method="post"
                            as="button"
                            className="rounded-md border border-slate-300 px-3 py-1 hover:bg-slate-100"
                        >
                            Keluar
                        </Link>
                    </div>
                </div>
            </header>

            <div className="mx-auto flex w-full max-w-7xl flex-1 gap-6 px-4 py-6">
                <nav className="w-56 shrink-0">
                    <ul className="space-y-1 text-sm">
                        {visibleNavigation.map((item) => (
                            <li key={item.href}>
                                <Link
                                    href={item.href}
                                    className="block rounded-md px-3 py-2 text-slate-700 hover:bg-white hover:text-teal-700"
                                >
                                    {item.label}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </nav>

                <main className="min-w-0 flex-1">
                    {flash.success && (
                        <div className="mb-4 rounded-md border border-teal-200 bg-teal-50 px-4 py-3 text-sm text-teal-800">
                            {flash.success}
                        </div>
                    )}

                    {flash.error && (
                        <div className="mb-4 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                            {flash.error}
                        </div>
                    )}

                    {children}
                </main>
            </div>
        </div>
    );
}
