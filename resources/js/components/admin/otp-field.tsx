import { REGEXP_ONLY_DIGITS } from 'input-otp';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { OTP_MAX_LENGTH } from '@/hooks/use-two-factor-auth';

/** The 6 digits of the authenticator app, sent as `code`. */
export default function OtpField({
    value,
    onChange,
    disabled,
}: {
    value: string;
    onChange: (value: string) => void;
    disabled?: boolean;
}) {
    return (
        <div className="flex w-full items-center justify-center">
            <InputOTP
                name="code"
                maxLength={OTP_MAX_LENGTH}
                value={value}
                onChange={onChange}
                disabled={disabled}
                pattern={REGEXP_ONLY_DIGITS}
                autoComplete="one-time-code"
                autoFocus
            >
                <InputOTPGroup>
                    {Array.from({ length: OTP_MAX_LENGTH }, (_, index) => (
                        <InputOTPSlot key={index} index={index} />
                    ))}
                </InputOTPGroup>
            </InputOTP>
        </div>
    );
}
