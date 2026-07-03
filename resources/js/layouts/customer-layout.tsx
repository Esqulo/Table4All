import { Link } from '@inertiajs/react';
import { Menu, QrCode, X } from 'lucide-react';
import type { PropsWithChildren } from 'react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

const appName = import.meta.env.VITE_APP_NAME || 'Table4All';

export default function CustomerLayout({ children }: PropsWithChildren) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);

    return (
        <div className="flex min-h-screen">
            {/* Mobile overlay */}
            {open && (
                <div
                    className="fixed inset-0 z-20 bg-black/40 lg:hidden"
                    onClick={() => setOpen(false)}
                />
            )}

            {/* Left sidebar */}
            <aside
                className={[
                    'fixed inset-y-0 left-0 z-30 flex w-56 flex-col border-r border-border bg-card transition-transform duration-200',
                    'lg:relative lg:translate-x-0',
                    open ? 'translate-x-0' : '-translate-x-full',
                ].join(' ')}
            >
                <div className="flex h-14 shrink-0 items-center justify-between border-b border-border px-4">
                    <span className="font-semibold tracking-tight">{appName}</span>
                    <button
                        className="rounded-md p-1 text-muted-foreground hover:bg-muted lg:hidden"
                        onClick={() => setOpen(false)}
                    >
                        <X className="h-4 w-4" />
                    </button>
                </div>

                <nav className="flex-1 p-2">
                    <Link
                        href="/mesa"
                        className="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                        onClick={() => setOpen(false)}
                    >
                        <QrCode className="h-4 w-4 shrink-0" />
                        {t('join_table.title')}
                    </Link>
                </nav>
            </aside>

            {/* Main area */}
            <div className="flex min-w-0 flex-1 flex-col">
                {/* Mobile top bar */}
                <header className="flex h-12 shrink-0 items-center border-b border-border bg-card px-4 lg:hidden">
                    <button
                        className="rounded-md p-1 text-muted-foreground hover:bg-muted"
                        onClick={() => setOpen(true)}
                        aria-label="Open menu"
                    >
                        <Menu className="h-5 w-5" />
                    </button>
                </header>

                <main className="flex flex-1 flex-col">
                    {children}
                </main>
            </div>
        </div>
    );
}
