import { Head, Link, useForm } from '@inertiajs/react';

export default function Register() {
    const form = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    return (
        <div className="flex min-h-screen items-center justify-center bg-slate-50 px-4">
            <Head title="Daftar" />

            <div className="w-full max-w-sm rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                <h1 className="text-xl font-semibold text-teal-700">Buat akun Akunta</h1>

                <form
                    className="mt-6 space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post('/register');
                    }}
                >
                    {(
                        [
                            ['name', 'Nama', 'text'],
                            ['email', 'Email', 'email'],
                            ['password', 'Password', 'password'],
                            ['password_confirmation', 'Konfirmasi Password', 'password'],
                        ] as const
                    ).map(([field, label, type]) => (
                        <div key={field}>
                            <label
                                htmlFor={field}
                                className="block text-sm font-medium text-slate-700"
                            >
                                {label}
                            </label>
                            <input
                                id={field}
                                type={type}
                                className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                                value={form.data[field]}
                                onChange={(event) => form.setData(field, event.target.value)}
                            />
                            {form.errors[field] && (
                                <p className="mt-1 text-sm text-rose-600">{form.errors[field]}</p>
                            )}
                        </div>
                    ))}

                    <button
                        type="submit"
                        disabled={form.processing}
                        className="w-full rounded-md bg-teal-700 px-4 py-2 text-sm font-medium text-white hover:bg-teal-800 disabled:opacity-60"
                    >
                        Daftar
                    </button>
                </form>

                <p className="mt-4 text-sm text-slate-500">
                    Sudah punya akun?{' '}
                    <Link href="/login" className="text-teal-700 hover:underline">
                        Masuk
                    </Link>
                </p>
            </div>
        </div>
    );
}
