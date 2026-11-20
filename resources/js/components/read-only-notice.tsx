import { Eye } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import type { StaffFeature } from '@/hooks/use-permission';
import { useCanWrite } from '@/hooks/use-permission';

/** Tells a staff member with read-only access why there is nothing to change on the screen. */
export function ReadOnlyNotice({ feature }: { feature: StaffFeature }) {
    const { t } = useTranslation();

    if (useCanWrite(feature)) {
        return null;
    }

    return (
        <p
            role="status"
            className="flex items-center gap-2 rounded-lg border border-dashed px-3 py-2 text-sm text-muted-foreground"
        >
            <Eye className="h-4 w-4 shrink-0" />
            {t('staff.read_only_notice')}
        </p>
    );
}
