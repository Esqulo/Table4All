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
import type { PriceType, Product } from '@/types';

const PRICE_TYPES: PriceType[] = ['unit', 'kg', '100g', 'liter', 'portion'];

type Props = {
    product: Product;
};

export default function EditProduct({ product }: Props) {
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

    const displayPicture = preview ?? product.picture_url;

    return (
        <>
            <Head title={t('products.breadcrumb_edit')} />

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
                <h1 className="text-xl font-semibold">{t('products.breadcrumb_edit')}</h1>

                <Form
                    {...ProductController.update.form({ product: product.id })}
                    encType="multipart/form-data"
                    className="space-y-5"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">{t('products.name_label')}</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    required
                                    autoFocus
                                    defaultValue={product.name}
                                    placeholder={t('products.name_placeholder')}
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="description">{t('products.description_label')}</Label>
                                <Textarea
                                    id="description"
                                    name="description"
                                    defaultValue={product.description ?? ''}
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
                                        defaultValue={product.price}
                                    />
                                    <InputError message={errors.price} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="price_type">{t('products.price_type_label')}</Label>
                                    <Select name="price_type" defaultValue={product.price_type}>
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
                                    {displayPicture ? (
                                        <img
                                            src={displayPicture}
                                            alt={product.name}
                                            className="h-24 w-24 rounded-lg object-cover border border-border"
                                        />
                                    ) : (
                                        <div className="h-24 w-24 rounded-lg border border-dashed border-border bg-muted" />
                                    )}
                                    <Button type="button" variant="outline" onClick={openPicker}>
                                        {displayPicture ? t('products.picture_change') : t('products.picture_select')}
                                    </Button>
                                </div>
                                {/* actual file input submitted with the form */}
                                <input ref={formPictureRef} type="file" name="picture" className="hidden" readOnly />
                                <InputError message={errors.picture} />
                            </div>

                            <div className="flex gap-3 pt-2">
                                <Button type="submit" disabled={processing}>
                                    {t('products.submit_update')}
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

EditProduct.layout = {
    breadcrumbs: [
        {
            title: 'products.breadcrumb_index',
            href: ProductController.index.url(),
        },
        {
            title: 'products.breadcrumb_edit',
            href: '#',
        },
    ],
};
