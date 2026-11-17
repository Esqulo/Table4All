import { Head, InfiniteScroll } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import SocialFeedController from '@/actions/App/Http/Controllers/SocialFeedController';
import Heading from '@/components/heading';
import { PostCard } from '@/components/social/post-card';
import {
    RestaurantSearchBox,
    RestaurantSearchResults,
    RestaurantStrip,
} from '@/components/social/restaurant-search';
import { SEARCH_HASH } from '@/lib/social';
import type { DirectoryRestaurant, FeedPost } from '@/types';

type Props = {
    /** One page of the feed; InfiniteScroll merges the next ones in. */
    posts: { data: FeedPost[] };
    canInteract: boolean;
    suggestedReactions: string[];
    /** Current search term (?q=). */
    q: string;
    /** Null while there is no search (term shorter than 2 characters). */
    searchResults: DirectoryRestaurant[] | null;
    /** Recently active restaurants, to browse profiles. */
    restaurants: DirectoryRestaurant[];
};

/** Posts from every public restaurant, newest first (a ranking algorithm comes later). */
export default function SocialFeed({
    posts,
    canInteract,
    suggestedReactions,
    q,
    searchResults,
    restaurants,
}: Props) {
    const { t } = useTranslation();
    const focusSearch =
        typeof window !== 'undefined' && window.location.hash === SEARCH_HASH;

    return (
        <>
            <Head title={t('social.feed_title')} />

            <div className="mx-auto w-full max-w-xl space-y-5 p-4 sm:p-6">
                <Heading
                    title={t('social.feed_title')}
                    description={t('social.feed_description')}
                />

                <RestaurantSearchBox initial={q} autoFocus={focusSearch} />

                {searchResults !== null ? (
                    <RestaurantSearchResults results={searchResults} />
                ) : (
                    <>
                        <RestaurantStrip restaurants={restaurants} />

                        {posts.data.length === 0 ? (
                            <p className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                                {t('social.feed_empty_all')}
                            </p>
                        ) : (
                            <InfiniteScroll
                                data="posts"
                                onlyNext
                                className="space-y-4"
                                loading={() => (
                                    <p className="py-4 text-center text-sm text-muted-foreground">
                                        {t('social.feed_loading')}
                                    </p>
                                )}
                            >
                                {posts.data.map((post, i) => (
                                    <PostCard
                                        key={post.id}
                                        post={post}
                                        restaurant={post.restaurant}
                                        isOwner={post.is_owner}
                                        canInteract={canInteract}
                                        signedIn
                                        suggestedReactions={suggestedReactions}
                                        eagerImage={i === 0}
                                    />
                                ))}
                            </InfiniteScroll>
                        )}
                    </>
                )}
            </div>
        </>
    );
}

SocialFeed.layout = {
    breadcrumbs: [
        {
            title: 'social.feed_title',
            href: SocialFeedController.index.url(),
        },
    ],
};
