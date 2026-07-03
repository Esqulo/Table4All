import { Head, router } from '@inertiajs/react';
import { REGEXP_ONLY_DIGITS } from 'input-otp';
import { useTranslation } from 'react-i18next';
import { InputOTP, InputOTPGroup, InputOTPSlot } from '@/components/ui/input-otp';

export default function JoinTable() {
    const { t } = useTranslation();

    function handleChange(value: string) {
        if (value.length === 6) {
            router.visit(`/mesa/${value}`);
        }
    }

    return (
        <>
            <Head title={t('join_table.title')} />

            <div className="flex flex-1 flex-col items-center justify-center px-4 py-12">
                <div className="w-full max-w-sm space-y-8 text-center">
                    <div className="space-y-2">
                        <h1 className="text-2xl font-bold tracking-tight">
                            {t('join_table.heading')}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {t('join_table.description')}
                        </p>
                    </div>

                    <div className="flex justify-center">
                        <InputOTP
                            maxLength={6}
                            pattern={REGEXP_ONLY_DIGITS}
                            onChange={handleChange}
                            autoFocus
                        >
                            <InputOTPGroup>
                                {Array.from({ length: 6 }, (_, i) => (
                                    <InputOTPSlot key={i} index={i} className="h-14 w-12 text-xl" />
                                ))}
                            </InputOTPGroup>
                        </InputOTP>
                    </div>
                </div>
            </div>
        </>
    );
}
