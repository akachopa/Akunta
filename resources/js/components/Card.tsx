import type { ReactNode } from 'react';

interface CardProps {
    title?: string;
    description?: string;
    actions?: ReactNode;
    children: ReactNode;
}

export default function Card({ title, description, actions, children }: CardProps) {
    return (
        <section className="rounded-lg border border-slate-200 bg-white shadow-sm">
            {(title || actions) && (
                <header className="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4">
                    <div>
                        {title && (
                            <h2 className="text-base font-semibold text-slate-900">{title}</h2>
                        )}
                        {description && (
                            <p className="mt-1 text-sm text-slate-500">{description}</p>
                        )}
                    </div>
                    {actions}
                </header>
            )}

            <div className="px-5 py-4">{children}</div>
        </section>
    );
}
