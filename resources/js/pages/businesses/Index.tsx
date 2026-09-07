import { Head, Link } from '@inertiajs/react';

import Card from '@/components/Card';

interface BusinessRow {
    id: string;
    name: string;
    business_type: string;
    currency: string;
    organization: string;
    role: string | null;
}

export default function BusinessIndex({ businesses }: { businesses: BusinessRow[] }) {
    return (
        <>
            <Head title="Bisnis Saya" />

            <Card
                title="Bisnis Saya"
                description="Satu akun dapat mengelola beberapa bisnis, dan akuntan dapat terhubung ke beberapa client."
                actions={
                    <Link
                        href="/businesses/create"
                        className="rounded-md bg-teal-700 px-3 py-1.5 text-sm font-medium text-white hover:bg-teal-800"
                    >
                        Tambah Bisnis
                    </Link>
                }
            >
                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-b border-slate-200 text-left text-slate-500">
                            <th className="py-2 font-medium">Nama</th>
                            <th className="py-2 font-medium">Organisasi</th>
                            <th className="py-2 font-medium">Jenis Usaha</th>
                            <th className="py-2 font-medium">Mata Uang</th>
                            <th className="py-2 font-medium">Role</th>
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
                                <td className="py-2 text-slate-600">{business.organization}</td>
                                <td className="py-2 text-slate-600">{business.business_type}</td>
                                <td className="py-2 text-slate-600">{business.currency}</td>
                                <td className="py-2 text-slate-600">{business.role ?? '—'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </Card>
        </>
    );
}
