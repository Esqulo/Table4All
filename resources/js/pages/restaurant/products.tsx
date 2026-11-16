import { Form, Head, Link, router } from '@inertiajs/react';
import { Camera, ImageOff, Loader2, Pencil, Plus, Trash2 } from 'lucide-react';
import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import ProductController from '@/actions/App/Http/Controllers/Restaurant/ProductController';
import Heading from '@/components/heading';
import { ImageCropper } from '@/components/image-cropper';
import { Button } from '@/components/ui/button';
import { croppedFile, imageSourceUrl } from '@/lib/image-upload';
import { formatBRL } from '@/lib/money';
import type { Product } from '@/types';

type Props = {
    products: Product[];
    canManage?: boolean;
};

export default function Products({ products, canManage = true }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('products.title')} />

            <div className="space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <Heading title={t('products.title')} />
                    {canManage && (
                        <Button asChild>
                            <Link href={ProductController.create.url()}>
                                <Plus className="mr-2 h-4 w-4" />
                                {t('products.new')}
                            </Link>
                        </Button>
                    )}
                </div>

                {products.length === 0 ? (
                    <p className="py-12 text-center text-sm text-muted-foreground">
                        {t('products.empty')}
                    </p>
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        {products.map((product) => (
                            <ProductCard
                                key={product.id}
                                product={product}
                                canManage={canManage}
                            />
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

function ProductCard({
    product,
    canManage,
}: {
    product: Product;
    canManage: boolean;
}) {
    const { t } = useTranslation();

    const priceFormatted = formatBRL(product.price);

    const suffix = t(`products.price_suffix.${product.price_type}`);

    return (
        <div className="flex flex-col overflow-hidden rounded-xl border border-border bg-card shadow-xs">
            {canManage ? (
                <ProductPictureEditor product={product} />
            ) : (
                <div className="relative aspect-video overflow-hidden bg-muted">
                    <ProductPicture product={product} />
                </div>
            )}

            <div className="flex flex-1 flex-col gap-1 p-4">
                {product.category && (
                    <span className="self-start rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">
                        {product.category.name}
                    </span>
                )}
                <p className="leading-tight font-medium">{product.name}</p>
                {product.description && (
                    <p className="line-clamp-2 text-xs text-muted-foreground">
                        {product.description}
                    </p>
                )}
                <p className="mt-1 text-sm font-semibold text-primary">
                    {priceFormatted}
                    <span className="ml-1 text-xs font-normal text-muted-foreground">
                        / {suffix}
                    </span>
                </p>
            </div>

            {canManage && (
                <div className="flex gap-2 border-t border-border px-4 py-3">
                    <Button
                        variant="outline"
                        size="sm"
                        asChild
                        className="flex-1"
                    >
                        <Link
                            href={ProductController.edit.url({
                                product: product.id,
                            })}
                        >
                            <Pencil className="mr-1 h-3.5 w-3.5" />
                            {t('products.edit')}
                        </Link>
                    </Button>

                    <Form
                        {...ProductController.destroy.form({
                            product: product.id,
                        })}
                        className="flex-1"
                        onBefore={() => confirm(t('products.delete_confirm'))}
                    >
                        {({ processing }) => (
                            <Button
                                type="submit"
                                variant="outline"
                                size="sm"
                                disabled={processing}
                                className="w-full text-destructive hover:text-destructive"
                            >
                                <Trash2 className="mr-1 h-3.5 w-3.5" />
                                {t('products.delete')}
                            </Button>
                        )}
                    </Form>
                </div>
            )}
        </div>
    );
}

function ProductPicture({
    product,
    src,
}: {
    product: Product;
    src?: string | null;
}) {
    const url = src ?? product.picture_url;

    return url ? (
        <img
            loading="lazy"
            decoding="async"
            src={url}
            alt={product.name}
            className="h-full w-full object-cover"
        />
    ) : (
        <div className="flex h-full w-full items-center justify-center text-muted-foreground">
            <ImageOff className="h-10 w-10 opacity-30" />
        </div>
    );
}

/** Clicking the picture area picks a file, crops it and uploads only the picture. */
function ProductPictureEditor({ product }: { product: Product }) {
    const { t } = useTranslation();
    const pickerRef = useRef<HTMLInputElement>(null);
    const [cropSrc, setCropSrc] = useState<string | null>(null);
    const [preview, setPreview] = useState<string | null>(null);
    const [uploading, setUploading] = useState(false);

    const onFileSelected = (e: React.ChangeEvent<HTMLInputElement>) => {
        const src = imageSourceUrl(e.target.files?.[0]);
        e.target.value = '';

        if (src) {
            setCropSrc(src);
        }
    };

    const closeCropper = () => {
        if (cropSrc) {
            URL.revokeObjectURL(cropSrc);
        }

        setCropSrc(null);
    };

    const onCropConfirm = (blob: Blob) => {
        closeCropper();
        const localUrl = URL.createObjectURL(blob);
        setPreview(localUrl);

        router.post(
            ProductController.updatePicture.url({ product: product.id }),
            { picture: croppedFile(blob, 'picture') },
            {
                forceFormData: true,
                preserveScroll: true,
                onStart: () => setUploading(true),
                onError: (errors) => {
                    toast.error(errors.picture ?? Object.values(errors)[0]);
                },
                onFinish: () => {
                    setUploading(false);
                    // Fresh props carry the stored URL (or the old one on failure).
                    setPreview(null);
                    URL.revokeObjectURL(localUrl);
                },
            },
        );
    };

    return (
        <>
            {cropSrc && (
                <ImageCropper
                    open
                    src={cropSrc}
                    onConfirm={onCropConfirm}
                    onCancel={closeCropper}
                    confirmLabel={t('products.crop_confirm')}
                    cancelLabel={t('products.cancel')}
                />
            )}
            <input
                ref={pickerRef}
                type="file"
                accept="image/*"
                className="hidden"
                onChange={onFileSelected}
            />

            <button
                type="button"
                onClick={() => pickerRef.current?.click()}
                disabled={uploading}
                title={t('products.picture_edit_hint')}
                aria-label={t('products.picture_edit_hint')}
                className="group relative aspect-video cursor-pointer overflow-hidden bg-muted focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-ring disabled:cursor-wait"
            >
                <ProductPicture product={product} src={preview} />
                <span
                    className={`absolute inset-0 flex items-center justify-center bg-black/40 text-white transition-opacity ${uploading ? 'opacity-100' : 'opacity-0 group-hover:opacity-100 group-focus-visible:opacity-100'}`}
                >
                    {uploading ? (
                        <Loader2 className="h-8 w-8 animate-spin" />
                    ) : (
                        <span className="flex items-center gap-2 text-sm font-medium">
                            <Camera className="h-5 w-5" />
                            {t('products.picture_edit_hint')}
                        </span>
                    )}
                </span>
            </button>
        </>
    );
}

Products.layout = {
    breadcrumbs: [
        {
            title: 'products.breadcrumb_index',
            href: ProductController.index.url(),
        },
    ],
};
