import { router } from '@inertiajs/react';
import { Crop, ImagePlus, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import PostController from '@/actions/App/Http/Controllers/Restaurant/PostController';
import { ImageCropper } from '@/components/image-cropper';
import type { CropAspectOption } from '@/components/image-cropper';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { croppedFile, imageSourceUrl } from '@/lib/image-upload';
import { newRequestKey } from '@/lib/request-key';
import { cn } from '@/lib/utils';
import type { PostImageAspect } from '@/types';

const BODY_MAX = 2000;
/** Instagram's standard width: exports come out at 1080×1350, 1080×1080, 1080×566 or 1080×1920. */
const IMAGE_WIDTH = 1080;
/**
 * The formats Instagram/Facebook use (mirrors RestaurantPost::ASPECTS), so a
 * picture posted here can be reused there without cropping it again.
 */
const ASPECTS: {
    value: PostImageAspect;
    ratio: number;
    ratioLabel: string;
    preview: string;
}[] = [
    {
        value: 'portrait',
        ratio: 4 / 5,
        ratioLabel: '4:5',
        preview: 'aspect-[4/5]',
    },
    { value: 'square', ratio: 1, ratioLabel: '1:1', preview: 'aspect-square' },
    {
        value: 'landscape',
        ratio: 1.91,
        ratioLabel: '1,91:1',
        preview: 'aspect-[1.91/1]',
    },
    {
        value: 'story',
        ratio: 9 / 16,
        ratioLabel: '9:16',
        preview: 'aspect-[9/16] max-w-[200px]',
    },
];

type PickedImage = { file: File; url: string; aspect: PostImageAspect };

/** The restaurant writes a post (text, picture or both) at the top of its own feed. */
export function PostComposer() {
    const { t } = useTranslation();
    const inputRef = useRef<HTMLInputElement>(null);
    const [body, setBody] = useState('');
    // Original of the confirmed picture, kept so "Ajustar" can crop it again in any format.
    const [source, setSource] = useState<string | null>(null);
    // Picture open in the editor: a new pick, or `source` when adjusting.
    const [editing, setEditing] = useState<string | null>(null);
    const [image, setImage] = useState<PickedImage | null>(null);
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

    const aspectOptions: CropAspectOption<PostImageAspect>[] = ASPECTS.map(
        (a) => ({
            value: a.value,
            ratio: a.ratio,
            label: t(`social.aspect_${a.value}`),
            hint: t(`social.aspect_${a.value}_hint`, { ratio: a.ratioLabel }),
        }),
    );

    const pick = (e: React.ChangeEvent<HTMLInputElement>) => {
        const src = imageSourceUrl(e.target.files?.[0]);
        e.target.value = '';

        if (src) {
            setEditing(src);
        }
    };

    const closeEditor = (confirmed: boolean) => {
        // A new pick that was cancelled is dropped; the confirmed picture stays as it was.
        if (!confirmed && editing && editing !== source) {
            URL.revokeObjectURL(editing);
        }

        if (confirmed && editing !== source) {
            setSource(editing);
        }

        setEditing(null);
    };

    const clearImage = () => {
        setSource(null);
        setImage(null);
    };

    const canSubmit = (body.trim() !== '' || image !== null) && !processing;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();

        router.post(
            PostController.store.url(),
            {
                body,
                image: image?.file ?? null,
                image_aspect: image?.aspect ?? null,
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
            {editing && (
                <ImageCropper<PostImageAspect>
                    open
                    src={editing}
                    aspectOptions={aspectOptions}
                    initialAspect={image?.aspect ?? 'portrait'}
                    aspectLabel={t('social.composer_format')}
                    outputWidth={IMAGE_WIDTH}
                    quality={0.82}
                    onConfirm={(blob, aspect) => {
                        setImage({
                            file: croppedFile(blob, 'post'),
                            url: URL.createObjectURL(blob),
                            aspect: aspect ?? 'portrait',
                        });
                        closeEditor(true);
                    }}
                    onCancel={() => closeEditor(false)}
                />
            )}

            {image && (
                <div
                    className={cn(
                        'relative max-w-xs overflow-hidden rounded-xl bg-muted',
                        ASPECTS.find((a) => a.value === image.aspect)?.preview,
                    )}
                >
                    <img
                        src={image.url}
                        alt=""
                        className="h-full w-full object-cover"
                    />
                    <div className="absolute top-2 right-2 flex gap-1.5">
                        <button
                            type="button"
                            onClick={() => setEditing(source)}
                            aria-label={t('social.composer_adjust_image')}
                            title={t('social.composer_adjust_image')}
                            className="flex size-8 items-center justify-center rounded-full bg-black/60 text-white"
                        >
                            <Crop className="h-4 w-4" />
                        </button>
                        <button
                            type="button"
                            onClick={clearImage}
                            aria-label={t('social.composer_remove_image')}
                            title={t('social.composer_remove_image')}
                            className="flex size-8 items-center justify-center rounded-full bg-black/60 text-white"
                        >
                            <X className="h-4 w-4" />
                        </button>
                    </div>
                </div>
            )}
            <InputError message={errors.image ?? errors.image_aspect} />

            <div className="flex flex-wrap items-center justify-between gap-3">
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
