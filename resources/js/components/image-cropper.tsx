import { useCallback, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import ReactCrop, {
    centerCrop,
    convertToPixelCrop,
    makeAspectCrop,
} from 'react-image-crop';
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
import { cn } from '@/lib/utils';

/** A crop format the user can pick inside the editor (e.g. portrait, square, landscape). */
export type CropAspectOption<K extends string = string> = {
    value: K;
    /** Width / height. */
    ratio: number;
    label: string;
    /** Second, smaller line, e.g. the ratio and where the format is used. */
    hint?: string;
};

type Props<K extends string = string> = {
    open: boolean;
    src: string;
    /** `aspect` is the picked option's value when `aspectOptions` is given. */
    onConfirm: (blob: Blob, aspect?: K) => void;
    onCancel: () => void;
    confirmLabel?: string;
    cancelLabel?: string;
    /** Width / height of the crop. Defaults to a square. Ignored with `aspectOptions`. */
    aspect?: number;
    /** Lets the user switch the crop format in the editor; starts on `initialAspect` (or the first). */
    aspectOptions?: CropAspectOption<K>[];
    initialAspect?: K;
    /** Accessible name of the format switch. */
    aspectLabel?: string;
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

/** Size of a format's outline icon, fitted in a 32×24 px box. */
function formatIconSize(ratio: number): { width: number; height: number } {
    const [boxW, boxH] = [32, 24];

    return ratio >= boxW / boxH
        ? { width: boxW, height: Math.round(boxW / ratio) }
        : { width: Math.round(boxH * ratio), height: boxH };
}

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
export function ImageCropper<K extends string = string>({
    open,
    src,
    onConfirm,
    onCancel,
    confirmLabel,
    cancelLabel,
    aspect: fixedAspect = 1,
    aspectOptions,
    initialAspect,
    aspectLabel,
    outputWidth = 500,
    format = 'jpeg',
    quality = 0.85,
    description,
}: Props<K>) {
    const { t } = useTranslation();
    const imgRef = useRef<HTMLImageElement>(null);
    const [crop, setCrop] = useState<Crop>();
    const [completedCrop, setCompletedCrop] = useState<PixelCrop>();
    const [encoding, setEncoding] = useState(false);
    const [selected, setSelected] = useState<K | undefined>(
        initialAspect ?? aspectOptions?.[0]?.value,
    );
    const aspect =
        aspectOptions?.find((o) => o.value === selected)?.ratio ?? fixedAspect;

    /** Centers a fresh crop of `ratio` on the loaded picture, ready to confirm without dragging. */
    // Plain functions: the React Compiler memoizes them.
    const resetCrop = (img: HTMLImageElement, ratio: number) => {
        const next = makeCenteredCrop(
            img.naturalWidth,
            img.naturalHeight,
            ratio,
        );
        setCrop(next);
        setCompletedCrop(convertToPixelCrop(next, img.width, img.height));
    };

    const onImageLoad = (e: React.SyntheticEvent<HTMLImageElement>) =>
        resetCrop(e.currentTarget, aspect);

    const pickAspect = (option: CropAspectOption<K>) => {
        setSelected(option.value);

        if (imgRef.current?.complete) {
            resetCrop(imgRef.current, option.ratio);
        }
    };

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
                    onConfirm(blob, selected);
                }
            },
            format === 'webp' ? 'image/webp' : 'image/jpeg',
            quality,
        );
    }, [
        aspect,
        completedCrop,
        format,
        onConfirm,
        outputWidth,
        quality,
        selected,
    ]);

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

                {aspectOptions && aspectOptions.length > 1 && (
                    <div
                        role="radiogroup"
                        aria-label={aspectLabel}
                        className="grid auto-cols-fr grid-flow-col gap-1 rounded-lg border p-1"
                    >
                        {aspectOptions.map((option) => (
                            <button
                                key={option.value}
                                type="button"
                                role="radio"
                                aria-checked={selected === option.value}
                                onClick={() => pickAspect(option)}
                                className={cn(
                                    'flex min-w-0 flex-col items-center gap-1 rounded-md px-1 py-1.5 text-xs font-medium transition-colors',
                                    selected === option.value
                                        ? 'bg-primary text-primary-foreground'
                                        : 'text-muted-foreground hover:bg-muted',
                                )}
                            >
                                {/* Outline of the format, so it reads without the label. */}
                                <span className="flex h-6 items-center">
                                    <span
                                        aria-hidden
                                        className="block rounded-[3px] border-2 border-current"
                                        style={formatIconSize(option.ratio)}
                                    />
                                </span>
                                <span className="leading-tight">
                                    {option.label}
                                </span>
                                {option.hint && (
                                    <span className="text-[10px] leading-tight font-normal opacity-80">
                                        {option.hint}
                                    </span>
                                )}
                            </button>
                        ))}
                    </div>
                )}

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
