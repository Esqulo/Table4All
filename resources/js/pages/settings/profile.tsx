import { Form, Head, usePage } from '@inertiajs/react';
import { Link } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/delete-user';
import DownloadMyData from '@/components/download-my-data';
import Heading from '@/components/heading';
import { ImageCropper } from '@/components/image-cropper';
import InputError from '@/components/input-error';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { croppedFile, imageSourceUrl, setInputFile } from '@/lib/image-upload';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';
import type { Auth } from '@/types';

type PageProps = {
    auth: Auth;
};

export default function Profile({
    mustVerifyEmail,
    status,
}: {
    mustVerifyEmail: boolean;
    status?: string;
}) {
    const { t } = useTranslation();
    const { auth } = usePage<PageProps>().props;
    const [avatarPreview, setAvatarPreview] = useState<string | null>(null);
    const [cropSrc, setCropSrc] = useState<string | null>(null);
    const pickerRef = useRef<HTMLInputElement>(null);
    const avatarInputRef = useRef<HTMLInputElement>(null);

    const closeCropper = () => {
        if (cropSrc) {
            URL.revokeObjectURL(cropSrc);
        }

        setCropSrc(null);
    };

    const onCropConfirm = (blob: Blob) => {
        closeCropper();
        setInputFile(avatarInputRef.current, croppedFile(blob, 'avatar'));
        setAvatarPreview(URL.createObjectURL(blob));
    };

    const avatarSrc = avatarPreview ?? auth.user.avatar_url ?? undefined;
    const avatarFallback = auth.user.name.charAt(0).toUpperCase();

    return (
        <>
            <Head title={t('settings.profile.head')} />

            <h1 className="sr-only">{t('settings.profile.head')}</h1>

            {cropSrc && (
                <ImageCropper
                    open
                    src={cropSrc}
                    aspect={1}
                    outputWidth={400}
                    onConfirm={onCropConfirm}
                    onCancel={closeCropper}
                />
            )}

            {/* Picker outside the form: only the cropped file is submitted. */}
            <input
                ref={pickerRef}
                type="file"
                accept="image/*"
                className="hidden"
                onChange={(e) => {
                    const src = imageSourceUrl(e.target.files?.[0]);
                    e.target.value = '';

                    if (src) {
                        setCropSrc(src);
                    }
                }}
            />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('settings.profile.section_title')}
                    description={t('settings.profile.section_description')}
                />

                <Form
                    {...ProfileController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">
                                    {t('settings.profile.name_label')}
                                </Label>
                                <Input
                                    id="name"
                                    className="mt-1 block w-full"
                                    defaultValue={auth.user.name}
                                    name="name"
                                    required
                                    autoComplete="name"
                                    placeholder={t(
                                        'settings.profile.name_placeholder',
                                    )}
                                />
                                <InputError
                                    className="mt-2"
                                    message={errors.name}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="email">
                                    {t('settings.profile.email_label')}
                                </Label>
                                <Input
                                    id="email"
                                    type="email"
                                    className="mt-1 block w-full"
                                    defaultValue={auth.user.email}
                                    name="email"
                                    required
                                    autoComplete="username"
                                    placeholder={t(
                                        'settings.profile.email_placeholder',
                                    )}
                                />
                                <InputError
                                    className="mt-2"
                                    message={errors.email}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="phone">
                                    {t('settings.profile.phone_label')}
                                </Label>
                                <div className="mt-1 flex">
                                    <span className="inline-flex h-9 items-center rounded-l-md border border-r-0 border-input bg-muted px-3 text-sm text-muted-foreground select-none">
                                        +55
                                    </span>
                                    <Input
                                        id="phone"
                                        type="tel"
                                        defaultValue={
                                            auth.user.phone?.replace(
                                                /^\+55/,
                                                '',
                                            ) ?? ''
                                        }
                                        name="phone"
                                        autoComplete="tel"
                                        placeholder={t(
                                            'settings.profile.phone_placeholder',
                                        )}
                                        className="rounded-l-none"
                                    />
                                </div>
                                <InputError
                                    className="mt-2"
                                    message={errors.phone}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="avatar">
                                    {t('settings.profile.avatar_label')}
                                </Label>
                                <div className="flex items-center gap-4">
                                    <Avatar className="h-16 w-16">
                                        <AvatarImage
                                            src={avatarSrc}
                                            alt={auth.user.name}
                                        />
                                        <AvatarFallback className="text-lg">
                                            {avatarFallback}
                                        </AvatarFallback>
                                    </Avatar>
                                    <input
                                        ref={avatarInputRef}
                                        type="file"
                                        name="avatar"
                                        className="hidden"
                                        tabIndex={-1}
                                        aria-hidden
                                    />
                                    <Button
                                        id="avatar"
                                        type="button"
                                        variant="outline"
                                        onClick={() =>
                                            pickerRef.current?.click()
                                        }
                                    >
                                        {t('settings.profile.avatar_choose')}
                                    </Button>
                                </div>
                                <InputError
                                    className="mt-2"
                                    message={errors.avatar}
                                />
                            </div>

                            {mustVerifyEmail &&
                                auth.user.email_verified_at === null && (
                                    <div>
                                        <p className="-mt-4 text-sm text-muted-foreground">
                                            {t(
                                                'settings.profile.unverified_email',
                                            )}{' '}
                                            <Link
                                                href={send()}
                                                as="button"
                                                className="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                                            >
                                                {t(
                                                    'settings.profile.resend_verification',
                                                )}
                                            </Link>
                                        </p>

                                        {status ===
                                            'verification-link-sent' && (
                                            <div className="mt-2 text-sm font-medium text-green-600">
                                                {t(
                                                    'settings.profile.verification_sent',
                                                )}
                                            </div>
                                        )}
                                    </div>
                                )}

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-profile-button"
                                >
                                    {t('settings.profile.save')}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>

            <DownloadMyData />

            <DeleteUser />
        </>
    );
}

Profile.layout = {
    breadcrumbs: [
        {
            title: 'settings.profile.title',
            href: edit(),
        },
    ],
};
