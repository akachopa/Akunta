import { Head, Link, useForm } from '@inertiajs/react';

import Card from '@/components/Card';
import { formatDate } from '@/lib/money';

interface BankAccountRow {
    id: string;
    label: string;
    bank_name: string;
    account_number: string;
    is_primary: boolean;
}

interface BusinessDetail {
    id: string;
    name: string;
    legal_name: string | null;
    business_type: string;
    accounting_basis: string;
    currency: string;
    opening_date: string;
    organization: string;
    profile: Record<string, string | null> | null;
    bank_accounts: BankAccountRow[];
}

interface Props {
    business: BusinessDetail;
    counts: { accounts: number; periods: number; journal_entries: number };
}

export default function BusinessShow({ business, counts }: Props) {
    const form = useForm({
        name: business.name,
        legal_name: business.legal_name ?? '',
        tax_id: business.profile?.tax_id ?? '',
        phone: business.profile?.phone ?? '',
        email: business.profile?.email ?? '',
        address: business.profile?.address ?? '',
        city: business.profile?.city ?? '',
        province: business.profile?.province ?? '',
        postal_code: business.profile?.postal_code ?? '',
    });

    return (
        <>
            <Head title={business.name} />

            <div className="space-y-6">
                <Card title={business.name} description={`${business.business_type} — ${business.organization}`}>
                    <dl className="grid grid-cols-2 gap-4 text-sm md:grid-cols-4">
                        <div>
                            <dt className="text-slate-500">Basis Akuntansi</dt>
                            <dd className="font-medium text-slate-900">{business.accounting_basis}</dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">Mata Uang</dt>
                            <dd className="font-medium text-slate-900">{business.currency}</dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">Tanggal Mulai</dt>
                            <dd className="font-medium text-slate-900">{formatDate(business.opening_date)}</dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">Nama Legal</dt>
                            <dd className="font-medium text-slate-900">{business.legal_name ?? '—'}</dd>
                        </div>
                    </dl>

                    <div className="mt-5 grid grid-cols-3 gap-3 text-sm">
                        <Link
                            href={`/businesses/${business.id}/accounts`}
                            className="rounded-md border border-slate-200 px-4 py-3 hover:border-teal-300"
                        >
                            <span className="block text-2xl font-semibold text-slate-900">{counts.accounts}</span>
                            <span className="text-slate-500">Akun COA</span>
                        </Link>
                        <Link
                            href={`/businesses/${business.id}/periods`}
                            className="rounded-md border border-slate-200 px-4 py-3 hover:border-teal-300"
                        >
                            <span className="block text-2xl font-semibold text-slate-900">{counts.periods}</span>
                            <span className="text-slate-500">Periode</span>
                        </Link>
                        <Link
                            href={`/businesses/${business.id}/journals`}
                            className="rounded-md border border-slate-200 px-4 py-3 hover:border-teal-300"
                        >
                            <span className="block text-2xl font-semibold text-slate-900">
                                {counts.journal_entries}
                            </span>
                            <span className="text-slate-500">Journal Entry</span>
                        </Link>
                    </div>
                </Card>

                <Card title="Profil Bisnis" description="Data ini dipakai pada header laporan keuangan.">
                    <form
                        className="grid grid-cols-1 gap-4 md:grid-cols-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.patch(`/businesses/${business.id}`, { preserveScroll: true });
                        }}
                    >
                        {(
                            [
                                ['name', 'Nama Bisnis'],
                                ['legal_name', 'Nama Legal'],
                                ['tax_id', 'NPWP'],
                                ['phone', 'Telepon'],
                                ['email', 'Email'],
                                ['city', 'Kota'],
                                ['province', 'Provinsi'],
                                ['postal_code', 'Kode Pos'],
                            ] as const
                        ).map(([field, label]) => (
                            <label key={field} className="text-sm">
                                <span className="block text-slate-600">{label}</span>
                                <input
                                    type="text"
                                    value={form.data[field]}
                                    onChange={(event) => form.setData(field, event.target.value)}
                                    className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2"
                                />
                                {form.errors[field] && (
                                    <span className="mt-1 block text-xs text-rose-600">{form.errors[field]}</span>
                                )}
                            </label>
                        ))}

                        <label className="text-sm md:col-span-2">
                            <span className="block text-slate-600">Alamat</span>
                            <textarea
                                value={form.data.address}
                                onChange={(event) => form.setData('address', event.target.value)}
                                rows={2}
                                className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2"
                            />
                        </label>

                        <div className="md:col-span-2">
                            <button
                                type="submit"
                                disabled={form.processing}
                                className="rounded-md bg-teal-700 px-4 py-2 text-sm font-medium text-white hover:bg-teal-800 disabled:opacity-50"
                            >
                                Simpan Profil
                            </button>
                        </div>
                    </form>
                </Card>

                <Card title="Rekening" description="Rekening bank dan kas yang dipakai bisnis ini.">
                    {business.bank_accounts.length === 0 ? (
                        <p className="text-sm text-slate-500">Belum ada rekening terdaftar.</p>
                    ) : (
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-slate-200 text-left text-slate-500">
                                    <th className="py-2 font-medium">Label</th>
                                    <th className="py-2 font-medium">Bank</th>
                                    <th className="py-2 font-medium">Nomor</th>
                                    <th className="py-2 font-medium">Utama</th>
                                </tr>
                            </thead>
                            <tbody>
                                {business.bank_accounts.map((account) => (
                                    <tr key={account.id} className="border-b border-slate-100">
                                        <td className="py-2">{account.label}</td>
                                        <td className="py-2 text-slate-600">{account.bank_name}</td>
                                        <td className="py-2 text-slate-600">{account.account_number}</td>
                                        <td className="py-2 text-slate-600">{account.is_primary ? 'Ya' : '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </Card>
            </div>
        </>
    );
}
