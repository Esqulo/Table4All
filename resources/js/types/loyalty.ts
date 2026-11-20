export type CustomerLevel = {
    level: number;
    xp: number;
    /** XP at which the current level started. */
    level_xp: number;
    next_level_xp: number;
};

export type CustomerStats = {
    visits: number;
    places: number;
    best_streak: number;
    ordered: number;
    paid_in_app: number;
    saved: number;
    coupons_used: number;
};

export type AchievementKey =
    | 'first_visit'
    | 'regular'
    | 'explorer'
    | 'loyal'
    | 'saver'
    | 'big_spender';

export type Achievement = {
    key: AchievementKey;
    progress: number;
    goal: number;
    unlocked: boolean;
};

/** At `visits` visits in a streak, a coupon with this discount. */
export type LoyaltyTier = {
    visits: number;
    discount_type: 'percent' | 'fixed';
    discount_value: number;
    max_discount: number | null;
};

export type LoyaltyCard = {
    restaurant: {
        id: number;
        name: string;
        handle: string | null;
        logo_url: string | null;
    };
    /** Visits in the current streak. */
    streak: number;
    last_visit: string;
    /** Last day a visit still keeps the streak (Y-m-d). */
    expires_on: string;
    days_left: number;
    rewards_enabled: boolean;
    /** Visits to the top of the ladder (its last tier); then it starts over. */
    segments: number;
    /** Where the customer is on the ladder (filled segments). */
    progress: number;
    /** The last visit reached a tier. */
    just_earned: boolean;
    /** Rewards earned in the current streak. */
    rewards_earned: number;
    tiers: LoyaltyTier[];
};

export type LoyaltyRules = {
    streak_days: number;
};

export type WalletCoupon = {
    id: number;
    code: string;
    discount_type: 'percent' | 'fixed';
    value: number;
    max_discount: number | null;
    expires_at: string | null;
    status: 'available' | 'used' | 'expired';
    restaurant: { name: string; handle: string | null };
};

export type HistoryPayment = {
    status: 'paid' | 'processing' | 'refunded';
    amount: number;
    charged: number;
    discount: number;
    paid_at: string | null;
};

export type HistoryTable = {
    id: number;
    title: string;
    restaurant: { name: string; handle: string | null };
    joined_at: string;
    closed_at: string | null;
    /** Access code while the table is still open. */
    code: string | null;
    table_total: number;
    my_total: number;
    payments: HistoryPayment[];
};

export type CustomerHistory = {
    data: HistoryTable[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export type IncentiveSetting = {
    key: 'loyalty';
    enabled: boolean;
    enabled_at: string | null;
    tiers: LoyaltyTier[];
    rules: {
        streak_days: number;
        reward_valid_days: number;
        max_tiers: number;
        max_visits: number;
    };
    stats: {
        rewards_granted: number;
        customers: number;
        rewards_used: number;
        discount_given: number;
    };
};
