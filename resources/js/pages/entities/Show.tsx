import { Head, Link, router, useForm } from '@inertiajs/react';

import Card from '@/components/Card';
import StatusBadge from '@/components/StatusBadge';

interface EntityDetail {
    id: string;
    name: string;
    type_label: string;
    status: string;
    status_label: string;
    confirmed: boolean;
    transaction_count: number;
    aliases: { alias: string; source_label: string }[];
    identifiers: { kind_label: string; value: string }[];
}

interface Candidate {
    id: string;
    name: string;
    type_label: string;
}

interface Props {
    business: { id: string; name: string };
    entity: EntityDetail;
    merge_candidates: Candidate[];
    can: { manage: boolean };
}

export default function EntityShow({ business, entity, merge_candidates, can }: Props) {
    const confirmForm = useForm({ alias: entity.name });
    const mergeForm = useForm({ target_id: '' });

    return (
        <>
            <Head title={`${entity.name} — ${business.name}`} />

            <div className="space-y-6">
                <Card
                    title={entity.name}
                    description={entity.type_label}
                    actions={<StatusBadge status={entity.status} label={entity.status_label} />}
                >
                    <p className="text-sm text-slate-600">
                        {entity.transaction_count} transaksi menunjuk pihak lawan ini.
                    </p>

                    {can.manage && !entity.confirmed && (
                        <form
                            className="mt-4 flex flex-wrap gap-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                confirmForm.post(
                                    `/businesses/${business.id}/entities/${entity.id}/confirm`,
                                );
                            }}
                        >
                            <input
                                className="rounded-md border border-slate-300 px-3 py-2 text-sm"
                                value={confirmForm.data.alias}
                                onChange={(event) =>
                                    confirmForm.setData('alias', event.target.value)
                                }
                            />
                            <button
                                type="submit"
                                className="rounded-md bg-teal-700 px-3 py-2 text-sm text-white"
                            >
                                Konfirmasi alias
                            </button>
                        </form>
                    )}
                </Card>

                <Card title="Alias" description="Nama lain yang menunjuk entity yang sama.">
                    {entity.aliases.length === 0 ? (
                        <p className="text-sm text-slate-500">Belum ada alias.</p>
                    ) : (
                        <ul className="text-sm">
                            {entity.aliases.map((alias) => (
                                <li key={alias.alias} className="flex justify-between py-1">
                                    <span>{alias.alias}</span>
                                    <span className="text-slate-500">{alias.source_label}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>

                <Card title="Identitas">
                    {entity.identifiers.length === 0 ? (
                        <p className="text-sm text-slate-500">Belum ada identitas unik.</p>
                    ) : (
                        <ul className="text-sm">
                            {entity.identifiers.map((identifier) => (
                                <li key={identifier.value} className="flex justify-between py-1">
                                    <span>{identifier.kind_label}</span>
                                    <span className="font-mono">{identifier.value}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>

                {can.manage && merge_candidates.length > 0 && (
                    <Card
                        title="Gabungkan"
                        description="Entity yang diserap tidak dihapus; jejak merge tetap tercatat."
                    >
                        <form
                            className="flex flex-wrap gap-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                mergeForm.post(
                                    `/businesses/${business.id}/entities/${entity.id}/merge`,
                                );
                            }}
                        >
                            <select
                                className="rounded-md border border-slate-300 px-3 py-2 text-sm"
                                value={mergeForm.data.target_id}
                                onChange={(event) =>
                                    mergeForm.setData('target_id', event.target.value)
                                }
                            >
                                <option value="">Pilih entity yang bertahan</option>
                                {merge_candidates.map((candidate) => (
                                    <option key={candidate.id} value={candidate.id}>
                                        {candidate.name} ({candidate.type_label})
                                    </option>
                                ))}
                            </select>
                            <button
                                type="submit"
                                className="rounded-md border border-slate-300 px-3 py-2 text-sm"
                            >
                                Gabungkan ke entity ini
                            </button>
                        </form>
                    </Card>
                )}

                <Link
                    href={`/businesses/${business.id}/entities`}
                    className="text-sm text-teal-700 hover:underline"
                >
                    Kembali ke daftar pihak lawan
                </Link>
            </div>
        </>
    );
}
