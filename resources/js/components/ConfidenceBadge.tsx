import type { ConfidenceAssessment, ConfidenceBand } from '@/types';

const TONE: Record<ConfidenceBand, string> = {
    ready: 'bg-teal-100 text-teal-800',
    review_recommended: 'bg-amber-100 text-amber-800',
    human_confirmation_required: 'bg-rose-100 text-rose-800',
};

/**
 * Confidence ditampilkan sebagai persen bulat, bukan desimal empat angka.
 *
 * Angka aslinya tetap string desimal dari backend dan tidak pernah dihitung di sini
 * (plan.md §15). Pembulatan hanya untuk dibaca manusia: "0,8412" tidak lebih informatif
 * daripada "84%" bagi pemilik usaha yang harus memutuskan.
 */
export function formatConfidence(value: string | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const numeric = Number(value);

    if (Number.isNaN(numeric)) {
        return value;
    }

    return `${Math.round(numeric * 100)}%`;
}

/**
 * Pita untuk satu nilai terhadap ambang dokumen (plan.md §15.2).
 *
 * Keputusan pita dokumen tetap dihitung backend; ini hanya mewarnai badge per field supaya
 * angka 88% tidak tampil senetral 100%. Ambangnya diambil dari assessment dokumen agar
 * warnanya mengikuti pengaturan bisnis, bukan konstanta yang tertanam di frontend.
 */
export function bandFor(
    confidence: string | null | undefined,
    assessment: ConfidenceAssessment | null | undefined,
): ConfidenceBand | null {
    if (confidence === null || confidence === undefined || !assessment) {
        return null;
    }

    const numeric = Number(confidence);

    if (Number.isNaN(numeric)) {
        return null;
    }

    if (numeric >= Number(assessment.auto_ready_threshold)) {
        return 'ready';
    }

    return numeric >= Number(assessment.review_threshold)
        ? 'review_recommended'
        : 'human_confirmation_required';
}

interface Props {
    confidence: string | null;
    band?: ConfidenceBand | null;
    label?: string | null;
}

export default function ConfidenceBadge({ confidence, band, label }: Props) {
    if (confidence === null && !band) {
        return <span className="text-xs text-slate-400">—</span>;
    }

    const tone = band ? TONE[band] : 'bg-slate-100 text-slate-700';

    return (
        <span
            className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${tone}`}
            title={label ?? undefined}
        >
            {formatConfidence(confidence)}
            {label && <span className="font-normal">· {label}</span>}
        </span>
    );
}
