import { useTranslation } from 'react-i18next';
import CouponController from '@/actions/App/Http/Controllers/Admin/CouponController';
import { CouponManager } from '@/components/coupon-manager';
import type { CouponRow } from '@/components/coupon-manager';

export default function AdminCoupons({ coupons }: { coupons: CouponRow[] }) {
    const { t } = useTranslation();

    return (
        <CouponManager
            coupons={coupons}
            actions={CouponController}
            title={t('coupons.platform_title')}
            description={t('coupons.platform_description')}
        />
    );
}

AdminCoupons.layout = {
    breadcrumbs: [
        { title: 'coupons.platform_title', href: CouponController.index.url() },
    ],
};
