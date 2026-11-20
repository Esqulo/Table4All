import { useTranslation } from 'react-i18next';
import CouponController from '@/actions/App/Http/Controllers/Restaurant/CouponController';
import { CouponManager } from '@/components/coupon-manager';
import type { CouponRow } from '@/components/coupon-manager';
import { useCanWrite } from '@/hooks/use-permission';

export default function RestaurantCoupons({
    coupons,
}: {
    coupons: CouponRow[];
}) {
    const { t } = useTranslation();
    const canWrite = useCanWrite('coupons');

    return (
        <CouponManager
            coupons={coupons}
            actions={CouponController}
            title={t('coupons.title')}
            description={t('coupons.restaurant_description')}
            readOnly={!canWrite}
        />
    );
}

RestaurantCoupons.layout = {
    breadcrumbs: [
        { title: 'coupons.title', href: CouponController.index.url() },
    ],
};
