export type PriceType = 'unit' | 'kg' | '100g' | 'liter' | 'portion';

export type Product = {
    id: number;
    user_id: number;
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

export type PaymentMethod = 'cash' | 'pix' | 'card' | 'coupon';

export type TablePayment = {
    id: number;
    restaurant_table_id: number;
    method: PaymentMethod;
    amount: number;
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
