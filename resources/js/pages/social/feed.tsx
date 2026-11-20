import { Head, InfiniteScroll, Link } from '@inertiajs/react';
import { ChevronRight, Compass, EyeOff } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import DiscoverController from '@/actions/App/Http/Controllers/DiscoverController';
import SocialFeedController from '@/actions/App/Http/Controllers/SocialFeedController';
import Heading from '@/components/heading';
import { PostCard } from '@/components/social/post-card';
import { PostComposer } from '@/components/social/post-composer';
import { StoriesStrip } from '@/components/social/stories';
import type { FeedPost, StoryGroup, StoryLimits } from '@/types';

type Props = {
    /** One page of the feed; InfiniteScroll merges the next ones in. */
    posts?: { data: FeedPost[] };
    canInteract: boolean;
    suggestedReactions: string[];
    /** Restaurants write posts here too. */
    canPost: boolean;
    /** The poster's profile visibility (null for other accounts): hidden posts stay out of the feed. */
    profilePublic: boolean | null;
    /** "Hoje tem!": restaurants with stories from the last 24 hours. */
    stories?: StoryGroup[];
    /** Null for accounts that can't post stories. */
    storyLimits: StoryLimits | null;
};

/** Posts from every public restaurant, newest first (a ranking algorithm comes later). */
export default function SocialFeed({
    posts,
    canInteract,
    suggestedReactions,
    canPost,
    profilePublic,
    stories,
    storyLimits,
}: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('social.feed_title')} />

            <div className="mx-auto w-full max-w-xl space-y-5 p-4 sm:p-6">
                <Heading
                    title={t('social.feed_title')}
                    description={t('social.feed_description')}
                />

                {/* Finding restaurants has its own page: search, filters and closest first. */}
                <Link
                    href={DiscoverController.index.url()}
                    className="flex items-center gap-3 rounded-xl border bg-card px-3 py-2.5 shadow-xs transition-colors hover:bg-muted/50"
                >
                    <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-primary/15 text-primary">
                        <Compass className="h-5 w-5" />
                    </span>
                    <span className="min-w-0 flex-1">
                        <span className="block text-sm font-semibold">
                            {t('social.discover_nav')}
                        </span>
                        <span className="block truncate text-xs text-muted-foreground">
                            {t('social.discover_nav_hint')}
                        </span>
                    </span>
                    <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground" />
                </Link>

                <StoriesStrip groups={stories ?? []} limits={storyLimits} />

                {canPost && (
                    <div className="space-y-2">
                        <PostComposer />
                        {profilePublic === false && (
                            <p className="flex items-center gap-2 text-xs text-muted-foreground">
                                <EyeOff className="h-3.5 w-3.5 shrink-0" />
                                {t('social.composer_hidden_profile')}
                            </p>
                        )}
                    </div>
                )}

                {!posts || posts.data.length === 0 ? (
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
