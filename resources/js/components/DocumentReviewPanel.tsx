import { router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

import Card from '@/components/Card';
import ConfidenceBadge, { bandFor, formatConfidence } from '@/components/ConfidenceBadge';
import StatusBadge from '@/components/StatusBadge';
import { formatDateTime } from '@/lib/money';
import type { DocumentDetail, DocumentField } from '@/types';

interface Props {
    businessId: string;
    document: DocumentDetail;
    canReview: boolean;
}

/**
 * Tipe input per jenis field.
 *
 * Uang memakai input teks dengan pola desimal, bukan `type="number"`, karena input number
 * pada beberapa peramban mengirim notasi eksponen dan membulatkan nilai panjang. Nilai uang
 * harus sampai ke server apa adanya (plan.md §44.14).
 */
function inputProps(field: DocumentField) {
    switch (field.kind) {
        case 'money':
            return { type: 'text', inputMode: 'decimal' as const, pattern: '-?\\d+(\\.\\d{1,2})?' };
        case 'integer':
            return { type: 'number', min: 0, step: 1 };
        case 'date':
            return { type: 'date' };
        default:
            return { type: 'text' };
    }
}

export default function DocumentReviewPanel({ businessId, document, canReview }: Props) {
    const [documentType, setDocumentType] = useState(document.document_type ?? '');
    const [values, setValues] = useState<Record<string, string>>({});
    const [openEvidence, setOpenEvidence] = useState<string | null>(null);

    const base = `/businesses/${businessId}/documents/${document.id}`;

    const rejected = useMemo(
        () => document.extractions.find((attempt) => attempt.status === 'rejected'),
        [document.extractions],
    );

    const hasAcceptedExtraction = document.extractions.some(
        (attempt) => attempt.status === 'accepted',
    );

    const submitType = () => {
        router.post(
            `${base}/review/type`,
            { document_type: documentType },
            { preserveScroll: true },
        );
    };

    /*
     * Hanya field yang benar-benar disentuh reviewer yang dikirim. Mengirim seluruh field
     * akan menandai semuanya terkonfirmasi, termasuk yang tidak pernah dilihat, dan itu
     * tepat jenis persetujuan diam-diam yang dilarang plan.md §44.8.
     */
    const submitFields = () => {
        const touched = Object.keys(values);

        if (touched.length === 0) {
            return;
        }

        router.post(
            `${base}/review/fields`,
            { fields: values },
            { preserveScroll: true, onSuccess: () => setValues({}) },
        );
    };

    return (
        <Card
            title="Review Data Dokumen"
            description="Nilai hasil pembacaan beserta tingkat keyakinan dan cuplikan sumbernya."
            actions={
                canReview &&
                hasAcceptedExtraction &&
                document.processing_status === 'need_review' && (
                    <button
                        type="button"
                        onClick={() =>
                            router.post(`${base}/review/approve`, {}, { preserveScroll: true })
                        }
                        className="rounded-md bg-teal-600 px-3 py-1 text-sm font-medium text-white hover:bg-teal-700"
                    >
                        Setujui dokumen
                    </button>
                )
            }
        >
            {document.review_reason && (
                <p className="mb-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                    {document.review_reason}
                </p>
            )}

            {rejected && rejected.validation_errors.length > 0 && (
                <div className="mb-4 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                    <p className="font-medium">
                        Percobaan pembacaan ke-{rejected.attempt} ditolak validasi:
                    </p>
                    <ul className="mt-1 list-inside list-disc space-y-0.5 text-xs">
                        {rejected.validation_errors.map((error) => (
                            <li key={error}>{error}</li>
                        ))}
                    </ul>
                </div>
            )}

            <div className="space-y-6">
                <section>
                    <h3 className="text-sm font-semibold text-slate-800">Jenis dokumen</h3>

                    {document.classification && (
                        <p className="mt-1 text-xs text-slate-500">
                            Prediksi AI: {document.classification.predicted_label} (
                            {formatConfidence(document.classification.confidence)})
                            {document.classification.candidates.length > 0 && (
                                <>
                                    {' '}
                                    · alternatif:{' '}
                                    {document.classification.candidates
                                        .map(
                                            (candidate) =>
                                                `${candidate.label} (${formatConfidence(candidate.confidence)})`,
                                        )
                                        .join(', ')}
                                </>
                            )}
                        </p>
                    )}

                    <div className="mt-2 flex flex-wrap items-center gap-2">
                        <select
                            value={documentType}
                            onChange={(event) => setDocumentType(event.target.value)}
                            disabled={!canReview}
                            className="rounded-md border border-slate-300 px-3 py-1.5 text-sm disabled:bg-slate-50"
                        >
                            <option value="">Pilih jenis dokumen</option>
                            {document.documentTypes.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>

                        {canReview && (
                            <button
                                type="button"
                                onClick={submitType}
                                disabled={documentType === ''}
                                className="rounded-md border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-100 disabled:opacity-50"
                            >
                                Simpan jenis dokumen
                            </button>
                        )}

                        {document.document_type_source_label && (
                            <span className="text-xs text-slate-500">
                                {document.document_type_source_label}
                            </span>
                        )}
                    </div>
                </section>

                <section>
                    <h3 className="text-sm font-semibold text-slate-800">Data yang terbaca</h3>

                    {document.fields.length === 0 ? (
                        <p className="mt-2 text-sm text-slate-500">
                            Belum ada data yang lolos validasi untuk dokumen ini.
                        </p>
                    ) : (
                        <>
                            <ul className="mt-2 divide-y divide-slate-100">
                                {document.fields.map((field) => {
                                    const draft = values[field.key];
                                    const current = draft ?? field.value ?? '';

                                    return (
                                        <li key={field.id} className="py-3">
                                            <div className="flex flex-wrap items-center gap-3">
                                                <label
                                                    htmlFor={`field-${field.key}`}
                                                    className="w-40 shrink-0 text-sm text-slate-600"
                                                >
                                                    {field.label}
                                                </label>

                                                <input
                                                    id={`field-${field.key}`}
                                                    {...inputProps(field)}
                                                    value={current}
                                                    disabled={!canReview}
                                                    onChange={(event) =>
                                                        setValues({
                                                            ...values,
                                                            [field.key]: event.target.value,
                                                        })
                                                    }
                                                    className="w-56 rounded-md border border-slate-300 px-2 py-1 text-sm disabled:bg-slate-50"
                                                />

                                                <ConfidenceBadge
                                                    confidence={field.confidence}
                                                    band={bandFor(
                                                        field.confidence,
                                                        document.confidence,
                                                    )}
                                                />

                                                {field.is_confirmed && (
                                                    <span className="text-xs text-teal-700">
                                                        dikonfirmasi {field.confirmed_by ?? ''}
                                                    </span>
                                                )}

                                                {field.source === 'review' && (
                                                    <span className="text-xs text-violet-700">
                                                        {field.source_label}
                                                    </span>
                                                )}

                                                {field.source_text && (
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            setOpenEvidence(
                                                                openEvidence === field.key
                                                                    ? null
                                                                    : field.key,
                                                            )
                                                        }
                                                        className="text-xs text-slate-500 underline hover:text-slate-700"
                                                    >
                                                        {openEvidence === field.key
                                                            ? 'sembunyikan sumber'
                                                            : 'lihat sumber'}
                                                    </button>
                                                )}
                                            </div>

                                            {/*
                                             * plan.md §45.10 dan §45.12: reviewer harus dapat
                                             * memeriksa asal nilainya, bukan hanya
                                             * mempercayainya.
                                             */}
                                            {openEvidence === field.key && field.source_text && (
                                                <p className="mt-2 ml-40 rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600">
                                                    {field.page_number !== null && (
                                                        <span className="mr-1 font-medium">
                                                            Halaman {field.page_number}:
                                                        </span>
                                                    )}
                                                    {field.source_text}
                                                </p>
                                            )}
                                        </li>
                                    );
                                })}
                            </ul>

                            {canReview && (
                                <div className="mt-3 flex items-center gap-3">
                                    <button
                                        type="button"
                                        onClick={submitFields}
                                        disabled={Object.keys(values).length === 0}
                                        className="rounded-md bg-slate-800 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-900 disabled:opacity-50"
                                    >
                                        Simpan &amp; konfirmasi data
                                    </button>

                                    <p className="text-xs text-slate-500">
                                        Hanya data yang Anda ubah atau sentuh yang ditandai
                                        terkonfirmasi.
                                    </p>
                                </div>
                            )}
                        </>
                    )}
                </section>

                {document.rows.length > 0 && (
                    <section>
                        <h3 className="text-sm font-semibold text-slate-800">
                            Baris mutasi ({document.rows.length})
                        </h3>
                        <p className="mt-1 text-xs text-slate-500">
                            Baris dibaca apa adanya dari dokumen. Pencocokan dengan transaksi
                            dikerjakan pada tahap rekonsiliasi.
                        </p>

                        <div className="mt-2 overflow-x-auto">
                            <table className="min-w-full divide-y divide-slate-200 text-xs">
                                <thead className="text-left text-slate-500">
                                    <tr>
                                        <th className="px-2 py-1">Tanggal</th>
                                        <th className="px-2 py-1">Keterangan</th>
                                        <th className="px-2 py-1 text-right">Debit</th>
                                        <th className="px-2 py-1 text-right">Kredit</th>
                                        <th className="px-2 py-1 text-right">Saldo</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 text-slate-700">
                                    {document.rows.map((row) => (
                                        <tr key={row.row_index}>
                                            <td className="whitespace-nowrap px-2 py-1">
                                                {row.values.date ?? '—'}
                                            </td>
                                            <td className="px-2 py-1">
                                                {row.values.description ?? '—'}
                                            </td>
                                            <td className="whitespace-nowrap px-2 py-1 text-right">
                                                {row.values.debit ?? '—'}
                                            </td>
                                            <td className="whitespace-nowrap px-2 py-1 text-right">
                                                {row.values.credit ?? '—'}
                                            </td>
                                            <td className="whitespace-nowrap px-2 py-1 text-right">
                                                {row.values.balance ?? '—'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                )}

                {document.extractions.length > 0 && (
                    <section>
                        <h3 className="text-sm font-semibold text-slate-800">
                            Riwayat pembacaan data
                        </h3>

                        <ul className="mt-2 space-y-2">
                            {document.extractions.map((attempt) => (
                                <li
                                    key={attempt.id}
                                    className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-slate-200 px-3 py-2 text-xs"
                                >
                                    <div>
                                        <p className="text-slate-700">
                                            Percobaan {attempt.attempt} · {attempt.extractor} v
                                            {attempt.schema_version} · {attempt.document_type_label}
                                        </p>
                                        <p className="mt-0.5 text-slate-500">
                                            {formatDateTime(attempt.created_at)} · keyakinan{' '}
                                            {formatConfidence(attempt.confidence)}
                                        </p>
                                    </div>

                                    <StatusBadge
                                        status={
                                            attempt.status === 'accepted' ? 'succeeded' : 'failed'
                                        }
                                        label={attempt.status_label}
                                    />
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </Card>
    );
}
