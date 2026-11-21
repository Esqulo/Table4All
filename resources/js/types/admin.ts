import type { PaymentMethodTotal } from './dashboard';

/** Orders, bills and payments (PlatformStats::activity), platform-wide or for one restaurant. */
export type AdminActivity = {
    tables: { total: number; month: number; previous: number; day: number };
    items: {
        total: number;
        month: number;
        previous: number;
        day: number;
        abandoned_month: number;
    };
    payments: {
        count_total: number;
        count_month: number;
        count_previous: number;
        total: number;
        month: number;
        previous: number;
        day: number;
        avg_ticket_month: number;
        avg_ticket_previous: number;
    };
    online: {
        count_total: number;
        count_month: number;
        total: number;
        month: number;
        fee_total: number;
        fee_month: number;
        fee_previous: number;
        platform_coupons_total: number;
    };
};

export type AdminDailyPoint = {
    day: string;
    revenue: number;
    tables: number;
    items: number;
    signups?: number;
};

export type AdminPaymentMethods = PaymentMethodTotal[];

export type FeeTierOption = { key: string; name: string; fee_percent: number };
