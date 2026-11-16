import { toast } from 'sonner';
import i18n from '@/i18n';

/**
 * Every picture goes through the cropper, which re-encodes it small before it
 * is uploaded. So the browser can accept big originals (phone photos of
 * several MB); the server only ever receives the compressed crop.
 */
export const MAX_SOURCE_MB = 25;

/**
 * Checks a picked file and returns an object URL for the cropper, or null
 * (with a toast) when it can't be used. Revoke the URL when done.
 */
export function imageSourceUrl(file: File | null | undefined): string | null {
    if (!file) {
        return null;
    }

    if (!file.type.startsWith('image/')) {
        toast.error(i18n.t('image_cropper.not_image'));

        return null;
    }

    if (file.size > MAX_SOURCE_MB * 1024 * 1024) {
        toast.error(i18n.t('image_cropper.too_large', { size: MAX_SOURCE_MB }));

        return null;
    }

    return URL.createObjectURL(file);
}

/** Wraps a cropped blob in a File named after its real encoding. */
export function croppedFile(blob: Blob, basename: string): File {
    const extension =
        { 'image/webp': 'webp', 'image/png': 'png' }[blob.type] ?? 'jpg';

    return new File([blob], `${basename}.${extension}`, {
        type: blob.type || 'image/jpeg',
    });
}

/** Puts a file into a (hidden) form input, so a regular form submit sends it. */
export function setInputFile(input: HTMLInputElement | null, file: File): void {
    if (!input) {
        return;
    }

    const transfer = new DataTransfer();
    transfer.items.add(file);
    input.files = transfer.files;
}
