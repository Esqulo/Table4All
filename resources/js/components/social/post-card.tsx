import { Link, router } from '@inertiajs/react';
import { MessageCircle, MoreHorizontal, Store, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import PostController from '@/actions/App/Http/Controllers/Restaurant/PostController';
import { ConfirmDialog } from '@/components/social/confirm-dialog';
import { ReactionBar } from '@/components/social/reaction-bar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { timeAgo } from '@/lib/relative-time';
import { cn } from '@/lib/utils';
import restaurantProfile from '@/routes/restaurant-profile';
import type {
    PostImageAspect,
    PublicRestaurant,
    RestaurantPost,
} from '@/types';

/** Space reserved for the picture before it loads, from the format it was cropped to. */
const ASPECT_CLASS: Record<PostImageAspect, string> = {
    square: 'aspect-square',
    portrait: 'aspect-[4/5]',
    landscape: 'aspect-video',
};

export function PostCard({
    post,
    restaurant,
    isOwner,
    canInteract,
    signedIn,
    suggestedReactions,
    showCommentsLink = true,
    eagerImage = false,
}: {
    post: RestaurantPost;
    restaurant: PublicRestaurant;
    isOwner: boolean;
    canInteract: boolean;
    signedIn: boolean;
    suggestedReactions: string[];
    showCommentsLink?: boolean;
    eagerImage?: boolean;
}) {
    const { t, i18n } = useTranslation();
    const [confirming, setConfirming] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const postUrl = restaurantProfile.post.url({
        handle: restaurant.handle,
        post: post.id,
    });

    const remove = () =>
        router.delete(PostController.destroy.url({ post: post.id }), {
            onStart: () => setDeleting(true),
            onFinish: () => {
                setDeleting(false);
                setConfirming(false);
            },
        });

    return (
        <article className="overflow-hidden rounded-2xl border bg-card shadow-xs">
            <header className="flex items-center gap-3 px-4 pt-4 pb-3">
                <div className="flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-xl border bg-muted">
                    {restaurant.logo_url ? (
                        <img
                            src={restaurant.logo_url}
                            alt=""
                            loading="lazy"
                            decoding="async"
                            className="h-full w-full object-contain p-0.5"
                        />
                    ) : (
                        <Store className="size-4 text-muted-foreground/60" />
                    )}
                </div>
                <div className="min-w-0 flex-1 leading-tight">
                    <p className="truncate text-sm font-semibold">
                        {restaurant.name}
                    </p>
                    <Link
                        href={postUrl}
                        className="text-xs text-muted-foreground hover:underline"
                    >
                        <time dateTime={post.created_at}>
                            {timeAgo(post.created_at, i18n.language)}
                        </time>
                    </Link>
                </div>
                {isOwner && (
                    <DropdownMenu>
                        <DropdownMenuTrigger
                            className="flex size-8 items-center justify-center rounded-full text-muted-foreground hover:bg-muted"
                            aria-label={t('social.post_options')}
                        >
                            <MoreHorizontal className="h-4 w-4" />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem
                                variant="destructive"
                                onSelect={() => setConfirming(true)}
                            >
                                <Trash2 className="h-4 w-4" />
                                {t('social.post_delete')}
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
            </header>

            {post.body && (
                <p className="px-4 pb-3 text-[15px] leading-relaxed break-words whitespace-pre-line">
                    {post.body}
                </p>
            )}

            {post.image_url && (
                <div
                    className={cn(
                        'bg-muted',
                        ASPECT_CLASS[post.image_aspect ?? 'portrait'],
                    )}
                >
                    <img
                        src={post.image_url}
                        alt={t('social.post_image_alt', {
                            name: restaurant.name,
                        })}
                        loading={eagerImage ? 'eager' : 'lazy'}
                        decoding="async"
                        className="h-full w-full object-cover"
                    />
                </div>
            )}

            <footer className="flex flex-wrap items-start justify-between gap-2 px-4 py-3">
                <ReactionBar
                    postId={post.id}
                    initial={post.reactions}
                    suggested={suggestedReactions}
                    canInteract={canInteract}
                    signedIn={signedIn}
                />
                {showCommentsLink && (
                    <Link
                        href={postUrl}
                        className="inline-flex h-8 items-center gap-1.5 rounded-full px-2.5 text-xs font-medium text-muted-foreground hover:bg-muted hover:text-foreground"
                    >
                        <MessageCircle className="h-4 w-4" />
                        {t('social.comments_count', {
                            count: post.comments_count,
                        })}
                    </Link>
                )}
            </footer>

            <ConfirmDialog
                open={confirming}
                onOpenChange={setConfirming}
                title={t('social.post_delete_title')}
                description={t('social.post_delete_description')}
                confirmLabel={t('social.post_delete')}
                onConfirm={remove}
                processing={deleting}
            />
        </article>
    );
}
