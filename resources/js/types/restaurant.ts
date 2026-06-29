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
