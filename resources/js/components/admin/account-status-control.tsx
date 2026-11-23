import { Form, router } from '@inertiajs/react';
import { Ban, RotateCcw } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

type Props = {
    user: { id: number; name: string; account_type: string };
    deactivated: boolean;
    size?: 'sm' | 'default';
};

/**
 * Switches an account off (with an optional reason, seen only by admins) or back on.
 * Switching off a restaurant also keeps its team out and hides it from customers.
 */
export default function AccountStatusControl({
    user,
    deactivated,
    size = 'sm',
}: Props) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const isRestaurant = user.account_type === 'restaurant';

    if (deactivated) {
        return (
            <Button
                variant="outline"
                size={size}
                disabled={busy}
                onClick={() => {
                    if (
                        !window.confirm(
                            t('admin_users.reactivate_confirm', {
                                name: user.name,
                            }),
                        )
                    ) {
                        return;
                    }

                    setBusy(true);
                    router.patch(
                        UserController.reactivate.url(user.id),
                        {},
                        {
                            preserveScroll: true,
                            onFinish: () => setBusy(false),
                        },
                    );
                }}
            >
                <RotateCcw className="size-4" />
                {t('admin_users.reactivate')}
            </Button>
        );
    }

    return (
        <>
            <Button
                variant="outline"
                size={size}
                className="text-destructive hover:text-destructive"
                onClick={() => setOpen(true)}
            >
                <Ban className="size-4" />
                {t('admin_users.deactivate')}
            </Button>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>
                            {t('admin_users.deactivate_title', {
                                name: user.name,
                            })}
                        </DialogTitle>
                        <DialogDescription>
                            {isRestaurant
                                ? t('admin_users.deactivate_restaurant_hint')
                                : t('admin_users.deactivate_hint')}
                        </DialogDescription>
                    </DialogHeader>
                    <Form
                        {...UserController.deactivate.form(user.id)}
                        options={{ preserveScroll: true }}
                        className="space-y-4"
                        onSuccess={() => setOpen(false)}
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor={`reason_${user.id}`}>
                                        {t('admin_users.reason')}
                                    </Label>
                                    <Textarea
                                        id={`reason_${user.id}`}
                                        name="reason"
                                        maxLength={500}
                                        placeholder={t(
                                            'admin_users.reason_placeholder',
                                        )}
                                    />
                                    <InputError message={errors.reason} />
                                </div>
                                <DialogFooter>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setOpen(false)}
                                    >
                                        {t('admin_users.cancel')}
                                    </Button>
                                    <Button
                                        type="submit"
                                        variant="destructive"
                                        disabled={processing}
                                    >
                                        {t('admin_users.deactivate')}
                                    </Button>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>
        </>
    );
}
