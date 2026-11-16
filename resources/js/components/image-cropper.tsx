import { useCallback, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import ReactCrop, { centerCrop, makeAspectCrop } from 'react-image-crop';
import type { Crop, PixelCrop } from 'react-image-crop';
import 'react-image-crop/dist/ReactCrop.css';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

type Props = {
    open: boolean;
    src: string;
    onConfirm: (blob: Blob) => void;
    onCancel: () => void;
    confirmLabel?: string;
    cancelLabel?: string;
    /** Width / height of the crop. Defaults to a square. */
    aspect?: number;
    /** Width of the encoded result in px (height follows the aspect). Never upscaled. */
    outputWidth?: number;
    /**
     * `jpeg` for photos (smallest). `webp` keeps transparency, for logos;
     * browsers that can't encode WebP fall back to PNG.
     */
    format?: 'jpeg' | 'webp';
    quality?: number;
    /** Extra line under the title, e.g. "Photo 2 of 3". */
    description?: string;
};

function makeCenteredCrop(width: number, height: number, aspect: number): Crop {
    return centerCrop(
        makeAspectCrop({ unit: '%', width: 90 }, aspect, width, height),
        width,
        height,
    );
}

/**
 * Crops and compresses a picture in the browser, so uploads stay small on
 * mobile data and never hit the server's file-size limit.
 */
export function ImageCropper({
    open,
    src,
    onConfirm,
    onCancel,
    confirmLabel,
    cancelLabel,
    aspect = 1,
    outputWidth = 500,
    format = 'jpeg',
    quality = 0.85,
    description,
}: Props) {
    const { t } = useTranslation();
    const imgRef = useRef<HTMLImageElement>(null);
    const [crop, setCrop] = useState<Crop>();
    const [completedCrop, setCompletedCrop] = useState<PixelCrop>();
    const [encoding, setEncoding] = useState(false);

    const onImageLoad = useCallback(
        (e: React.SyntheticEvent<HTMLImageElement>) => {
            const { naturalWidth, naturalHeight } = e.currentTarget;
            setCrop(makeCenteredCrop(naturalWidth, naturalHeight, aspect));
        },
        [aspect],
    );

    // Formats the browser can't decode (e.g. HEIC outside Safari) can't be cropped.
    const onImageError = useCallback(() => {
        toast.error(t('image_cropper.unreadable'));
        onCancel();
    }, [onCancel, t]);

    const handleConfirm = useCallback(() => {
        const img = imgRef.current;

        if (!img || !completedCrop || completedCrop.width === 0) {
            return;
        }

        const scaleX = img.naturalWidth / img.width;
        const scaleY = img.naturalHeight / img.height;
        const sourceW = completedCrop.width * scaleX;
        const sourceH = completedCrop.height * scaleY;
        const width = Math.max(1, Math.round(Math.min(outputWidth, sourceW)));
        const height = Math.max(1, Math.round(width / aspect));

        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const ctx = canvas.getContext('2d');

        if (!ctx) {
            return;
        }

        if (format === 'jpeg') {
            // JPEG has no alpha: transparent areas would turn black.
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, width, height);
        }

        ctx.imageSmoothingQuality = 'high';
        ctx.drawImage(
            img,
            completedCrop.x * scaleX,
            completedCrop.y * scaleY,
            sourceW,
            sourceH,
            0,
            0,
            width,
            height,
        );

        setEncoding(true);
        canvas.toBlob(
            (blob) => {
                setEncoding(false);

                if (blob) {
                    onConfirm(blob);
                }
            },
            format === 'webp' ? 'image/webp' : 'image/jpeg',
            quality,
        );
    }, [aspect, completedCrop, format, onConfirm, outputWidth, quality]);

    return (
        <Dialog
            open={open}
            onOpenChange={(o) => {
                if (!o) {
                    onCancel();
                }
            }}
        >
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t('image_cropper.title')}</DialogTitle>
                    {description && (
                        <DialogDescription>{description}</DialogDescription>
                    )}
                </DialogHeader>

                <div className="flex justify-center overflow-auto">
                    <ReactCrop
                        crop={crop}
                        onChange={setCrop}
                        onComplete={setCompletedCrop}
                        aspect={aspect}
                        minWidth={50}
                        circularCrop={false}
                    >
                        <img
                            ref={imgRef}
                            src={src}
                            alt={t('image_cropper.preview_alt')}
                            className="max-h-[60vh] max-w-full"
                            onLoad={onImageLoad}
                            onError={onImageError}
                        />
                    </ReactCrop>
                </div>

                <DialogFooter>
                    <Button variant="outline" onClick={onCancel}>
                        {cancelLabel ?? t('image_cropper.cancel')}
                    </Button>
                    <Button
                        onClick={handleConfirm}
                        disabled={!completedCrop || encoding}
                    >
                        {confirmLabel ?? t('image_cropper.confirm')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
