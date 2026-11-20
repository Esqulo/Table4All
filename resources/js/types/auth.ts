export type AccountType = 'admin' | 'restaurant' | 'customer' | 'waiter';

export type User = {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    avatar: string | null;
    avatar_url: string | null;
    /** Restaurants' logo (appended by the User model). */
    logo_url?: string | null;
    account_type: AccountType;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
    /** Access per restaurant feature (null for accounts outside a restaurant's team). */
    permissions?: Record<string, 'none' | 'read' | 'write'> | null;
    /** 'waiter' or 'manager' for staff accounts, null otherwise. */
    staffRole?: 'waiter' | 'manager' | null;
};

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
