export type PriceType = 'unit' | 'kg' | '100g' | 'liter' | 'portion';

export type StaffRole = 'waiter' | 'manager';

export type PermissionLevel = 'none' | 'read' | 'write';

/** A member of the restaurant's team, as the team page lists it. */
export type Waiter = {
    id: number;
    name: string;
    email: string;
    role: StaffRole;
    /** What was chosen per feature (the editor's values). */
    permissions: Record<string, PermissionLevel>;
    /** What it amounts to with the role (a manager reads everything). */
    access: Record<string, PermissionLevel>;
    /** The viewer may change this member's access or remove them. */
    manageable: boolean;
    is_self: boolean;
    created_at: string;
};

export type WaiterInvitation = {
    id: number;
    email: string;
    role: StaffRole;
    cancellable: boolean;
    created_at: string;
};

/** A feature whose access is granted per member, and the most staff can have there. */
export type StaffFeatureInfo = {
    key: string;
    ceiling: PermissionLevel;
};

export type Category = {
    id: number;
    name: string;
};

export type RestaurantQueue = {
    id: number;
    user_id: number;
    name: string;
    products_count?: number;
    created_at: string;
    updated_at: string;
};

export type Menu = {
    id: number;
    user_id: number;
    name: string;
    created_at: string;
    updated_at: string;
};

export type Product = {
    id: number;
    user_id: number;
    category_id: number | null;
    category: Category | null;
    queue_id: number | null;
    queue: RestaurantQueue | null;
    name: string;
    description: string | null;
    picture: string | null;
    picture_url: string | null;
    price: number;
    price_type: PriceType;
    created_at: string;
    updated_at: string;
};

export type OrderItem = Pick<
    Product,
    'id' | 'name' | 'picture' | 'picture_url' | 'price' | 'price_type'
> & {
    pivot: { quantity: number };
};

// A single priced line in a table's order (one row in restaurant_table_product).
// The same product can appear more than once if it was ordered at different prices.
export type TableOrderLine = {
    id: number;
    name: string;
    picture: string | null;
    picture_url: string | null;
    price_type: PriceType;
    price: number;
    quantity: number;
};

export type QueueItemStatus = 'pending' | 'done' | 'delivered' | 'abandoned';

export type QueueItem = {
    id: number;
    restaurant_table_id: number;
    product_id: number;
    product: {
        id: number;
        name: string;
        picture_url: string | null;
        price: number;
        price_type: PriceType;
    };
    queue_id: number | null;
    queue: { id: number; name: string } | null;
    restaurant_table?: { id: number; title: string };
    quantity: number;
    price: number;
    status: QueueItemStatus;
    created_at: string;
    updated_at: string;
};

export type PaymentMethod = 'cash' | 'pix' | 'card' | 'coupon' | 'online';

export type PaymentRegistrar = 'waiter' | 'restaurant' | 'customer' | 'app';

export type TablePayment = {
    id: number;
    restaurant_table_id: number;
    method: PaymentMethod;
    amount: number;
    registered_by_id: number | null;
    registered_by_type: PaymentRegistrar | null;
    registered_by: { id: number; name: string } | null;
    created_at: string;
};

export type SaleType = 'periodic' | 'scheduled';

export type Sale = {
    id: number;
    user_id: number;
    product_id: number;
    product: Pick<Product, 'id' | 'name' | 'price' | 'price_type'>;
    type: SaleType;
    sale_price: number;
    // periodic
    days: number[] | null;
    start_time: string | null;
    end_time: string | null;
    // scheduled
    starts_at: string | null;
    ends_at: string | null;
    created_at: string;
    updated_at: string;
};

export type RestaurantTable = {
    id: number;
    user_id: number;
    title: string;
    access_code: string | null;
    closed_at: string | null;
    /** Up to five products, for the board thumbnails. */
    products: Pick<OrderItem, 'id' | 'name' | 'picture' | 'picture_url'>[];
    products_count: number;
    order_total: number;
    paid_total: number;
    created_at: string;
    updated_at: string;
};

export type AdmissionRequestStatus = 'pending' | 'contacted' | 'archived';

export type AdmissionRequest = {
    id: number;
    business_name: string;
    contact_email: string;
    phone: string | null;
    city: string | null;
    tables: number | null;
    message: string | null;
    status: AdmissionRequestStatus;
    created_at: string;
    updated_at: string;
};

/** One opening interval; `closes_at <= opens_at` means it ends after midnight. */
export type OpeningHourInterval = {
    weekday: number;
    opens_at: string;
    closes_at: string;
};

export type VenuePhoto = {
    id: number;
    position: number;
    url: string;
};
