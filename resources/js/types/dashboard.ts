import type { PaymentMethod } from './restaurant';

export type DashboardPeriod = 'today' | '7' | '30' | '90' | 'custom';

export type DashboardFilters = {
    period: DashboardPeriod;
    from: string;
    to: string;
    previous_from: string;
    previous_to: string;
    today: string;
    max_days: number;
    granularity: 'hour' | 'day';
    category: number | null;
};

export type DashboardSummary = {
    total_revenue: number;
    tables_closed: number;
    items_sold: number;
    avg_table_revenue: number;
    avg_items_per_table: number;
    avg_duration_minutes: number;
    qr_revenue: number;
    abandoned_items: number;
    abandoned_value: number;
};

/** One day (Y-m-d) or one hour ("00"–"23") of the period. */
export type TimelinePoint = {
    bucket: string;
    revenue: number;
    tables_count: number;
    previous_revenue: number;
};

/** Items ordered on an ISO weekday (1 = Monday) at a local hour. */
export type HeatmapCell = {
    weekday: number;
    hour: number;
    items: number;
    revenue: number;
};

export type DashboardProduct = {
    id: number;
    name: string;
    qty: number;
    revenue: number;
};

export type CategoryRevenue = {
    id: number | null;
    name: string | null;
    qty: number;
    revenue: number;
};

export type PaymentMethodTotal = {
    method: PaymentMethod;
    count: number;
    total: number;
};

export type RecentTable = {
    id: number;
    title: string;
    closed_at: string;
    created_at: string;
    total: number;
    items: number;
};
