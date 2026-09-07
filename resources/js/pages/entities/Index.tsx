import { Head, Link } from '@inertiajs/react';

import Card from '@/components/Card';
import StatusBadge from '@/components/StatusBadge';

interface EntityRow {
    id: string;
    name: string;
    type: string;
    type_label: string;
    status: string;
    status_label: string;
    confirmed: boolean;
    transaction_count: number;
}

interface Props {
    business: { id: string; name: string };
    entities: {
        data: EntityRow[];
        meta: { current_page: number; last_page: number; total: number };
    };
}

export default function EntityIndex({ business, entities }: Props) {
    return (
        <>
            <Head title={`Pihak Lawan — ${business.name}`} />

            <Card
                title="Pihak Lawan"
                description="Entity master hasil resolusi nama pada dokumen. Alias yang dikonfirmasi dipakai pada transaksi berikutnya."
            >
                {entities.data.length === 0 ? (
                    <p className="text-sm text-slate-500">Belum ada pihak lawan yang dikenali.</p>
                ) : (
                    <table className="min-w-full text-sm">
                        <thead>
                            <tr className="text-left text-xs uppercase tracking-wide text-slate-500">
                                <th className="py-2">Nama</th>
                                <th className="py-2">Jenis</th>
                                <th className="py-2">Status</th>
                                <th className="py-2 text-right">Transaksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            {entities.data.map((entity) => (
                                <tr key={entity.id} className="border-t border-slate-100">
                                    <td className="py-2">
                                        <Link
                                            href={`/businesses/${business.id}/entities/${entity.id}`}
                                            className="font-medium text-teal-700 hover:underline"
                                        >
                                            {entity.name}
                                        </Link>
                                    </td>
                                    <td className="py-2 text-slate-600">{entity.type_label}</td>
                                    <td className="py-2">
                                        <StatusBadge
                                            status={entity.confirmed ? 'ready' : 'need_review'}
                                            label={
                                                entity.confirmed ? 'Terkonfirmasi' : 'Dari dokumen'
                                            }
                                        />
                                    </td>
                                    <td className="py-2 text-right font-mono">
                                        {entity.transaction_count}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </Card>
        </>
    );
}
