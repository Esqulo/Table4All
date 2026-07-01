import { Form, Head, Link } from '@inertiajs/react';
import { useCallback, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import ProductController from '@/actions/App/Http/Controllers/Restaurant/ProductController';
import { ImageCropper } from '@/components/image-cropper';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import type { Category, PriceType, RestaurantQueue } from '@/types';

const PRICE_TYPES: PriceType[] = ['unit', 'kg', '100g', 'liter', 'portion'];

type Props = {
    categories: Category[];
    queues: RestaurantQueue[];
};

export default function CreateProduct({ categories, queues }: Props) {
    const { t } = useTranslation();
    const pickerRef = useRef<HTMLInputElement>(null);
    const formPictureRef = useRef<HTMLInputElement>(null);
    const [cropSrc, setCropSrc] = useState<string | null>(null);
    const [preview, setPreview] = useState<string | null>(null);

    const openPicker = () => pickerRef.current?.click();

    const onFileSelected = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (!file) return;
        setCropSrc(URL.createObjectURL(file));
        e.target.value = '';
    };

    const onCropConfirm = useCallback((blob: Blob) => {
        const file = new File([blob], 'picture.jpg', { type: 'image/jpeg' });
        const dt = new DataTransfer();
        dt.items.add(file);
        if (formPictureRef.current) formPictureRef.current.files = dt.files;
        setPreview(URL.createObjectURL(blob));
        setCropSrc(null);
    }, []);

    const onCropCancel = useCallback(() => {
        setCropSrc(null);
    }, []);

    return (
        <>
            <Head title={t('products.breadcrumb_create')} />

            {cropSrc && (
                <ImageCropper
                    open
                    src={cropSrc}
                    onConfirm={onCropConfirm}
                    onCancel={onCropCancel}
                    confirmLabel={t('products.crop_confirm')}
                    cancelLabel={t('products.cancel')}
                />
            )}

            {/* hidden picker — not inside the form so it doesn't submit */}
            <input ref={pickerRef} type="file" accept="image/*" className="hidden" onChange={onFileSelected} />

            <div className="mx-auto max-w-xl space-y-6 p-6">
                <h1 className="text-xl font-semibold">{t('products.breadcrumb_create')}</h1>

                <Form
                    {...ProductController.store.form()}
                    encType="multipart/form-data"
                    className="space-y-5"
                >
                    {({ processing, errors }) => (
                        <>
                            {categories.length > 0 && (
                                <div className="grid gap-2">
                                    <Label htmlFor="category_id">{t('products.category_label')}</Label>
                                    <Select name="category_id" defaultValue="">
                                        <SelectTrigger id="category_id">
                                            <SelectValue placeholder={t('products.category_none')} />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {categories.map((cat) => (
                                                <SelectItem key={cat.id} value={String(cat.id)}>
                                                    {cat.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.category_id} />
                                </div>
                            )}

                            {queues.length > 0 && (
                                <div className="grid gap-2">
                                    <Label htmlFor="queue_id">{t('products.queue_label')}</Label>
                                    <Select name="queue_id" defaultValue="">
                                        <SelectTrigger id="queue_id">
                                            <SelectValue placeholder={t('products.queue_none')} />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {queues.map((q) => (
                                                <SelectItem key={q.id} value={String(q.id)}>
                                                    {q.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.queue_id} />
                                </div>
                            )}

                            <div className="grid gap-2">
                                <Label htmlFor="name">{t('products.name_label')}</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    required
                                    autoFocus
                                    placeholder={t('products.name_placeholder')}
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="description">{t('products.description_label')}</Label>
                                <Textarea
                                    id="description"
                                    name="description"
                                    placeholder={t('products.description_placeholder')}
                                    rows={3}
                                />
                                <InputError message={errors.description} />
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="price">{t('products.price_label')}</Label>
                                    <Input
                                        id="price"
                                        name="price"
                                        type="number"
                                        min="0.01"
                                        step="0.01"
                                        required
                                        placeholder="0,00"
                                    />
                                    <InputError message={errors.price} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="price_type">{t('products.price_type_label')}</Label>
                                    <Select name="price_type" defaultValue="unit">
                                        <SelectTrigger id="price_type">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {PRICE_TYPES.map((pt) => (
                                                <SelectItem key={pt} value={pt}>
                                                    {t(`products.price_types.${pt}`)}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.price_type} />
                                </div>
                            </div>

                            <div className="grid gap-2">
                                <Label>{t('products.picture_label')}</Label>
                                <div className="flex items-center gap-4">
                                    {preview ? (
                                        <img
                                            src={preview}
                                            alt={t('products.picture_label')}
                                            className="h-24 w-24 rounded-lg object-cover border border-border"
                                        />
                                    ) : (
                                        <div className="h-24 w-24 rounded-lg border border-dashed border-border bg-muted" />
                                    )}
                                    <Button type="button" variant="outline" onClick={openPicker}>
                                        {preview ? t('products.picture_change') : t('products.picture_select')}
                                    </Button>
                                </div>
                                {/* actual file input submitted with the form */}
                                <input ref={formPictureRef} type="file" name="picture" className="hidden" readOnly />
                                <InputError message={errors.picture} />
                            </div>

                            <div className="flex gap-3 pt-2">
                                <Button type="submit" disabled={processing}>
                                    {t('products.submit_create')}
                                </Button>
                                <Button variant="outline" asChild>
                                    <Link href={ProductController.index.url()}>
                                        {t('products.cancel')}
                                    </Link>
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

CreateProduct.layout = {
    breadcrumbs: [
        {
            title: 'products.breadcrumb_index',
            href: ProductController.index.url(),
        },
        {
            title: 'products.breadcrumb_create',
            href: ProductController.create.url(),
        },
    ],
};
