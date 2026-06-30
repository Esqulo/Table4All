import { Head, Link } from '@inertiajs/react';
import { Plus, Printer, QrCode, ScrollText } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import MenuController from '@/actions/App/Http/Controllers/Restaurant/MenuController';
import { QRCustomizerDialog } from '@/components/qr-customizer';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import type { Menu } from '@/types';

type QRMenu = Pick<Menu, 'id' | 'name'> & { url: string };

type Props = {
    menus: (Menu & { url: string })[];
};

export default function Menus({ menus }: Props) {
    const { t } = useTranslation();
    const [qrMenu, setQrMenu] = useState<QRMenu | null>(null);

    return (
        <>
            <Head title={t('menus.title')} />

            <div className="space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <Heading title={t('menus.title')} />
                    <Button asChild>
                        <Link href={MenuController.create.url()}>
                            <Plus className="mr-2 h-4 w-4" />
                            {t('menus.new')}
                        </Link>
                    </Button>
                </div>

                {menus.length === 0 ? (
                    <p className="text-center text-sm text-muted-foreground py-12">
                        {t('menus.empty')}
                    </p>
                ) : (
                    <ul className="divide-y divide-border rounded-lg border border-border">
                        {menus.map((menu) => (
                            <li key={menu.id} className="flex items-center justify-between gap-3 px-4 py-3">
                                <div className="flex items-center gap-3 min-w-0">
                                    <ScrollText className="h-4 w-4 text-muted-foreground shrink-0" />
                                    <span className="text-sm font-medium truncate">{menu.name}</span>
                                </div>
                                <div className="flex items-center gap-2 shrink-0">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setQrMenu(menu)}
                                    >
                                        <QrCode className="mr-1.5 h-4 w-4" />
                                        {t('menus.qr_code')}
                                    </Button>
                                    <Button variant="outline" size="sm" asChild>
                                        <Link href={MenuController.printMenu.url({ menu: menu.id })}>
                                            <Printer className="mr-1.5 h-4 w-4" />
                                            {t('menus.print_menu')}
                                        </Link>
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <QRCustomizerDialog menu={qrMenu} onClose={() => setQrMenu(null)} />
        </>
    );
}

Menus.layout = {
    breadcrumbs: [
        {
            title: 'menus.breadcrumb_index',
            href: MenuController.index.url(),
        },
    ],
};
