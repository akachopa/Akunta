import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import Card from '@/components/Card';
import type { AccountType } from '@/types';

interface AccountRow {
    id: string;
    code: string;
    name: string;
    account_type: AccountType;
    account_type_label: string;
    normal_balance: 'debit' | 'credit';
    account_role: string | null;
    reporting_group: string;
    is_postable: boolean;
    is_system: boolean;
    is_active: boolean;
    parent_id: string | null;
}

interface Props {
    business: { id: string; name: string };
    accounts: AccountRow[];
    accountTypes: { value: AccountType; label: string }[];
    accountRoles: string[];
}

export default function ChartOfAccounts({ business, accounts, accountTypes, accountRoles }: Props) {
    const [showInactive, setShowInactive] = useState(false);

    const form = useForm<{
        code: string;
        name: string;
        account_type: string;
        account_role: string;
        is_postable: boolean;
    }>({
        code: '',
        name: '',
        account_type: accountTypes[0]?.value ?? 'asset',
        account_role: '',
        is_postable: true,
    });

    const visible = accounts.filter((account) => showInactive || account.is_active);

    return (
        <>
            <Head title={`Chart of Accounts — ${business.name}`} />

            <div className="space-y-6">
                <Card
                    title="Chart of Accounts"
                    description="Akun bertanda sistem berasal dari template starter dan hanya dapat diubah nama serta statusnya."
                    actions={
                        <label className="flex items-center gap-2 text-sm text-slate-600">
                            <input
                                type="checkbox"
                                checked={showInactive}
                                onChange={(event) => setShowInactive(event.target.checked)}
                            />
                            Tampilkan akun nonaktif
                        </label>
                    }
                >
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 text-left text-slate-500">
                                <th className="py-2 font-medium">Kode</th>
                                <th className="py-2 font-medium">Nama</th>
                                <th className="py-2 font-medium">Tipe</th>
                                <th className="py-2 font-medium">Saldo Normal</th>
                                <th className="py-2 font-medium">System Role</th>
                                <th className="py-2 font-medium">Sifat</th>
                                <th className="py-2" />
                            </tr>
                        </thead>
                        <tbody>
                            {visible.map((account) => (
                                <tr
                                    key={account.id}
                                    className={`border-b border-slate-100 ${account.is_active ? '' : 'text-slate-400'}`}
                                >
                                    <td className="py-2 font-mono tabular-nums">{account.code}</td>
                                    <td className={`py-2 ${account.is_postable ? '' : 'font-semibold'}`}>
                                        {account.name}
                                    </td>
                                    <td className="py-2 text-slate-600">{account.account_type_label}</td>
                                    <td className="py-2 text-slate-600">
                                        {account.normal_balance === 'debit' ? 'Debit' : 'Kredit'}
                                    </td>
                                    <td className="py-2 font-mono text-xs text-slate-500">
                                        {account.account_role ?? '—'}
                                    </td>
                                    <td className="py-2 text-slate-600">
                                        {account.is_postable ? 'Dapat diposting' : 'Header'}
                                        {account.is_system && ' · sistem'}
                                    </td>
                                    <td className="py-2 text-right">
                                        {account.is_active && (
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    router.delete(
                                                        `/businesses/${business.id}/accounts/${account.id}`,
                                                        { preserveScroll: true },
                                                    )
                                                }
                                                className="text-xs text-rose-600 hover:underline"
                                            >
                                                Nonaktifkan
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </Card>

                <Card
                    title="Tambah Akun"
                    description="Akun custom melengkapi starter COA tanpa mengubah akun sistem."
                >
                    <form
                        className="flex flex-wrap items-end gap-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post(`/businesses/${business.id}/accounts`, {
                                preserveScroll: true,
                                onSuccess: () => form.reset('code', 'name'),
                            });
                        }}
                    >
                        <label className="text-sm">
                            <span className="block text-slate-600">Kode</span>
                            <input
                                type="text"
                                value={form.data.code}
                                onChange={(event) => form.setData('code', event.target.value)}
                                className="mt-1 w-28 rounded-md border border-slate-300 px-3 py-2 font-mono"
                                required
                            />
                            {form.errors.code && (
                                <span className="mt-1 block text-xs text-rose-600">{form.errors.code}</span>
                            )}
                        </label>

                        <label className="text-sm">
                            <span className="block text-slate-600">Nama</span>
                            <input
                                type="text"
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                                className="mt-1 w-64 rounded-md border border-slate-300 px-3 py-2"
                                required
                            />
                        </label>

                        <label className="text-sm">
                            <span className="block text-slate-600">Tipe Akun</span>
                            <select
                                value={form.data.account_type}
                                onChange={(event) => form.setData('account_type', event.target.value)}
                                className="mt-1 rounded-md border border-slate-300 px-3 py-2"
                            >
                                {accountTypes.map((type) => (
                                    <option key={type.value} value={type.value}>
                                        {type.label}
                                    </option>
                                ))}
                            </select>
                        </label>

                        <label className="text-sm">
                            <span className="block text-slate-600">System Role</span>
                            <select
                                value={form.data.account_role}
                                onChange={(event) => form.setData('account_role', event.target.value)}
                                className="mt-1 rounded-md border border-slate-300 px-3 py-2"
                            >
                                <option value="">Tanpa role</option>
                                {accountRoles.map((role) => (
                                    <option key={role} value={role}>
                                        {role}
                                    </option>
                                ))}
                            </select>
                            {form.errors.account_role && (
                                <span className="mt-1 block text-xs text-rose-600">{form.errors.account_role}</span>
                            )}
                        </label>

                        <label className="flex items-center gap-2 pb-2 text-sm text-slate-600">
                            <input
                                type="checkbox"
                                checked={form.data.is_postable}
                                onChange={(event) => form.setData('is_postable', event.target.checked)}
                            />
                            Dapat diposting
                        </label>

                        <button
                            type="submit"
                            disabled={form.processing}
                            className="rounded-md bg-teal-700 px-4 py-2 text-sm font-medium text-white hover:bg-teal-800 disabled:opacity-50"
                        >
                            Tambah Akun
                        </button>
                    </form>
                </Card>
            </div>
        </>
    );
}
