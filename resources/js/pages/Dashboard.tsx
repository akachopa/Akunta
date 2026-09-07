import { Head, Link } from '@inertiajs/react';

import Card from '@/components/Card';

interface BusinessRow {
    id: string;
    name: string;
    business_type: string;
    role: string | null;
    accounts_count: number;
    posted_entries_count: number;
    open_periods_count: number;
}

export default function Dashboard({ businesses }: { businesses: BusinessRow[] }) {
    return (
        <>
            <Head title="Dashboard" />

            <div className="space-y-6">
                <Card
                    title="Workspace Anda"
                    description="Ringkasan kesiapan fondasi akuntansi tiap bisnis."
                    actions={
                        <Link
                            href="/businesses/create"
                            className="rounded-md bg-teal-700 px-3 py-1.5 text-sm font-medium text-white hover:bg-teal-800"
                        >
                            Tambah Bisnis
                        </Link>
                    }
                >
                    {businesses.length === 0 ? (
                        <p className="text-sm text-slate-500">
                            Belum ada bisnis. Buat bisnis pertama Anda untuk menghasilkan starter
                            chart of accounts dan periode akuntansi.
                        </p>
                    ) : (
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-slate-200 text-left text-slate-500">
                                    <th className="py-2 font-medium">Bisnis</th>
                                    <th className="py-2 font-medium">Jenis Usaha</th>
                                    <th className="py-2 font-medium">Role Anda</th>
                                    <th className="py-2 text-right font-medium">Akun COA</th>
                                    <th className="py-2 text-right font-medium">
                                        Journal Diposting
                                    </th>
                                    <th className="py-2 text-right font-medium">Periode Aktif</th>
                                </tr>
                            </thead>
                            <tbody>
                                {businesses.map((business) => (
                                    <tr key={business.id} className="border-b border-slate-100">
                                        <td className="py-2">
                                            <Link
                                                href={`/businesses/${business.id}`}
                                                className="font-medium text-teal-700 hover:underline"
                                            >
                                                {business.name}
                                            </Link>
                                        </td>
                                        <td className="py-2 text-slate-600">
                                            {business.business_type}
                                        </td>
                                        <td className="py-2 text-slate-600">
                                            {business.role ?? '—'}
                                        </td>
                                        <td className="py-2 text-right">
                                            {business.accounts_count}
                                        </td>
                                        <td className="py-2 text-right">
                                            {business.posted_entries_count}
                                        </td>
                                        <td className="py-2 text-right">
                                            {business.open_periods_count}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </Card>

                <Card title="Status Pengembangan">
                    <p className="text-sm text-slate-600">
                        Phase 0 sampai Phase 2 sudah tersedia: multi-tenant workspace, chart of
                        accounts, manual journal, dan neraca saldo. Document inbox, ekstraksi AI,
                        rekonsiliasi, dan laporan keuangan lengkap dibangun pada phase berikutnya.
                    </p>
                </Card>
            </div>
        </>
    );
}
