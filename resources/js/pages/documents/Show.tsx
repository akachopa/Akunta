import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import Card from '@/components/Card';
import ConfidenceBadge from '@/components/ConfidenceBadge';
import DocumentReviewPanel from '@/components/DocumentReviewPanel';
import StatusBadge from '@/components/StatusBadge';
import { formatBytes, formatDateTime, formatDuration } from '@/lib/money';
import type { DocumentDetail, DocumentPage } from '@/types';

interface Props {
    business: { id: string; name: string };
    document: DocumentDetail;
    can: { reprocess: boolean; archive: boolean; download: boolean; review: boolean };
}

const POLL_INTERVAL_MS = 4000;

// Baris yang ditampilkan per halaman tabular. Sisanya tetap tersimpan; pemotongan ini
// hanya menjaga halaman viewer tetap ringan.
const PREVIEW_ROW_LIMIT = 50;

function PagePreview({ page }: { page: DocumentPage }) {
    if (page.needs_ocr) {
        return (
            <p className="text-sm text-slate-500">
                Isi halaman ini hanya dapat dibaca lewat OCR, dan OCR belum tersedia. Datanya masih
                dapat dilengkapi lewat review manual.
            </p>
        );
    }

    if (page.is_tabular && page.rows) {
        const rows = page.rows.slice(0, PREVIEW_ROW_LIMIT);

        return (
            <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-slate-200 text-xs">
                    <tbody className="divide-y divide-slate-100">
                        {rows.map((row, rowIndex) => (
                            <tr key={rowIndex}>
                                {row.map((cell, cellIndex) => (
                                    <td
                                        key={cellIndex}
                                        className="whitespace-nowrap px-2 py-1 text-slate-700"
                                    >
                                        {cell ?? ''}
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>

                {page.rows.length > PREVIEW_ROW_LIMIT && (
                    <p className="mt-2 text-xs text-slate-500">
                        Menampilkan {PREVIEW_ROW_LIMIT} dari {page.rows.length} baris.
                    </p>
                )}
            </div>
        );
    }

    if (page.text) {
        return (
            <pre className="max-h-96 overflow-auto whitespace-pre-wrap rounded-md bg-slate-50 p-3 text-xs text-slate-700">
                {page.text}
            </pre>
        );
    }

    return <p className="text-sm text-slate-500">Halaman ini tidak memuat teks.</p>;
}

export default function DocumentShow({ business, document, can }: Props) {
    const [activePage, setActivePage] = useState(0);

    /*
     * Halaman detail ikut melakukan polling supaya user yang membuka dokumen tepat
     * setelah upload melihat statusnya berubah tanpa memuat ulang halaman
     * (plan.md §37 Phase 3).
     */
    useEffect(() => {
        if (!document.is_processing) {
            return;
        }

        const timer = window.setInterval(() => {
            router.reload({ only: ['document'] });
        }, POLL_INTERVAL_MS);

        return () => window.clearInterval(timer);
    }, [document.is_processing]);

    const page = document.pages[activePage];

    return (
        <>
            <Head title={`${document.reference} — ${business.name}`} />

            <div className="space-y-6">
                <Card
                    title={document.original_filename}
                    description={`${document.reference} · diunggah ${formatDateTime(document.uploaded_at)}${
                        document.uploaded_by ? ` oleh ${document.uploaded_by}` : ''
                    }`}
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <Link
                                href={`/businesses/${business.id}/documents`}
                                className="rounded-md border border-slate-300 px-3 py-1 text-sm hover:bg-slate-100"
                            >
                                Kembali ke inbox
                            </Link>

                            {can.download && (
                                <a
                                    href={`/businesses/${business.id}/documents/${document.id}/download`}
                                    className="rounded-md border border-slate-300 px-3 py-1 text-sm hover:bg-slate-100"
                                >
                                    Unduh berkas asli
                                </a>
                            )}

                            {can.reprocess && document.is_retryable && (
                                <button
                                    type="button"
                                    onClick={() =>
                                        router.post(
                                            `/businesses/${business.id}/documents/${document.id}/reprocess`,
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                    className="rounded-md bg-teal-600 px-3 py-1 text-sm font-medium text-white hover:bg-teal-700"
                                >
                                    Proses ulang
                                </button>
                            )}

                            {can.archive && !document.archived_at && (
                                <button
                                    type="button"
                                    onClick={() =>
                                        router.post(
                                            `/businesses/${business.id}/documents/${document.id}/archive`,
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                    className="rounded-md border border-slate-300 px-3 py-1 text-sm hover:bg-slate-100"
                                >
                                    Arsipkan
                                </button>
                            )}
                        </div>
                    }
                >
                    <dl className="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <dt className="text-slate-500">Status</dt>
                            <dd className="mt-1">
                                <StatusBadge
                                    status={document.processing_status}
                                    label={document.processing_status_label}
                                />
                            </dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">Jenis dokumen</dt>
                            <dd className="mt-1 text-slate-800">
                                {document.document_type_label ?? 'Belum ditentukan'}
                                {document.document_type_source_label && (
                                    <span className="ml-1 text-xs text-slate-500">
                                        ({document.document_type_source_label})
                                    </span>
                                )}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">Keyakinan</dt>
                            <dd className="mt-1">
                                <ConfidenceBadge
                                    confidence={document.confidence?.score ?? null}
                                    band={document.confidence?.band ?? null}
                                    label={document.confidence?.band_label ?? null}
                                />
                            </dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">Tanggal dokumen</dt>
                            <dd className="mt-1 text-slate-800">{document.document_date ?? '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">Total</dt>
                            <dd className="mt-1 text-slate-800">
                                {document.total === null
                                    ? '—'
                                    : `${document.currency ?? ''} ${document.total}`.trim()}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">Ukuran</dt>
                            <dd className="mt-1 text-slate-800">
                                {formatBytes(document.byte_size)}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">Halaman terbaca</dt>
                            <dd className="mt-1 text-slate-800">{document.page_count ?? '—'}</dd>
                        </div>
                        <div className="sm:col-span-2 lg:col-span-4">
                            <dt className="text-slate-500">Checksum SHA-256</dt>
                            <dd className="mt-1 break-all font-mono text-xs text-slate-600">
                                {document.checksum_sha256}
                            </dd>
                        </div>
                    </dl>

                    {document.failure_reason && (
                        <p className="mt-4 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                            {document.failure_reason}
                        </p>
                    )}
                </Card>

                {/*
                 * Panel review hanya muncul setelah tahap kecerdasan dokumen menghasilkan
                 * sesuatu untuk diperiksa. Menampilkannya pada dokumen yang baru diunggah
                 * hanya akan menyajikan formulir kosong.
                 */}
                {(document.classification !== null ||
                    document.fields.length > 0 ||
                    document.extractions.length > 0) && (
                    <DocumentReviewPanel
                        businessId={business.id}
                        document={document}
                        canReview={can.review}
                    />
                )}

                <Card
                    title="Riwayat Proses"
                    description="Setiap percobaan tahap pipeline beserta durasi dan penyebab kegagalannya."
                >
                    <ol className="space-y-3">
                        {document.processing_jobs.map((job) => (
                            <li
                                key={job.id}
                                className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-slate-200 px-3 py-2 text-sm"
                            >
                                <div className="min-w-0">
                                    <p className="font-medium text-slate-800">
                                        {job.stage_label}
                                        {job.attempt > 1 && (
                                            <span className="ml-1 text-xs text-slate-500">
                                                percobaan {job.attempt}
                                            </span>
                                        )}
                                    </p>
                                    <p className="mt-0.5 text-xs text-slate-500">
                                        {job.stage_implemented
                                            ? `${formatDateTime(job.finished_at ?? job.started_at ?? job.queued_at)} · ${formatDuration(job.duration_ms)}`
                                            : `Dibangun pada Phase ${job.stage_phase}`}
                                    </p>
                                    {job.error_message && job.stage_implemented && (
                                        <p className="mt-1 text-xs text-rose-600">
                                            {job.error_message}
                                        </p>
                                    )}
                                </div>

                                <StatusBadge status={job.status} label={job.status_label} />
                            </li>
                        ))}
                    </ol>
                </Card>

                <Card
                    title="Isi Berkas"
                    description={
                        document.needs_ocr
                            ? 'Sebagian halaman memerlukan OCR sehingga teksnya belum terbaca.'
                            : 'Hasil pembacaan berkas oleh parser, tanpa penafsiran apa pun.'
                    }
                >
                    {document.pages.length === 0 ? (
                        <p className="text-sm text-slate-500">
                            Berkas belum dibaca. Halaman akan muncul setelah proses selesai.
                        </p>
                    ) : (
                        <>
                            <nav className="mb-4 flex flex-wrap gap-2">
                                {document.pages.map((item, index) => (
                                    <button
                                        key={item.id}
                                        type="button"
                                        onClick={() => setActivePage(index)}
                                        className={`rounded-md px-3 py-1 text-sm ${
                                            index === activePage
                                                ? 'bg-slate-800 text-white'
                                                : 'bg-slate-100 text-slate-700 hover:bg-slate-200'
                                        }`}
                                    >
                                        {item.label ?? `Halaman ${item.page_number}`}
                                    </button>
                                ))}
                            </nav>

                            {page && <PagePreview page={page} />}
                        </>
                    )}
                </Card>
            </div>
        </>
    );
}
