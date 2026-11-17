/** Emoji reactions of a post: counts per emoji (most used first) and the viewer's own. */
export type PostReactions = {
    summary: { emoji: string; count: number }[];
    total: number;
    mine: string | null;
};

/** Social media formats: feed 4:5, 1:1, 1.91:1 and stories 9:16. */
export type PostImageAspect = 'portrait' | 'square' | 'landscape' | 'story';

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

/** A restaurant as consumers find it (search results, browse strip). */
export type DirectoryRestaurant = {
    id: number;
    name: string;
    handle: string;
    logo_url: string | null;
    rating: RatingSummary;
};

/** A post in the consumers' feed, which mixes restaurants: each carries its own. */
export type FeedPost = RestaurantPost & {
    restaurant: PublicRestaurant;
    /** The viewer is the restaurant that posted it (may delete it). */
    is_owner: boolean;
};
