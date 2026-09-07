import { Head, useForm } from '@inertiajs/react';

import Card from '@/components/Card';

interface Option {
    value: string;
    label: string;
}

interface CoaTemplateOption {
    code: string;
    name: string;
    business_type: string;
}

interface Props {
    businessTypes: Option[];
    accountingBases: Option[];
    coaTemplates: CoaTemplateOption[];
}

export default function BusinessCreate({ businessTypes, accountingBases, coaTemplates }: Props) {
    const form = useForm({
        name: '',
        legal_name: '',
        business_type: businessTypes[0]?.value ?? 'general',
        accounting_basis: accountingBases[0]?.value ?? 'accrual',
        currency: 'IDR',
        opening_date: new Date().toISOString().slice(0, 10),
        coa_template_code: '',
        bank_accounts: [{ label: '', bank_name: '', account_number: '' }],
    });

    return (
        <>
            <Head title="Tambah Bisnis" />

            <Card
                title="Onboarding Bisnis"
                description="Sistem akan langsung membuat starter chart of accounts dan periode akuntansi bulanan."
            >
                <form
                    className="space-y-5"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) => ({
                            ...data,
                            coa_template_code: data.coa_template_code || undefined,
                            bank_accounts: data.bank_accounts.filter(
                                (account) => account.label && account.bank_name && account.account_number,
                            ),
                        }));
                        form.post('/businesses');
                    }}
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Nama Bisnis" error={form.errors.name}>
                            <input
                                className="input"
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                            />
                        </Field>

                        <Field label="Legal Name (opsional)" error={form.errors.legal_name}>
                            <input
                                className="input"
                                value={form.data.legal_name}
                                onChange={(event) => form.setData('legal_name', event.target.value)}
                            />
                        </Field>

                        <Field label="Jenis Usaha" error={form.errors.business_type}>
                            <select
                                className="input"
                                value={form.data.business_type}
                                onChange={(event) =>
                                    form.setData('business_type', event.target.value)
                                }
                            >
                                {businessTypes.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </Field>

                        <Field label="Basis Akuntansi" error={form.errors.accounting_basis}>
                            <select
                                className="input"
                                value={form.data.accounting_basis}
                                onChange={(event) =>
                                    form.setData('accounting_basis', event.target.value)
                                }
                            >
                                {accountingBases.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </Field>

                        <Field label="Mata Uang" error={form.errors.currency}>
                            <input
                                className="input"
                                value={form.data.currency}
                                onChange={(event) => form.setData('currency', event.target.value)}
                            />
                        </Field>

                        <Field label="Opening Date" error={form.errors.opening_date}>
                            <input
                                type="date"
                                className="input"
                                value={form.data.opening_date}
                                onChange={(event) =>
                                    form.setData('opening_date', event.target.value)
                                }
                            />
                        </Field>

                        <Field
                            label="COA Template (opsional)"
                            error={form.errors.coa_template_code}
                        >
                            <select
                                className="input"
                                value={form.data.coa_template_code}
                                onChange={(event) =>
                                    form.setData('coa_template_code', event.target.value)
                                }
                            >
                                <option value="">Otomatis sesuai jenis usaha</option>
                                {coaTemplates.map((template) => (
                                    <option key={template.code} value={template.code}>
                                        {template.name}
                                    </option>
                                ))}
                            </select>
                        </Field>
                    </div>

                    <fieldset className="rounded-md border border-slate-200 p-4">
                        <legend className="px-1 text-sm font-medium text-slate-700">
                            Rekening Bank Awal (opsional)
                        </legend>

                        <div className="grid gap-3 sm:grid-cols-3">
                            <input
                                className="input"
                                placeholder="Label, mis. Bank Operasional"
                                value={form.data.bank_accounts[0]?.label ?? ''}
                                onChange={(event) =>
                                    form.setData('bank_accounts', [
                                        {
                                            ...form.data.bank_accounts[0]!,
                                            label: event.target.value,
                                        },
                                    ])
                                }
                            />
                            <input
                                className="input"
                                placeholder="Nama bank"
                                value={form.data.bank_accounts[0]?.bank_name ?? ''}
                                onChange={(event) =>
                                    form.setData('bank_accounts', [
                                        {
                                            ...form.data.bank_accounts[0]!,
                                            bank_name: event.target.value,
                                        },
                                    ])
                                }
                            />
                            <input
                                className="input"
                                placeholder="Nomor rekening"
                                value={form.data.bank_accounts[0]?.account_number ?? ''}
                                onChange={(event) =>
                                    form.setData('bank_accounts', [
                                        {
                                            ...form.data.bank_accounts[0]!,
                                            account_number: event.target.value,
                                        },
                                    ])
                                }
                            />
                        </div>
                    </fieldset>

                    <button
                        type="submit"
                        disabled={form.processing}
                        className="rounded-md bg-teal-700 px-4 py-2 text-sm font-medium text-white hover:bg-teal-800 disabled:opacity-60"
                    >
                        Buat Bisnis
                    </button>
                </form>
            </Card>
        </>
    );
}

function Field({
    label,
    error,
    children,
}: {
    label: string;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <label className="block">
            <span className="block text-sm font-medium text-slate-700">{label}</span>
            <div className="mt-1">{children}</div>
            {error && <p className="mt-1 text-sm text-rose-600">{error}</p>}
        </label>
    );
}
