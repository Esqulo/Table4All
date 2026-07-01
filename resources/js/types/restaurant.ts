export type PriceType = 'unit' | 'kg' | '100g' | 'liter' | 'portion';

export type Waiter = {
    id: number;
    name: string;
    email: string;
    created_at: string;
};

export type WaiterInvitation = {
    id: number;
    email: string;
    created_at: string;
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

export type OrderItem = Pick<Product, 'id' | 'name' | 'picture' | 'picture_url' | 'price' | 'price_type'> & {
    pivot: { quantity: number };
};

export type QueueItemStatus = 'pending' | 'done' | 'delivered';

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
    queue_id: number;
    queue: { id: number; name: string };
    restaurant_table?: { id: number; title: string };
    quantity: number;
    status: QueueItemStatus;
    created_at: string;
    updated_at: string;
};

export type PaymentMethod = 'cash' | 'pix' | 'card' | 'coupon';

export type PaymentRegistrar = 'waiter' | 'restaurant' | 'app';

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

export type RestaurantTable = {
    id: number;
    user_id: number;
    title: string;
    closed_at: string | null;
    products: OrderItem[];
    payments: TablePayment[];
    products_count: number;
    created_at: string;
    updated_at: string;
};
