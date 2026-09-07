import { Head, Link } from '@inertiajs/react';

import Card from '@/components/Card';
import ConfidenceBadge from '@/components/ConfidenceBadge';
import StatusBadge from '@/components/StatusBadge';
import { formatDate, formatDateTime, formatMoney } from '@/lib/money';
import type { TransactionDetail } from '@/types';

interface Props {
    business: { id: string; name: string };
    transaction: TransactionDetail;
}

function Detail({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div>
            <dt className="text-xs uppercase tracking-wide text-slate-500">{label}</dt>
            <dd className="mt-1 text-sm text-slate-800">{children}</dd>
        </div>
    );
}

export default function TransactionShow({ business, transaction }: Props) {
    const inflow = transaction.direction === 'inflow';
    const documentSource = transaction.source?.document ?? null;

    return (
        <>
            <Head title={`${transaction.reference} — ${business.name}`} />

            <div className="space-y-6">
                <Card
                    title={transaction.reference}
                    description={transaction.description}
                    actions={
                        <div className="flex items-center gap-2">
                            <StatusBadge
                                status={transaction.status}
                                label={transaction.status_label}
                            />
                            <ConfidenceBadge confidence={transaction.overall_confidence} />
                        </div>
                    }
                >
                    <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <Detail label="Tanggal">{formatDate(transaction.transaction_date)}</Detail>

                        <Detail label={inflow ? 'Nilai Masuk' : 'Nilai Keluar'}>
                            <span
                                className={`font-mono ${inflow ? 'text-teal-700' : 'text-slate-800'}`}
                            >
                                {inflow ? '+' : '−'}{' '}
                                {formatMoney(transaction.amount, transaction.currency)}
                            </span>
                        </Detail>

                        <Detail label="Pihak Lawan">
                            {transaction.counterparty_name ?? 'Belum teridentifikasi'}
                        </Detail>

                        <Detail label="Jenis Sumber">{transaction.source_type_label}</Detail>

                        <Detail label="Dinormalisasi">
                            {formatDateTime(transaction.normalized_at)}
                        </Detail>

                        {transaction.review_reason && (
                            <div className="sm:col-span-2 lg:col-span-3">
                                <dt className="text-xs uppercase tracking-wide text-slate-500">
                                    Perlu Diperiksa
                                </dt>
                                <dd className="mt-1 text-sm text-amber-700">
                                    {transaction.review_reason}
                                </dd>
                            </div>
                        )}
                    </dl>

                    {/*
                     * plan.md §44.3 dan §44.4: transaksi belum menyatakan pendapatan maupun
                     * beban. Kalimat ini ada supaya user tidak membaca "nilai masuk" sebagai
                     * penjualan yang sudah diakui.
                     */}
                    <p className="mt-4 rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-500">
                        Transaksi ini menggambarkan perpindahan nilai yang terbaca dari dokumen.
                        Penentuan akun dan jurnalnya dikerjakan pada tahap berikutnya.
                    </p>
                </Card>

                <Card
                    title="Asal Transaksi"
                    description="Setiap transaksi dapat ditelusuri kembali ke berkas yang menghasilkannya."
                >
                    {transaction.source === null ? (
                        <p className="text-sm text-slate-500">Sumber transaksi tidak tercatat.</p>
                    ) : (
                        <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <Detail label="Dokumen">
                                {documentSource ? (
                                    <Link
                                        href={`/businesses/${business.id}/documents/${documentSource.id}`}
                                        className="text-teal-700 hover:underline"
                                    >
                                        {documentSource.reference}
                                    </Link>
                                ) : (
                                    '—'
                                )}
                            </Detail>

                            <Detail label="Berkas">
                                {documentSource?.original_filename ?? '—'}
                            </Detail>

                            <Detail label="Jenis Dokumen">
                                {documentSource?.document_type_label ?? '—'}
                            </Detail>

                            <Detail label="Unit Sumber">
                                {transaction.source.row_index === null
                                    ? 'Seluruh dokumen'
                                    : `Baris ${transaction.source.row_index + 1}`}
                                {transaction.source.page_number !== null &&
                                    ` · halaman ${transaction.source.page_number}`}
                            </Detail>
                        </dl>
                    )}
                </Card>

                <Card
                    title="Bukti"
                    description="Bagian dokumen yang menjadi dasar transaksi ini, termasuk angka pendukung yang bukan nominalnya."
                >
                    {transaction.evidence.length === 0 ? (
                        <p className="text-sm text-slate-500">Belum ada bukti yang tercatat.</p>
                    ) : (
                        <ul className="divide-y divide-slate-100">
                            {transaction.evidence.map((evidence) => (
                                <li key={evidence.id} className="flex flex-wrap gap-2 py-2 text-sm">
                                    <span className="w-40 shrink-0 text-slate-500">
                                        {evidence.field_label ?? evidence.type_label}
                                    </span>
                                    <span className="min-w-0 flex-1 text-slate-800">
                                        {evidence.note ?? '—'}
                                    </span>
                                    <span className="text-xs text-slate-400">
                                        {evidence.row_reference !== null &&
                                            `baris ${Number(evidence.row_reference) + 1}`}
                                        {evidence.page_number !== null &&
                                            ` · halaman ${evidence.page_number}`}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </div>
        </>
    );
}
