import { Head, router } from '@inertiajs/react';

import Card from '@/components/Card';
import { formatDate, formatMoney } from '@/lib/money';
import type { TrialBalanceReport } from '@/types';

interface Props {
    business: { id: string; name: string };
    report: TrialBalanceReport;
    periods: { id: string; name: string; status: string }[];
    selectedPeriod: string | null;
}

export default function TrialBalance({ business, report, periods, selectedPeriod }: Props) {
    return (
        <>
            <Head title={`Neraca Saldo — ${business.name}`} />

            <Card
                title="Neraca Saldo"
                description={`Hanya journal berstatus posted yang dihitung. Periode ${formatDate(report.from)} – ${formatDate(report.to)}.`}
                actions={
                    <select
                        value={selectedPeriod ?? ''}
                        onChange={(event) =>
                            router.get(
                                `/businesses/${business.id}/reports/trial-balance`,
                                event.target.value ? { period: event.target.value } : {},
                                { preserveState: true },
                            )
                        }
                        className="rounded-md border border-slate-300 px-2 py-1 text-sm"
                    >
                        {periods.map((period) => (
                            <option key={period.id} value={period.id}>
                                {period.name}
                            </option>
                        ))}
                    </select>
                }
            >
                {report.rows.length === 0 ? (
                    <p className="text-sm text-slate-500">
                        Belum ada journal terposting pada periode ini.
                    </p>
                ) : (
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 text-left text-slate-500">
                                <th className="py-2 font-medium">Kode</th>
                                <th className="py-2 font-medium">Akun</th>
                                <th className="py-2 text-right font-medium">Mutasi Debit</th>
                                <th className="py-2 text-right font-medium">Mutasi Kredit</th>
                                <th className="py-2 text-right font-medium">Saldo Debit</th>
                                <th className="py-2 text-right font-medium">Saldo Kredit</th>
                            </tr>
                        </thead>
                        <tbody>
                            {report.rows.map((row) => (
                                <tr key={row.account_id} className="border-b border-slate-100">
                                    <td className="py-2 font-mono tabular-nums">{row.code}</td>
                                    <td className="py-2">{row.name}</td>
                                    <td className="py-2 text-right tabular-nums text-slate-600">
                                        {formatMoney(row.movement_debit)}
                                    </td>
                                    <td className="py-2 text-right tabular-nums text-slate-600">
                                        {formatMoney(row.movement_credit)}
                                    </td>
                                    <td className="py-2 text-right tabular-nums">
                                        {Number(row.debit_balance) === 0 ? '' : formatMoney(row.debit_balance)}
                                    </td>
                                    <td className="py-2 text-right tabular-nums">
                                        {Number(row.credit_balance) === 0 ? '' : formatMoney(row.credit_balance)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot>
                            <tr className="font-semibold">
                                <td className="py-2" colSpan={4}>
                                    Total
                                </td>
                                <td className="py-2 text-right tabular-nums">{formatMoney(report.total_debit)}</td>
                                <td className="py-2 text-right tabular-nums">{formatMoney(report.total_credit)}</td>
                            </tr>
                        </tfoot>
                    </table>
                )}

                <p
                    className={`mt-4 text-sm ${report.is_balanced ? 'text-teal-700' : 'text-rose-700'}`}
                >
                    {report.is_balanced
                        ? 'Total debit sama dengan total kredit.'
                        : 'Total debit tidak sama dengan total kredit — ledger perlu diperiksa.'}
                </p>
            </Card>
        </>
    );
}
