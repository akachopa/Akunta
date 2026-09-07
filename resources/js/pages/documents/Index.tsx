import { Head, Link, router } from '@inertiajs/react';
import { useEffect } from 'react';

import Card from '@/components/Card';
import ConfidenceBadge from '@/components/ConfidenceBadge';
import DocumentUploader from '@/components/DocumentUploader';
import StatusBadge from '@/components/StatusBadge';
import { formatBytes, formatDateTime } from '@/lib/money';
import type { DocumentSummary } from '@/types';

interface Props {
    business: { id: string; name: string };
    filter: string;
    filters: { value: string; label: string }[];
    counts: Record<string, number>;
    documentTypes: { value: string; label: string }[];
    uploadLimits: { max_files: number; max_size_kb: number; extensions: string[] };
    documents: {
        data: DocumentSummary[];
        meta: { current_page: number; last_page: number; per_page: number; total: number };
    };
    errors: Record<string, string>;
}

// Interval polling status. Cukup rapat untuk terasa hidup, cukup longgar untuk tidak
// membebani server ketika banyak dokumen diunggah sekaligus.
const POLL_INTERVAL_MS = 4000;

export default function DocumentInbox({
    business,
    filter,
    filters,
    counts,
    documentTypes,
    uploadLimits,
    documents,
    errors,
}: Props) {
    const processing = documents.data.filter((document) => document.is_processing).length;

    /*
     * plan.md §37 Phase 3 acceptance: "processing status real-time/polling". Polling
     * hanya berjalan selama masih ada dokumen yang benar-benar diproses, dan memakai
     * partial reload sehingga hanya daftar dan jumlah tab yang diminta ulang.
     */
    useEffect(() => {
        if (processing === 0) {
            return;
        }

        const timer = window.setInterval(() => {
            router.reload({ only: ['documents', 'counts'] });
        }, POLL_INTERVAL_MS);

        return () => window.clearInterval(timer);
    }, [processing]);

    return (
        <>
            <Head title={`Inbox — ${business.name}`} />

            <div className="space-y-6">
                <Card
                    title="Unggah Data"
                    description="PDF, Excel, CSV, dan foto struk. Beberapa berkas sekaligus diperbolehkan."
                >
                    <DocumentUploader
                        businessId={business.id}
                        documentTypes={documentTypes}
                        limits={uploadLimits}
                        error={errors.files}
                    />
                </Card>

                <Card
                    title="Inbox"
                    description={
                        processing > 0
                            ? `${processing} dokumen sedang diproses. Status diperbarui otomatis.`
                            : 'Dokumen yang sudah diunggah beserta status prosesnya.'
                    }
                >
                    <nav className="mb-4 flex flex-wrap gap-2">
                        {filters.map((tab) => (
                            <Link
                                key={tab.value}
                                href={`/businesses/${business.id}/documents?filter=${tab.value}`}
                                preserveScroll
                                className={`rounded-full px-3 py-1 text-sm ${
                                    tab.value === filter
                                        ? 'bg-teal-600 text-white'
                                        : 'bg-slate-100 text-slate-700 hover:bg-slate-200'
                                }`}
                            >
                                {tab.label}
                                <span className="ml-2 text-xs opacity-80">
                                    {counts[tab.value] ?? 0}
                                </span>
                            </Link>
                        ))}
                    </nav>

                    {documents.data.length === 0 ? (
                        <p className="py-6 text-sm text-slate-500">
                            Belum ada dokumen pada tampilan ini.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-slate-200 text-sm">
                                <thead>
                                    <tr className="text-left text-xs uppercase tracking-wide text-slate-500">
                                        <th className="py-2 pr-4">Referensi</th>
                                        <th className="py-2 pr-4">Berkas</th>
                                        <th className="py-2 pr-4">Jenis</th>
                                        <th className="py-2 pr-4">Status</th>
                                        <th className="py-2 pr-4">Keyakinan</th>
                                        <th className="py-2 pr-4">Halaman</th>
                                        <th className="py-2 pr-4">Ukuran</th>
                                        <th className="py-2 pr-4">Diunggah</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {documents.data.map((document) => (
                                        <tr key={document.id} className="align-top">
                                            <td className="py-2 pr-4 font-mono text-xs">
                                                <Link
                                                    href={`/businesses/${business.id}/documents/${document.id}`}
                                                    className="text-teal-700 hover:underline"
                                                >
                                                    {document.reference}
                                                </Link>
                                            </td>
                                            <td className="max-w-xs py-2 pr-4">
                                                <span className="block truncate text-slate-800">
                                                    {document.original_filename}
                                                </span>
                                                {document.failure_reason && (
                                                    <span className="mt-1 block text-xs text-rose-600">
                                                        {document.failure_reason}
                                                    </span>
                                                )}
                                                {!document.failure_reason &&
                                                    document.review_reason && (
                                                        <span className="mt-1 block text-xs text-amber-700">
                                                            {document.review_reason}
                                                        </span>
                                                    )}
                                            </td>
                                            <td className="py-2 pr-4 text-slate-600">
                                                {document.document_type_label ?? 'Belum ditentukan'}
                                            </td>
                                            <td className="py-2 pr-4">
                                                <StatusBadge
                                                    status={document.processing_status}
                                                    label={document.processing_status_label}
                                                />
                                            </td>
                                            <td className="py-2 pr-4">
                                                <ConfidenceBadge
                                                    confidence={document.confidence?.score ?? null}
                                                    band={document.confidence?.band ?? null}
                                                />
                                            </td>
                                            <td className="py-2 pr-4 text-slate-600">
                                                {document.page_count ?? '—'}
                                            </td>
                                            <td className="py-2 pr-4 text-slate-600">
                                                {formatBytes(document.byte_size)}
                                            </td>
                                            <td className="py-2 pr-4 text-slate-600">
                                                {formatDateTime(document.uploaded_at)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    {documents.meta.last_page > 1 && (
                        <div className="mt-4 flex items-center justify-between text-sm text-slate-600">
                            <span>
                                Halaman {documents.meta.current_page} dari{' '}
                                {documents.meta.last_page} · {documents.meta.total} dokumen
                            </span>
                            <div className="flex gap-2">
                                <Link
                                    href={`/businesses/${business.id}/documents?filter=${filter}&page=${documents.meta.current_page - 1}`}
                                    preserveScroll
                                    className={`rounded-md border border-slate-300 px-3 py-1 ${
                                        documents.meta.current_page <= 1
                                            ? 'pointer-events-none opacity-40'
                                            : 'hover:bg-slate-100'
                                    }`}
                                >
                                    Sebelumnya
                                </Link>
                                <Link
                                    href={`/businesses/${business.id}/documents?filter=${filter}&page=${documents.meta.current_page + 1}`}
                                    preserveScroll
                                    className={`rounded-md border border-slate-300 px-3 py-1 ${
                                        documents.meta.current_page >= documents.meta.last_page
                                            ? 'pointer-events-none opacity-40'
                                            : 'hover:bg-slate-100'
                                    }`}
                                >
                                    Berikutnya
                                </Link>
                            </div>
                        </div>
                    )}
                </Card>
            </div>
        </>
    );
}
