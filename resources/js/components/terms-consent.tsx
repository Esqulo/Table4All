import { Trans } from 'react-i18next';
import InputError from '@/components/input-error';
import { Checkbox } from '@/components/ui/checkbox';
import { privacy, terms } from '@/routes';

const linkClass =
    'font-medium text-foreground underline underline-offset-4 hover:decoration-2';

/**
 * "I accept the terms and the privacy policy and I'm 18 or older": required to create an
 * account (LGPD Art. 8 and 14). The documents open in a new tab so the form keeps its input.
 * Uncontrolled inside an Inertia <Form> (sends terms=on); controlled with checked/onChange.
 */
export function TermsConsent({
    error,
    tabIndex,
    checked,
    onCheckedChange,
}: {
    error?: string;
    tabIndex?: number;
    checked?: boolean;
    onCheckedChange?: (checked: boolean) => void;
}) {
    return (
        <div className="grid gap-2">
            <div className="flex items-start gap-3">
                <Checkbox
                    id="terms"
                    name="terms"
                    required
                    tabIndex={tabIndex}
                    className="mt-0.5"
                    aria-invalid={error ? true : undefined}
                    {...(checked === undefined
                        ? {}
                        : {
                              checked,
                              onCheckedChange: (value) =>
                                  onCheckedChange?.(value === true),
                          })}
                />
                <label
                    htmlFor="terms"
                    className="text-sm leading-snug text-muted-foreground"
                >
                    <Trans
                        i18nKey="legal.consent"
                        components={{
                            terms: (
                                <a
                                    href={terms.url()}
                                    target="_blank"
                                    rel="noopener"
                                    className={linkClass}
                                />
                            ),
                            privacy: (
                                <a
                                    href={privacy.url()}
                                    target="_blank"
                                    rel="noopener"
                                    className={linkClass}
                                />
                            ),
                        }}
                    />
                </label>
            </div>
            <InputError message={error} />
        </div>
    );
}
