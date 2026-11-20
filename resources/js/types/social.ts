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

/** A restaurant as consumers find it (search results). */
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

/** A "Hoje tem!" story: a picture or a short video, gone 24 hours after posting. */
export type Story = {
    id: number;
    media_type: 'image' | 'video';
    /** Others only get `ready`; the owner also sees videos converting or that failed. */
    status: 'processing' | 'ready' | 'failed';
    /** Null until a video is converted. */
    url: string | null;
    /** First frame of a video, shown while it loads. */
    poster_url: string | null;
    duration_ms: number | null;
    created_at: string;
    expires_at: string;
};

/** One restaurant's live stories in the "Hoje tem!" strip, oldest first. */
export type StoryGroup = {
    restaurant: Pick<PublicRestaurant, 'id' | 'name' | 'handle' | 'logo_url'>;
    /** The viewer is this restaurant (may delete them). */
    is_owner: boolean;
    stories: Story[];
};

/** Upload limits the server enforces, so the browser can say no before sending megabytes. */
export type StoryLimits = {
    maxVideoSeconds: number;
    maxVideoMb: number;
    maxImageMb: number;
    /** False when the server can't convert videos (no ffmpeg): pictures only. */
    videoEnabled: boolean;
};

/** Price level a restaurant chose: 1 ($) to 3 ($$$). */
export type PriceLevel = 1 | 2 | 3;

/** How full the venue is now: 1 calm, 2, 3 packed ("bombando"). */
export type CrowdLevel = 1 | 2 | 3;

/** A restaurant in the discovery grid (/descobrir). */
export type DiscoverRestaurant = DirectoryRestaurant & {
    banner_url: string | null;
    address: string | null;
    /** Null when the restaurant hasn't set its hours. */
    is_open: boolean | null;
    /** Has a "Hoje tem!" story on air. */
    has_stories: boolean;
    /** Null when the restaurant hasn't chosen one. */
    price_level: PriceLevel | null;
    /** Null when the restaurant hasn't said how many tables it has. */
    crowd_level: CrowdLevel | null;
    /** Only when sorting by distance and the restaurant was found on the map. */
    distance_km: number | null;
};

export type DiscoverSort = 'relevance' | 'distance' | 'rating';

/** The discovery page's search, filters and order, as the server read them. */
export type DiscoverFilters = {
    q: string;
    /** [min, max]; [1, 3] means off. */
    crowd: [number, number];
    /** [min, max]; [1, 3] means off. */
    price: [number, number];
    open: boolean;
    sort: DiscoverSort;
};
