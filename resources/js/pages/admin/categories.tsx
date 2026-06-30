import { Form, Head } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useRef } from 'react';
import { useTranslation } from 'react-i18next';
import CategoryController from '@/actions/App/Http/Controllers/Admin/CategoryController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { Category } from '@/types';

type Props = {
    categories: Category[];
};

export default function Categories({ categories }: Props) {
    const { t } = useTranslation();
    const inputRef = useRef<HTMLInputElement>(null);

    return (
        <>
            <Head title={t('categories.title')} />

            <div className="space-y-6 p-6">
                <Heading title={t('categories.title')} />

                <Form
                    {...CategoryController.store.form()}
                    className="flex gap-2"
                    onSuccess={() => {
                        if (inputRef.current) inputRef.current.value = '';
                    }}
                >
                    {({ processing }) => (
                        <>
                            <Input
                                ref={inputRef}
                                name="name"
                                placeholder={t('categories.new_placeholder')}
                                className="max-w-xs"
                                required
                            />
                            <Button type="submit" disabled={processing}>
                                {t('categories.add')}
                            </Button>
                        </>
                    )}
                </Form>

                {categories.length === 0 ? (
                    <p className="text-center text-sm text-muted-foreground py-12">
                        {t('categories.empty')}
                    </p>
                ) : (
                    <ul className="divide-y divide-border rounded-lg border border-border">
                        {categories.map((category) => (
                            <li key={category.id} className="flex items-center justify-between px-4 py-3">
                                <span className="text-sm font-medium">{category.name}</span>
                                <Form
                                    {...CategoryController.destroy.form({ category: category.id })}
                                    onBefore={() => confirm(t('categories.delete_confirm'))}
                                >
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            variant="ghost"
                                            size="sm"
                                            disabled={processing}
                                            className="text-destructive hover:text-destructive"
                                        >
                                            <Trash2 className="h-4 w-4" />
                                        </Button>
                                    )}
                                </Form>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

Categories.layout = {
    breadcrumbs: [
        {
            title: 'categories.breadcrumb_index',
            href: CategoryController.index.url(),
        },
    ],
};
