import { useCallback, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import ReactCrop, { centerCrop, makeAspectCrop } from 'react-image-crop';
import type { Crop, PixelCrop } from 'react-image-crop';
import 'react-image-crop/dist/ReactCrop.css';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { compressImage } from '@/lib/compress-image';

type Props = {
    open: boolean;
    src: string;
    onConfirm: (blob: Blob) => void;
    onCancel: () => void;
    confirmLabel?: string;
    cancelLabel?: string;
};

const OUTPUT_SIZE = 500;

function makeCenteredCrop(width: number, height: number): Crop {
    return centerCrop(
        makeAspectCrop({ unit: '%', width: 90 }, 1, width, height),
        width,
        height,
    );
}

export function ImageCropper({
    open,
    src,
    onConfirm,
    onCancel,
    confirmLabel,
    cancelLabel,
}: Props) {
    const { t } = useTranslation();
    const imgRef = useRef<HTMLImageElement>(null);
    const [crop, setCrop] = useState<Crop>();
    const [completedCrop, setCompletedCrop] = useState<PixelCrop>();

    const onImageLoad = useCallback(
        (e: React.SyntheticEvent<HTMLImageElement>) => {
            const { naturalWidth, naturalHeight } = e.currentTarget;
            setCrop(makeCenteredCrop(naturalWidth, naturalHeight));
        },
        [],
    );

    const handleConfirm = useCallback(() => {
        const img = imgRef.current;

        if (!img || !completedCrop) {
            return;
        }

        const canvas = document.createElement('canvas');
        canvas.width = OUTPUT_SIZE;
        canvas.height = OUTPUT_SIZE;
        const ctx = canvas.getContext('2d');

        if (!ctx) {
            return;
        }

        const scaleX = img.naturalWidth / img.width;
        const scaleY = img.naturalHeight / img.height;

        ctx.drawImage(
            img,
            completedCrop.x * scaleX,
            completedCrop.y * scaleY,
            completedCrop.width * scaleX,
            completedCrop.height * scaleY,
            0,
            0,
            OUTPUT_SIZE,
            OUTPUT_SIZE,
        );

        canvas.toBlob((blob) => {
            if (!blob) {
                return;
            }

            // run through compressor for consistent quality settings
            compressImage(
                new File([blob], 'crop.jpg', { type: 'image/jpeg' }),
                {
                    maxWidth: OUTPUT_SIZE,
                    maxHeight: OUTPUT_SIZE,
                },
            ).then(onConfirm);
        }, 'image/jpeg');
    }, [completedCrop, onConfirm]);

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
                </DialogHeader>

                <div className="flex justify-center overflow-auto">
                    <ReactCrop
                        crop={crop}
                        onChange={setCrop}
                        onComplete={setCompletedCrop}
                        aspect={1}
                        minWidth={50}
                        circularCrop={false}
                    >
                        <img
                            ref={imgRef}
                            src={src}
                            alt={t('image_cropper.preview_alt')}
                            className="max-h-[60vh] max-w-full"
                            onLoad={onImageLoad}
                        />
                    </ReactCrop>
                </div>

                <DialogFooter>
                    <Button variant="outline" onClick={onCancel}>
                        {cancelLabel ?? t('image_cropper.cancel')}
                    </Button>
                    <Button onClick={handleConfirm} disabled={!completedCrop}>
                        {confirmLabel ?? t('image_cropper.confirm')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
