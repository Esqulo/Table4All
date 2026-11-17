/** Emoji reactions of a post: counts per emoji (most used first) and the viewer's own. */
export type PostReactions = {
    summary: { emoji: string; count: number }[];
    total: number;
    mine: string | null;
};

export type PostImageAspect = 'square' | 'portrait' | 'landscape';

export type RestaurantPost = {
    id: number;
    body: string | null;
    image_url: string | null;
    image_aspect: PostImageAspect | null;
    created_at: string;
    reactions: PostReactions;
    comments_count: number;
};

export type PostComment = {
    id: number;
    body: string;
    created_at: string;
    author: {
        name: string;
        avatar_url: string | null;
        is_restaurant: boolean;
    };
    can_delete: boolean;
};

/** Public identity of a restaurant, as the profile pages receive it. */
export type PublicRestaurant = {
    id: number;
    name: string;
    handle: string;
    logo_url: string | null;
    banner_url: string | null;
};

export type RatingSummary = { average: number; count: number };
