import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    Copy,
    ExternalLink,
    ImagePlus,
    Moon,
    Plus,
    Store,
    Trash2,
    X,
} from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import EstablishmentController from '@/actions/App/Http/Controllers/Restaurant/EstablishmentController';
import Heading from '@/components/heading';
import { ImageCropper } from '@/components/image-cropper';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { croppedFile, imageSourceUrl } from '@/lib/image-upload';
import type { OpeningHourInterval, VenuePhoto } from '@/types';

type Props = {
    logoUrl: string | null;
    bannerUrl: string | null;
    /** Public @handle; null only for accounts saved before handles existed. */
    handle: string | null;
    profilePublic: boolean;
    /** Absolute "/vempro" address; the profile lives at `${profileBaseUrl}/${handle}`. */
    profileBaseUrl: string;
    address: string | null;
    ifoodUrl: string | null;
    hours: OpeningHourInterval[];
    photos: VenuePhoto[];
    maxPhotos: number;
};

type Interval = { opens_at: string; closes_at: string };

/** Brazilian week starts on Monday; weekday numbers follow JS/Carbon (0 = Sunday). */
const DISPLAY_ORDER = [1, 2, 3, 4, 5, 6, 0];
const MAX_INTERVALS_PER_DAY = 3;
const DEFAULT_INTERVAL: Interval = { opens_at: '18:00', closes_at: '23:00' };
/** Logos are shown small; 512 px covers 3x screens. WebP keeps transparency. */
const LOGO_SIZE = 512;
/** The banner is a wide cover behind the profile header; 3:1 reads well on phones and desktops. */
const BANNER_ASPECT = 3;
const BANNER_WIDTH = 1920;
/** Venue photos are shown in 4:3 tiles and can go full width on a phone. */
const PHOTO_ASPECT = 4 / 3;
const PHOTO_WIDTH = 1600;

export default function Establishment({
    logoUrl,
    bannerUrl,
    handle,
    profilePublic,
    profileBaseUrl,
    address,
    ifoodUrl,
    hours,
    photos,
    maxPhotos,
}: Props) {
    const { t } = useTranslation();
    const profileUrl = handle ? `${profileBaseUrl}/${handle}` : null;

    return (
        <>
            <Head title={t('establishment.title')} />

            <div className="mx-auto w-full max-w-4xl space-y-6 p-4 sm:p-6">
                <div className="flex flex-wrap items-start justify-between gap-x-4">
                    <Heading
                        title={t('establishment.title')}
                        description={t('establishment.description')}
                    />
                    {profileUrl && (
                        <Button variant="outline" asChild>
                            <Link href={profileUrl}>
                                <ExternalLink className="h-4 w-4" />
                                {t('establishment.view_profile')}
                            </Link>
                        </Button>
                    )}
                </div>

                <ProfileSection
                    handle={handle}
                    profilePublic={profilePublic}
                    profileBaseUrl={profileBaseUrl}
                    address={address}
                    ifoodUrl={ifoodUrl}
                />
                <BannerSection bannerUrl={bannerUrl} logoUrl={logoUrl} />
                <LogoSection logoUrl={logoUrl} />
                <HoursSection initial={hours} />
                <PhotosSection photos={photos} maxPhotos={maxPhotos} />
            </div>
        </>
    );
}

function Section({
    title,
    description,
    aside,
    children,
}: {
    title: string;
    description: string;
    aside?: React.ReactNode;
    children: React.ReactNode;
}) {
    return (
        <section className="rounded-xl border bg-card shadow-xs">
            <header className="flex flex-wrap items-start justify-between gap-3 border-b border-dashed px-5 py-4">
                <div className="min-w-0">
                    <h2 className="font-display text-2xl leading-none font-extrabold">
                        {title}
                    </h2>
                    <p className="mt-1.5 text-sm text-muted-foreground">
                        {description}
                    </p>
                </div>
                {aside}
            </header>
            <div className="p-5">{children}</div>
        </section>
    );
}

// ─── Public profile ───────────────────────────────────────────────────────────

/** Mirrors the server: no leading "@", lowercase, spaces become hyphens. */
function normalizeHandle(value: string): string {
    return value.trim().replace(/^@+/, '').toLowerCase().replace(/\s+/g, '-');
}

function ProfileSection({
    handle,
    profilePublic,
    profileBaseUrl,
    address,
    ifoodUrl,
}: {
    handle: string | null;
    profilePublic: boolean;
    profileBaseUrl: string;
    address: string | null;
    ifoodUrl: string | null;
}) {
    const { t } = useTranslation();
    const [value, setValue] = useState(handle ?? '');
    const [isPublic, setIsPublic] = useState(profilePublic);
    const [addressValue, setAddressValue] = useState(address ?? '');
    const [ifoodValue, setIfoodValue] = useState(ifoodUrl ?? '');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const normalized = normalizeHandle(value);
    const dirty =
        normalized !== (handle ?? '') ||
        isPublic !== profilePublic ||
        addressValue.trim() !== (address ?? '') ||
        ifoodValue.trim() !== (ifoodUrl ?? '');
    const savedUrl = handle ? `${profileBaseUrl}/${handle}` : null;

    const save = (e: React.FormEvent) => {
        e.preventDefault();

        router.put(
            EstablishmentController.updateProfile.url(),
            {
                handle: normalized,
                profile_public: isPublic,
                address: addressValue.trim() || null,
                ifood_url: ifoodValue.trim() || null,
            },
            {
                preserveScroll: true,
                onStart: () => {
                    setProcessing(true);
                    setErrors({});
                },
                onSuccess: (page) => {
                    const saved = page.props as unknown as Props;
                    setValue(normalized);
                    setAddressValue(saved.address ?? '');
                    setIfoodValue(saved.ifoodUrl ?? '');
                },
                onError: setErrors,
                onFinish: () => setProcessing(false),
            },
        );
    };

    const copy = async () => {
        if (!savedUrl) {
            return;
        }

        try {
            await navigator.clipboard.writeText(savedUrl);
            toast.success(t('venue_profile.link_copied'));
        } catch {
            toast.error(t('venue_profile.link_copy_failed'));
        }
    };

    return (
        <Section
            title={t('establishment.profile_title')}
            description={t('establishment.profile_description')}
        >
            <form onSubmit={save} className="space-y-5">
                <div className="space-y-2">
                    <Label htmlFor="handle">
                        {t('establishment.handle_label')}
                    </Label>
                    <div className="flex max-w-md items-center rounded-md border bg-transparent shadow-xs focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50">
                        <span className="pl-3 text-muted-foreground select-none">
                            @
                        </span>
                        <input
                            id="handle"
                            value={value}
                            onChange={(e) =>
                                setValue(
                                    e.target.value
                                        .toLowerCase()
                                        .replace(/\s+/g, '-'),
                                )
                            }
                            maxLength={31}
                            autoComplete="off"
                            autoCapitalize="none"
                            spellCheck={false}
                            aria-invalid={!!errors.handle}
                            className="h-9 min-w-0 flex-1 bg-transparent py-1 pr-3 pl-0.5 text-base outline-none md:text-sm"
                            required
                        />
                    </div>
                    <p className="font-receipt text-xs break-all text-muted-foreground">
                        {profileBaseUrl}/
                        <span className="text-foreground">
                            {normalized ||
                                t('establishment.handle_placeholder')}
                        </span>
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {t('establishment.handle_hint')}
                    </p>
                    <InputError message={errors.handle} />
                </div>

                <div className="max-w-xl space-y-2">
                    <Label htmlFor="address">
                        {t('establishment.address_label')}
                    </Label>
                    <Input
                        id="address"
                        value={addressValue}
                        onChange={(e) => setAddressValue(e.target.value)}
                        maxLength={255}
                        autoComplete="street-address"
                        placeholder={t('establishment.address_placeholder')}
                        aria-invalid={!!errors.address}
                    />
                    <p className="text-sm text-muted-foreground">
                        {t('establishment.address_hint')}
                    </p>
                    <InputError message={errors.address} />
                </div>

                <div className="max-w-xl space-y-2">
                    <Label htmlFor="ifood_url">
                        {t('establishment.ifood_label')}
                    </Label>
                    <Input
                        id="ifood_url"
                        inputMode="url"
                        value={ifoodValue}
                        onChange={(e) => setIfoodValue(e.target.value)}
                        maxLength={255}
                        autoComplete="off"
                        autoCapitalize="none"
                        spellCheck={false}
                        placeholder="https://www.ifood.com.br/delivery/…"
                        aria-invalid={!!errors.ifood_url}
                    />
                    <p className="text-sm text-muted-foreground">
                        {t('establishment.ifood_hint')}
                    </p>
                    <InputError message={errors.ifood_url} />
                </div>

                <label className="flex max-w-xl items-start gap-3">
                    <Checkbox
                        checked={isPublic}
                        onCheckedChange={(checked) =>
                            setIsPublic(checked === true)
                        }
                        className="mt-0.5"
                    />
                    <span className="space-y-1">
                        <span className="block text-sm font-medium">
                            {t('establishment.profile_public_label')}
                        </span>
                        <span className="block text-sm text-muted-foreground">
                            {isPublic
                                ? t('establishment.profile_public_on')
                                : t('establishment.profile_public_off')}
                        </span>
                    </span>
                </label>

                <div className="flex flex-wrap gap-2">
                    <Button type="submit" disabled={!dirty || processing}>
                        {t('establishment.profile_save')}
                    </Button>
                    {savedUrl && profilePublic && (
                        <Button type="button" variant="outline" onClick={copy}>
                            <Copy className="h-4 w-4" />
                            {t('establishment.profile_copy_link')}
                        </Button>
                    )}
                </div>
            </form>
        </Section>
    );
}

// ─── Single images (banner, logo) ─────────────────────────────────────────────

/** Pick → crop → upload flow shared by the banner and the logo. */
function useSingleImage(
    field: 'logo' | 'banner',
    uploadUrl: string,
    removeUrl: string,
) {
    const inputRef = useRef<HTMLInputElement>(null);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string>();
    const [cropSrc, setCropSrc] = useState<string | null>(null);

    const pick = (e: React.ChangeEvent<HTMLInputElement>) => {
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

    const upload = (blob: Blob) => {
        closeCropper();

        router.post(
            uploadUrl,
            { [field]: croppedFile(blob, field) },
            {
                forceFormData: true,
                preserveScroll: true,
                onStart: () => {
                    setProcessing(true);
                    setError(undefined);
                },
                onError: (errors) => setError(errors[field]),
                onFinish: () => setProcessing(false),
            },
        );
    };

    const remove = () => {
        router.delete(removeUrl, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
        });
    };

    const fileInput = (
        <input
            ref={inputRef}
            type="file"
            accept="image/*"
            className="hidden"
            onChange={pick}
        />
    );

    return {
        fileInput,
        browse: () => inputRef.current?.click(),
        processing,
        error,
        cropSrc,
        closeCropper,
        upload,
        remove,
    };
}

function ImageActions({
    hasImage,
    processing,
    uploadLabel,
    changeLabel,
    removeLabel,
    onBrowse,
    onRemove,
}: {
    hasImage: boolean;
    processing: boolean;
    uploadLabel: string;
    changeLabel: string;
    removeLabel: string;
    onBrowse: () => void;
    onRemove: () => void;
}) {
    return (
        <div className="flex flex-wrap gap-2">
            <Button type="button" onClick={onBrowse} disabled={processing}>
                <ImagePlus className="h-4 w-4" />
                {hasImage ? changeLabel : uploadLabel}
            </Button>
            {hasImage && (
                <Button
                    type="button"
                    variant="outline"
                    onClick={onRemove}
                    disabled={processing}
                >
                    <Trash2 className="h-4 w-4" />
                    {removeLabel}
                </Button>
            )}
        </div>
    );
}

// ─── Banner ───────────────────────────────────────────────────────────────────

function BannerSection({
    bannerUrl,
    logoUrl,
}: {
    bannerUrl: string | null;
    logoUrl: string | null;
}) {
    const { t } = useTranslation();
    const image = useSingleImage(
        'banner',
        EstablishmentController.updateBanner.url(),
        EstablishmentController.destroyBanner.url(),
    );

    return (
        <Section
            title={t('establishment.banner_title')}
            description={t('establishment.banner_description')}
        >
            {image.fileInput}
            {image.cropSrc && (
                <ImageCropper
                    open
                    src={image.cropSrc}
                    aspect={BANNER_ASPECT}
                    outputWidth={BANNER_WIDTH}
                    quality={0.82}
                    onConfirm={image.upload}
                    onCancel={image.closeCropper}
                />
            )}

            {/* Preview of the profile header: banner behind, logo overlapping its bottom edge. */}
            <div className="relative mb-12">
                <button
                    type="button"
                    onClick={image.browse}
                    disabled={image.processing}
                    aria-label={
                        bannerUrl
                            ? t('establishment.banner_change')
                            : t('establishment.banner_upload')
                    }
                    className="flex aspect-[3/1] w-full items-center justify-center overflow-hidden rounded-xl border border-dashed bg-muted text-muted-foreground transition-colors hover:border-primary hover:text-primary disabled:opacity-60"
                >
                    {bannerUrl ? (
                        <img
                            src={bannerUrl}
                            alt={t('establishment.banner_alt')}
                            className="h-full w-full object-cover"
                        />
                    ) : (
                        <ImagePlus className="size-8 opacity-60" />
                    )}
                </button>
                <div className="pointer-events-none absolute -bottom-9 left-5 flex size-20 items-center justify-center overflow-hidden rounded-2xl border-4 border-card bg-muted shadow-sm">
                    {logoUrl ? (
                        <img
                            src={logoUrl}
                            alt=""
                            className="h-full w-full object-contain p-1.5"
                        />
                    ) : (
                        <Store className="size-7 text-muted-foreground/60" />
                    )}
                </div>
            </div>

            <div className="space-y-3">
                <p className="text-sm text-muted-foreground">
                    {t('establishment.banner_hint')}
                </p>
                <ImageActions
                    hasImage={bannerUrl !== null}
                    processing={image.processing}
                    uploadLabel={t('establishment.banner_upload')}
                    changeLabel={t('establishment.banner_change')}
                    removeLabel={t('establishment.banner_remove')}
                    onBrowse={image.browse}
                    onRemove={image.remove}
                />
                <InputError message={image.error} />
            </div>
        </Section>
    );
}

// ─── Logo ─────────────────────────────────────────────────────────────────────

function LogoSection({ logoUrl }: { logoUrl: string | null }) {
    const { t } = useTranslation();
    const image = useSingleImage(
        'logo',
        EstablishmentController.updateLogo.url(),
        EstablishmentController.destroyLogo.url(),
    );

    return (
        <Section
            title={t('establishment.logo_title')}
            description={t('establishment.logo_description')}
        >
            {image.fileInput}
            {image.cropSrc && (
                <ImageCropper
                    open
                    src={image.cropSrc}
                    aspect={1}
                    outputWidth={LOGO_SIZE}
                    format="webp"
                    onConfirm={image.upload}
                    onCancel={image.closeCropper}
                />
            )}

            <div className="flex flex-wrap items-center gap-5">
                <div className="flex size-28 shrink-0 items-center justify-center overflow-hidden rounded-2xl border border-dashed bg-muted">
                    {logoUrl ? (
                        <img
                            src={logoUrl}
                            alt={t('establishment.logo_alt')}
                            className="h-full w-full object-contain p-2"
                        />
                    ) : (
                        <Store className="size-9 text-muted-foreground/60" />
                    )}
                </div>

                <div className="space-y-3">
                    <p className="max-w-sm text-sm text-muted-foreground">
                        {t('establishment.logo_hint')}
                    </p>
                    <ImageActions
                        hasImage={logoUrl !== null}
                        processing={image.processing}
                        uploadLabel={t('establishment.logo_upload')}
                        changeLabel={t('establishment.logo_change')}
                        removeLabel={t('establishment.logo_remove')}
                        onBrowse={image.browse}
                        onRemove={image.remove}
                    />
                    <InputError message={image.error} />
                </div>
            </div>
        </Section>
    );
}

// ─── Opening hours ────────────────────────────────────────────────────────────

function groupByDay(hours: OpeningHourInterval[]): Interval[][] {
    const days: Interval[][] = Array.from({ length: 7 }, () => []);

    for (const h of hours) {
        days[h.weekday]?.push({ opens_at: h.opens_at, closes_at: h.closes_at });
    }

    return days;
}

/** Flattened weekday 0 → 6, the same order used to read `hours.{i}` errors back. */
function flatten(days: Interval[][]): OpeningHourInterval[] {
    return days.flatMap((intervals, weekday) =>
        intervals.map((i) => ({ weekday, ...i })),
    );
}

function HoursSection({ initial }: { initial: OpeningHourInterval[] }) {
    const { t } = useTranslation();
    const [days, setDays] = useState(() => groupByDay(initial));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const saved = useMemo(() => JSON.stringify(groupByDay(initial)), [initial]);
    const dirty = JSON.stringify(days) !== saved;

    const update = (weekday: number, intervals: Interval[]) =>
        setDays((prev) => prev.map((d, i) => (i === weekday ? intervals : d)));

    const copyToAll = (weekday: number) =>
        setDays((prev) => prev.map(() => prev[weekday].map((i) => ({ ...i }))));

    /** Index of an interval in the flattened payload, to find its errors. */
    const flatIndex = (weekday: number, k: number) =>
        days.slice(0, weekday).reduce((n, d) => n + d.length, 0) + k;

    const save = () => {
        router.put(
            EstablishmentController.updateHours.url(),
            { hours: flatten(days) },
            {
                preserveScroll: true,
                onStart: () => {
                    setProcessing(true);
                    setErrors({});
                },
                onError: setErrors,
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Section
            title={t('establishment.hours_title')}
            description={t('establishment.hours_description')}
        >
            <ul className="divide-y divide-dashed">
                {DISPLAY_ORDER.map((weekday) => {
                    const intervals = days[weekday];
                    const open = intervals.length > 0;

                    return (
                        <li
                            key={weekday}
                            className="flex flex-col gap-3 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-start"
                        >
                            <label className="flex w-40 shrink-0 items-center gap-2.5 pt-2 text-sm font-medium">
                                <Checkbox
                                    checked={open}
                                    onCheckedChange={(checked) =>
                                        update(
                                            weekday,
                                            checked
                                                ? [{ ...DEFAULT_INTERVAL }]
                                                : [],
                                        )
                                    }
                                />
                                {t(`establishment.weekday_${weekday}`)}
                            </label>

                            <div className="min-w-0 flex-1 space-y-2">
                                {!open && (
                                    <p className="pt-2 font-receipt text-xs tracking-[0.14em] text-muted-foreground uppercase">
                                        {t('establishment.closed')}
                                    </p>
                                )}

                                {intervals.map((interval, k) => {
                                    const i = flatIndex(weekday, k);
                                    const overnight =
                                        interval.closes_at !== '' &&
                                        interval.closes_at <= interval.opens_at;
                                    const error =
                                        errors[`hours.${i}.opens_at`] ??
                                        errors[`hours.${i}.closes_at`];
                                    const set = (patch: Partial<Interval>) =>
                                        update(
                                            weekday,
                                            intervals.map((x, j) =>
                                                j === k
                                                    ? { ...x, ...patch }
                                                    : x,
                                            ),
                                        );

                                    return (
                                        <div key={k} className="space-y-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Input
                                                    type="time"
                                                    value={interval.opens_at}
                                                    onChange={(e) =>
                                                        set({
                                                            opens_at:
                                                                e.target.value,
                                                        })
                                                    }
                                                    aria-label={t(
                                                        'establishment.opens_at',
                                                    )}
                                                    aria-invalid={!!error}
                                                    className="w-28 tabular-nums"
                                                    required
                                                />
                                                <span className="text-sm text-muted-foreground">
                                                    {t('establishment.until')}
                                                </span>
                                                <Input
                                                    type="time"
                                                    value={interval.closes_at}
                                                    onChange={(e) =>
                                                        set({
                                                            closes_at:
                                                                e.target.value,
                                                        })
                                                    }
                                                    aria-label={t(
                                                        'establishment.closes_at',
                                                    )}
                                                    aria-invalid={!!error}
                                                    className="w-28 tabular-nums"
                                                    required
                                                />
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    onClick={() =>
                                                        update(
                                                            weekday,
                                                            intervals.filter(
                                                                (_, j) =>
                                                                    j !== k,
                                                            ),
                                                        )
                                                    }
                                                    aria-label={t(
                                                        'establishment.remove_interval',
                                                    )}
                                                >
                                                    <X className="h-4 w-4" />
                                                </Button>
                                                {overnight && (
                                                    <span className="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">
                                                        <Moon className="h-3 w-3" />
                                                        {t(
                                                            'establishment.overnight',
                                                        )}
                                                    </span>
                                                )}
                                            </div>
                                            <InputError message={error} />
                                        </div>
                                    );
                                })}

                                {open && (
                                    <div className="flex flex-wrap gap-1">
                                        {intervals.length <
                                            MAX_INTERVALS_PER_DAY && (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    update(weekday, [
                                                        ...intervals,
                                                        { ...DEFAULT_INTERVAL },
                                                    ])
                                                }
                                            >
                                                <Plus className="h-3.5 w-3.5" />
                                                {t(
                                                    'establishment.add_interval',
                                                )}
                                            </Button>
                                        )}
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => copyToAll(weekday)}
                                        >
                                            <Copy className="h-3.5 w-3.5" />
                                            {t('establishment.copy_to_all')}
                                        </Button>
                                    </div>
                                )}
                            </div>
                        </li>
                    );
                })}
            </ul>
            <InputError message={errors.hours} className="mt-3" />

            {/* Save at the end of the week; it sticks to the bottom of the screen while there are unsaved edits. */}
            <div
                className={[
                    '-mx-5 mt-5 -mb-5 flex flex-wrap items-center justify-end gap-3 rounded-b-xl border-t border-dashed bg-card px-5 py-4',
                    dirty
                        ? 'sticky bottom-0 z-10 shadow-[0_-8px_16px_-12px_oklch(0.2_0.02_155/0.35)]'
                        : '',
                ].join(' ')}
            >
                {dirty && (
                    <span className="mr-auto text-sm text-muted-foreground">
                        {t('establishment.hours_unsaved')}
                    </span>
                )}
                <Button
                    type="button"
                    onClick={save}
                    disabled={!dirty || processing}
                >
                    {t('establishment.hours_save')}
                </Button>
            </div>
        </Section>
    );
}

// ─── Venue photos ─────────────────────────────────────────────────────────────

function PhotosSection({
    photos,
    maxPhotos,
}: {
    photos: VenuePhoto[];
    maxPhotos: number;
}) {
    const { t } = useTranslation();
    const inputRef = useRef<HTMLInputElement>(null);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string>();
    const [toDelete, setToDelete] = useState<VenuePhoto | null>(null);
    const remaining = maxPhotos - photos.length;
    // Picked files wait here and go through the cropper one at a time.
    const [queue, setQueue] = useState<string[]>([]);
    const [queueTotal, setQueueTotal] = useState(0);
    const [cropped, setCropped] = useState<File[]>([]);

    const pick = (e: React.ChangeEvent<HTMLInputElement>) => {
        const files = Array.from(e.target.files ?? []);
        e.target.value = '';

        if (files.length === 0) {
            return;
        }

        if (files.length > remaining) {
            setError(t('establishment.photos_limit', { count: maxPhotos }));

            return;
        }

        const sources = files
            .map((f) => imageSourceUrl(f))
            .filter((src): src is string => src !== null);

        setError(undefined);
        setCropped([]);
        setQueue(sources);
        setQueueTotal(sources.length);
    };

    /** Moves to the next picture; uploads everything once the queue is done. */
    const advance = (files: File[]) => {
        const [current, ...rest] = queue;
        URL.revokeObjectURL(current);
        setQueue(rest);
        setCropped(files);

        if (rest.length === 0 && files.length > 0) {
            upload(files);
        }
    };

    const upload = (files: File[]) => {
        setProcessing(true);

        router.post(
            EstablishmentController.storePhotos.url(),
            { photos: files },
            {
                forceFormData: true,
                preserveScroll: true,
                onError: (errors) =>
                    setError(
                        errors.photos ??
                            Object.entries(errors).find(([k]) =>
                                k.startsWith('photos.'),
                            )?.[1],
                    ),
                onFinish: () => {
                    setProcessing(false);
                    setCropped([]);
                },
            },
        );
    };

    const move = (index: number, delta: -1 | 1) => {
        const ids = photos.map((p) => p.id);
        const target = index + delta;
        [ids[index], ids[target]] = [ids[target], ids[index]];

        router.put(
            EstablishmentController.reorderPhotos.url(),
            { ids },
            { preserveScroll: true },
        );
    };

    const confirmDelete = () => {
        if (!toDelete) {
            return;
        }

        router.delete(
            EstablishmentController.destroyPhoto.url({ photo: toDelete.id }),
            {
                preserveScroll: true,
                onFinish: () => setToDelete(null),
            },
        );
    };

    return (
        <Section
            title={t('establishment.photos_title')}
            description={t('establishment.photos_description')}
            aside={
                <span className="font-receipt text-xs tracking-[0.14em] text-muted-foreground tabular-nums">
                    {photos.length}/{maxPhotos}
                </span>
            }
        >
            <input
                ref={inputRef}
                type="file"
                accept="image/*"
                multiple
                className="hidden"
                onChange={pick}
            />
            {queue.length > 0 && (
                <ImageCropper
                    key={queue[0]}
                    open
                    src={queue[0]}
                    aspect={PHOTO_ASPECT}
                    outputWidth={PHOTO_WIDTH}
                    quality={0.82}
                    description={
                        queueTotal > 1
                            ? t('establishment.photos_crop_progress', {
                                  current: queueTotal - queue.length + 1,
                                  total: queueTotal,
                              })
                            : undefined
                    }
                    onConfirm={(blob) =>
                        advance([
                            ...cropped,
                            croppedFile(blob, `photo-${cropped.length + 1}`),
                        ])
                    }
                    onCancel={() => advance(cropped)}
                />
            )}

            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                {photos.map((photo, index) => (
                    <figure
                        key={photo.id}
                        className="group relative aspect-[4/3] overflow-hidden rounded-xl border bg-muted"
                    >
                        <img
                            src={photo.url}
                            alt={t('establishment.photo_alt', { n: index + 1 })}
                            loading="lazy"
                            decoding="async"
                            className="h-full w-full object-cover"
                        />
                        {index === 0 && (
                            <span className="absolute top-2 left-2 rounded-full bg-primary px-2 py-0.5 text-[11px] font-semibold text-primary-foreground">
                                {t('establishment.photo_cover')}
                            </span>
                        )}
                        <div className="absolute inset-x-0 bottom-0 flex justify-between gap-1 bg-gradient-to-t from-black/60 to-transparent p-2 pt-6">
                            <div className="flex gap-1">
                                <PhotoAction
                                    label={t('establishment.photo_move_left')}
                                    disabled={index === 0}
                                    onClick={() => move(index, -1)}
                                >
                                    <ArrowLeft className="h-4 w-4" />
                                </PhotoAction>
                                <PhotoAction
                                    label={t('establishment.photo_move_right')}
                                    disabled={index === photos.length - 1}
                                    onClick={() => move(index, 1)}
                                >
                                    <ArrowRight className="h-4 w-4" />
                                </PhotoAction>
                            </div>
                            <PhotoAction
                                label={t('establishment.photo_delete')}
                                onClick={() => setToDelete(photo)}
                            >
                                <Trash2 className="h-4 w-4" />
                            </PhotoAction>
                        </div>
                    </figure>
                ))}

                {remaining > 0 && (
                    <button
                        type="button"
                        onClick={() => inputRef.current?.click()}
                        disabled={processing}
                        className="flex aspect-[4/3] flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed text-sm font-medium text-muted-foreground transition-colors hover:border-primary hover:text-primary disabled:opacity-60"
                    >
                        <ImagePlus className="h-6 w-6" />
                        {processing
                            ? t('establishment.photos_uploading')
                            : t('establishment.photos_add')}
                    </button>
                )}
            </div>

            {photos.length === 0 && (
                <p className="mt-3 text-sm text-muted-foreground">
                    {t('establishment.photos_empty')}
                </p>
            )}
            <InputError message={error} className="mt-3" />

            <Dialog
                open={toDelete !== null}
                onOpenChange={(open) => !open && setToDelete(null)}
            >
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle>
                            {t('establishment.photo_delete_title')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('establishment.photo_delete_description')}
                        </DialogDescription>
                    </DialogHeader>
                    {toDelete && (
                        <img
                            src={toDelete.url}
                            alt=""
                            className="aspect-[4/3] w-full rounded-lg object-cover"
                        />
                    )}
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setToDelete(null)}
                        >
                            {t('establishment.cancel')}
                        </Button>
                        <Button variant="destructive" onClick={confirmDelete}>
                            {t('establishment.photo_delete')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </Section>
    );
}

function PhotoAction({
    label,
    disabled,
    onClick,
    children,
}: {
    label: string;
    disabled?: boolean;
    onClick: () => void;
    children: React.ReactNode;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            aria-label={label}
            title={label}
            className="flex size-8 items-center justify-center rounded-full bg-white/90 text-neutral-900 shadow-sm transition-opacity hover:bg-white disabled:opacity-35"
        >
            {children}
        </button>
    );
}

Establishment.layout = {
    breadcrumbs: [
        {
            title: 'establishment.breadcrumb',
            href: EstablishmentController.edit.url(),
        },
    ],
};
