import { Head, router, useForm } from '@inertiajs/react';

import Card from '@/components/Card';
import { formatDate } from '@/lib/money';

interface MemberRow {
    id: string;
    user_id: string;
    name: string;
    email: string;
    role: string;
    role_label: string;
    is_external: boolean;
    joined_at: string | null;
}

interface Props {
    business: { id: string; name: string };
    members: MemberRow[];
    assignableRoles: { value: string; label: string }[];
}

export default function BusinessMembers({ business, members, assignableRoles }: Props) {
    const form = useForm({
        email: '',
        role: assignableRoles[0]?.value ?? '',
        is_external: false,
    });

    return (
        <>
            <Head title={`Member — ${business.name}`} />

            <div className="space-y-6">
                <Card
                    title="Member & Role"
                    description="Owner, staff, dan akuntan eksternal yang memiliki akses ke workspace ini."
                >
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 text-left text-slate-500">
                                <th className="py-2 font-medium">Nama</th>
                                <th className="py-2 font-medium">Email</th>
                                <th className="py-2 font-medium">Role</th>
                                <th className="py-2 font-medium">Eksternal</th>
                                <th className="py-2 font-medium">Bergabung</th>
                                <th className="py-2" />
                            </tr>
                        </thead>
                        <tbody>
                            {members.map((member) => (
                                <tr key={member.id} className="border-b border-slate-100">
                                    <td className="py-2 font-medium text-slate-900">{member.name}</td>
                                    <td className="py-2 text-slate-600">{member.email}</td>
                                    <td className="py-2">
                                        <select
                                            value={member.role}
                                            onChange={(event) =>
                                                router.patch(
                                                    `/businesses/${business.id}/members/${member.id}`,
                                                    { role: event.target.value },
                                                    { preserveScroll: true },
                                                )
                                            }
                                            className="rounded-md border border-slate-300 px-2 py-1"
                                        >
                                            {assignableRoles.map((role) => (
                                                <option key={role.value} value={role.value}>
                                                    {role.label}
                                                </option>
                                            ))}
                                        </select>
                                    </td>
                                    <td className="py-2 text-slate-600">{member.is_external ? 'Ya' : '—'}</td>
                                    <td className="py-2 text-slate-600">{formatDate(member.joined_at)}</td>
                                    <td className="py-2 text-right">
                                        <button
                                            type="button"
                                            onClick={() =>
                                                router.delete(
                                                    `/businesses/${business.id}/members/${member.id}`,
                                                    { preserveScroll: true },
                                                )
                                            }
                                            className="text-xs text-rose-600 hover:underline"
                                        >
                                            Lepas
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </Card>

                <Card
                    title="Tambah Member"
                    description="User harus sudah terdaftar di Akunta sebelum dapat dilekatkan ke bisnis."
                >
                    <form
                        className="flex flex-wrap items-end gap-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post(`/businesses/${business.id}/members`, {
                                preserveScroll: true,
                                onSuccess: () => form.reset('email'),
                            });
                        }}
                    >
                        <label className="text-sm">
                            <span className="block text-slate-600">Email</span>
                            <input
                                type="email"
                                value={form.data.email}
                                onChange={(event) => form.setData('email', event.target.value)}
                                className="mt-1 w-72 rounded-md border border-slate-300 px-3 py-2"
                                required
                            />
                            {form.errors.email && (
                                <span className="mt-1 block text-xs text-rose-600">{form.errors.email}</span>
                            )}
                        </label>

                        <label className="text-sm">
                            <span className="block text-slate-600">Role</span>
                            <select
                                value={form.data.role}
                                onChange={(event) => form.setData('role', event.target.value)}
                                className="mt-1 rounded-md border border-slate-300 px-3 py-2"
                            >
                                {assignableRoles.map((role) => (
                                    <option key={role.value} value={role.value}>
                                        {role.label}
                                    </option>
                                ))}
                            </select>
                        </label>

                        <label className="flex items-center gap-2 pb-2 text-sm text-slate-600">
                            <input
                                type="checkbox"
                                checked={form.data.is_external}
                                onChange={(event) => form.setData('is_external', event.target.checked)}
                            />
                            Akuntan eksternal
                        </label>

                        <button
                            type="submit"
                            disabled={form.processing}
                            className="rounded-md bg-teal-700 px-4 py-2 text-sm font-medium text-white hover:bg-teal-800 disabled:opacity-50"
                        >
                            Tambahkan
                        </button>
                    </form>
                </Card>
            </div>
        </>
    );
}
