import { router } from '@inertiajs/react';
import { ImagePlus, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import PostController from '@/actions/App/Http/Controllers/Restaurant/PostController';
import { ImageCropper } from '@/components/image-cropper';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { croppedFile, imageSourceUrl } from '@/lib/image-upload';
import { newRequestKey } from '@/lib/request-key';
import { cn } from '@/lib/utils';
import type { PostImageAspect } from '@/types';

const BODY_MAX = 2000;
const IMAGE_WIDTH = 1600;
const ASPECTS: { key: PostImageAspect; ratio: number; preview: string }[] = [
    { key: 'portrait', ratio: 4 / 5, preview: 'aspect-[4/5]' },
    { key: 'square', ratio: 1, preview: 'aspect-square' },
    { key: 'landscape', ratio: 16 / 9, preview: 'aspect-video' },
];

/** The restaurant writes a post (text, picture or both) at the top of its own feed. */
export function PostComposer() {
    const { t } = useTranslation();
    const inputRef = useRef<HTMLInputElement>(null);
    const [body, setBody] = useState('');
    const [aspect, setAspect] = useState<PostImageAspect>('portrait');
    // The picked picture stays around so changing the format can crop it again.
    const [source, setSource] = useState<string | null>(null);
    const [cropping, setCropping] = useState(false);
    const [image, setImage] = useState<{ file: File; url: string } | null>(
        null,
    );
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    // One key per post being written, kept across retries until it is published.
    const [requestKey, setRequestKey] = useState(newRequestKey);

    useEffect(
        () => () => {
            if (source) {
                URL.revokeObjectURL(source);
            }
        },
        [source],
    );
    useEffect(
        () => () => {
            if (image) {
                URL.revokeObjectURL(image.url);
            }
        },
        [image],
    );

    const pick = (e: React.ChangeEvent<HTMLInputElement>) => {
        const src = imageSourceUrl(e.target.files?.[0]);
        e.target.value = '';

        if (src) {
            setSource(src);
            setCropping(true);
        }
    };

    const changeAspect = (next: PostImageAspect) => {
        setAspect(next);

        if (source) {
            setCropping(true);
        }
    };

    const clearImage = () => {
        setSource(null);
        setImage(null);
    };

    const ratio = ASPECTS.find((a) => a.key === aspect)!.ratio;
    const canSubmit = (body.trim() !== '' || image !== null) && !processing;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();

        router.post(
            PostController.store.url(),
            {
                body,
                image: image?.file ?? null,
                image_aspect: image ? aspect : null,
                request_key: requestKey,
            },
            {
                forceFormData: true,
                preserveScroll: true,
                onStart: () => {
                    setProcessing(true);
                    setErrors({});
                },
                onSuccess: () => {
                    setBody('');
                    clearImage();
                    setRequestKey(newRequestKey());
                },
                onError: setErrors,
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <form
            onSubmit={submit}
            className="space-y-3 rounded-2xl border bg-card p-4 shadow-xs"
        >
            <Textarea
                value={body}
                onChange={(e) => setBody(e.target.value)}
                placeholder={t('social.composer_placeholder')}
                aria-label={t('social.composer_placeholder')}
                maxLength={BODY_MAX}
                rows={3}
                className="resize-none"
            />
            <InputError message={errors.body} />

            <input
                ref={inputRef}
                type="file"
                accept="image/*"
                className="hidden"
                onChange={pick}
            />
            {cropping && source && (
                <ImageCropper
                    open
                    src={source}
                    aspect={ratio}
                    outputWidth={IMAGE_WIDTH}
                    quality={0.82}
                    onConfirm={(blob) => {
                        setImage({
                            file: croppedFile(blob, 'post'),
                            url: URL.createObjectURL(blob),
                        });
                        setCropping(false);
                    }}
                    onCancel={() => {
                        setCropping(false);

                        if (!image) {
                            setSource(null);
                        }
                    }}
                />
            )}

            {image && (
                <div
                    className={cn(
                        'relative max-w-xs overflow-hidden rounded-xl bg-muted',
                        ASPECTS.find((a) => a.key === aspect)!.preview,
                    )}
                >
                    <img
                        src={image.url}
                        alt=""
                        className="h-full w-full object-cover"
                    />
                    <button
                        type="button"
                        onClick={clearImage}
                        aria-label={t('social.composer_remove_image')}
                        className="absolute top-2 right-2 flex size-8 items-center justify-center rounded-full bg-black/60 text-white"
                    >
                        <X className="h-4 w-4" />
                    </button>
                </div>
            )}
            <InputError message={errors.image ?? errors.image_aspect} />

            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-wrap items-center gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => inputRef.current?.click()}
                        disabled={processing}
                    >
                        <ImagePlus className="h-4 w-4" />
                        {image
                            ? t('social.composer_change_image')
                            : t('social.composer_add_image')}
                    </Button>
                    <div
                        className="flex rounded-md border p-0.5"
                        role="radiogroup"
                        aria-label={t('social.composer_format')}
                    >
                        {ASPECTS.map((a) => (
                            <button
                                key={a.key}
                                type="button"
                                role="radio"
                                aria-checked={aspect === a.key}
                                onClick={() => changeAspect(a.key)}
                                className={cn(
                                    'rounded px-2 py-1 text-xs font-medium',
                                    aspect === a.key
                                        ? 'bg-primary text-primary-foreground'
                                        : 'text-muted-foreground hover:bg-muted',
                                )}
                            >
                                {t(`social.aspect_${a.key}`)}
                            </button>
                        ))}
                    </div>
                </div>
                <div className="flex items-center gap-3">
                    {body.length > BODY_MAX * 0.8 && (
                        <span className="font-receipt text-xs text-muted-foreground tabular-nums">
                            {body.length}/{BODY_MAX}
                        </span>
                    )}
                    <Button type="submit" disabled={!canSubmit}>
                        {processing
                            ? t('social.composer_publishing')
                            : t('social.composer_publish')}
                    </Button>
                </div>
            </div>
        </form>
    );
}
