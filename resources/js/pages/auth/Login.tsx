import { Head, Link, useForm } from '@inertiajs/react';

export default function Login() {
    const form = useForm({ email: '', password: '', remember: false });

    return (
        <div className="flex min-h-screen items-center justify-center bg-slate-50 px-4">
            <Head title="Masuk" />

            <div className="w-full max-w-sm rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                <h1 className="text-xl font-semibold text-teal-700">Akunta</h1>
                <p className="mt-1 text-sm text-slate-500">Masuk ke workspace akuntansi Anda.</p>

                <form
                    className="mt-6 space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post('/login');
                    }}
                >
                    <div>
                        <label htmlFor="email" className="block text-sm font-medium text-slate-700">
                            Email
                        </label>
                        <input
                            id="email"
                            type="email"
                            autoComplete="email"
                            className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                            value={form.data.email}
                            onChange={(event) => form.setData('email', event.target.value)}
                        />
                        {form.errors.email && (
                            <p className="mt-1 text-sm text-rose-600">{form.errors.email}</p>
                        )}
                    </div>

                    <div>
                        <label
                            htmlFor="password"
                            className="block text-sm font-medium text-slate-700"
                        >
                            Password
                        </label>
                        <input
                            id="password"
                            type="password"
                            autoComplete="current-password"
                            className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                            value={form.data.password}
                            onChange={(event) => form.setData('password', event.target.value)}
                        />
                        {form.errors.password && (
                            <p className="mt-1 text-sm text-rose-600">{form.errors.password}</p>
                        )}
                    </div>

                    <label className="flex items-center gap-2 text-sm text-slate-600">
                        <input
                            type="checkbox"
                            checked={form.data.remember}
                            onChange={(event) => form.setData('remember', event.target.checked)}
                        />
                        Ingat saya
                    </label>

                    <button
                        type="submit"
                        disabled={form.processing}
                        className="w-full rounded-md bg-teal-700 px-4 py-2 text-sm font-medium text-white hover:bg-teal-800 disabled:opacity-60"
                    >
                        Masuk
                    </button>
                </form>

                <p className="mt-4 text-sm text-slate-500">
                    Belum punya akun?{' '}
                    <Link href="/register" className="text-teal-700 hover:underline">
                        Daftar
                    </Link>
                </p>
            </div>
        </div>
    );
}
