import { useState } from 'react';
import { Input } from '@/components/ui/input';

type Props = {
    name: string;
    id?: string;
    defaultValue?: number; // decimal, e.g. 12.34
    placeholder?: string;
};

export function CurrencyInput({ name, id, defaultValue, placeholder = '0,00' }: Props) {
    const [cents, setCents] = useState(() =>
        defaultValue ? Math.round(defaultValue * 100) : 0,
    );

    const displayValue = cents > 0
        ? (cents / 100).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
        : '';

    const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const digits = e.target.value.replace(/\D/g, '');
        setCents(digits === '' ? 0 : Math.min(parseInt(digits, 10), 9_999_999));
    };

    return (
        <>
            <input type="hidden" name={name} value={(cents / 100).toFixed(2)} />
            <Input
                id={id}
                type="text"
                inputMode="numeric"
                value={displayValue}
                onChange={handleChange}
                placeholder={placeholder}
                autoComplete="off"
            />
        </>
    );
}
