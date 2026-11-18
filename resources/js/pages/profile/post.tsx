import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, EyeOff, Send, Store, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import PostInteractionController from '@/actions/App/Http/Controllers/PostInteractionController';
import SocialFeedController from '@/actions/App/Http/Controllers/SocialFeedController';
import AppLogoWordmark from '@/components/app-logo-wordmark';
import InputError from '@/components/input-error';
import { ConfirmDialog } from '@/components/social/confirm-dialog';
import { PostCard } from '@/components/social/post-card';
import { SocialNavActions } from '@/components/social/social-nav';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { timeAgo } from '@/lib/relative-time';
import { newRequestKey } from '@/lib/request-key';
import { cn } from '@/lib/utils';
import { home, login } from '@/routes';
import restaurantProfile from '@/routes/restaurant-profile';
import { notice as verificationNotice } from '@/routes/verification';
import type { PostComment, PublicRestaurant, RestaurantPost } from '@/types';

const COMMENT_MAX = 500;

type Props = {
    restaurant: PublicRestaurant;
    isOwner: boolean;
    isPublic: boolean;
    canInteract: boolean;
    suggestedReactions: string[];
    post: RestaurantPost;
    /** The most recent ones, oldest first. */
    comments: PostComment[];
    commentsTotal: number;
    url: string;
};

export default function ProfilePost({
    restaurant,
    isOwner,
    isPublic,
    canInteract,
    suggestedReactions,
    post,
    comments,
    commentsTotal,
}: Props) {
    const { t } = useTranslation();
    const signedIn = Boolean(usePage().props.auth?.user);
    const profileUrl = restaurantProfile.show.url({
        handle: restaurant.handle,
    });

    return (
        <>
            <Head
                title={t('social.post_page_title', { name: restaurant.name })}
            />

            <div
                className={cn(
                    'bg-background pb-12',
                    !signedIn && 'min-h-screen',
                )}
            >
                {!isPublic && (
                    <p className="flex items-center justify-center gap-2 border-b bg-muted px-4 py-3 text-sm">
                        <EyeOff className="h-4 w-4 shrink-0" />
                        {t('venue_profile.hidden_notice')}
                    </p>
                )}

                <header className="sticky top-0 z-10 border-b bg-background/95 backdrop-blur">
                    <div className="mx-auto flex max-w-xl items-center gap-3 px-4 py-3">
                        <Link
                            href={profileUrl}
                            className="flex size-9 items-center justify-center rounded-full hover:bg-muted"
                            aria-label={t('social.back_to_profile')}
                        >
                            <ArrowLeft className="h-5 w-5" />
                        </Link>
                        <Link
                            href={profileUrl}
                            className="flex min-w-0 items-center gap-2.5"
                        >
                            <span className="flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-lg border bg-muted">
                                {restaurant.logo_url ? (
                                    <img
                                        src={restaurant.logo_url}
                                        alt=""
                                        className="h-full w-full object-contain p-0.5"
                                    />
                                ) : (
                                    <Store className="size-4 text-muted-foreground/60" />
                                )}
                            </span>
                            <span className="min-w-0 leading-tight">
                                <span className="block truncate text-sm font-semibold">
                                    {restaurant.name}
                                </span>
                                <span className="block font-receipt text-xs text-muted-foreground">
                                    @{restaurant.handle}
                                </span>
                            </span>
                        </Link>
                        {/* Signed in, the app sidebar already leads to the feed and search. */}
                        {!signedIn && (
                            <div className="ml-auto">
                                <SocialNavActions />
                            </div>
                        )}
                    </div>
                </header>

                <main className="mx-auto mt-4 max-w-xl space-y-6 px-4">
                    <PostCard
                        post={post}
                        restaurant={restaurant}
                        isOwner={isOwner}
                        canInteract={canInteract}
                        signedIn={signedIn}
                        suggestedReactions={suggestedReactions}
                        showCommentsLink={false}
                        eagerImage
                    />

                    <section
                        aria-labelledby="comments-title"
                        className="space-y-4"
                    >
                        <h2
                            id="comments-title"
                            className="font-display text-xl leading-none font-extrabold"
                        >
                            {t('social.comments_count', {
                                count: commentsTotal,
                            })}
                        </h2>

                        {commentsTotal > comments.length && (
                            <p className="text-xs text-muted-foreground">
                                {t('social.comments_showing_recent', {
                                    count: comments.length,
                                })}
                            </p>
                        )}

                        {comments.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                {t('social.comments_empty')}
                            </p>
                        ) : (
                            <ul className="space-y-3">
                                {comments.map((comment) => (
                                    <Comment
                                        key={comment.id}
                                        comment={comment}
                                    />
                                ))}
                            </ul>
                        )}

                        {canInteract ? (
                            <CommentForm postId={post.id} />
                        ) : (
                            <p className="rounded-xl border border-dashed p-4 text-center text-sm text-muted-foreground">
                                <Link
                                    href={
                                        signedIn
                                            ? verificationNotice.url()
                                            : login.url()
                                    }
                                    className="font-medium text-primary hover:underline"
                                >
                                    {signedIn
                                        ? t('social.verify_to_comment')
                                        : t('social.login_to_comment')}
                                </Link>
                            </p>
                        )}
                    </section>
                </main>

                {!signedIn && (
                    <footer className="mt-12 flex justify-center">
                        <Link
                            href={home.url()}
                            className="flex items-center gap-2 text-xs text-muted-foreground"
                        >
                            {t('venue_profile.powered_by')}
                            <AppLogoWordmark className="h-4" />
                        </Link>
                    </footer>
                )}
            </div>
        </>
    );
}

// Used only when signed in (app.tsx gives visitors no layout).
ProfilePost.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'social.feed_title', href: SocialFeedController.index.url() },
        {
            title: 'social.profile_breadcrumb',
            href: restaurantProfile.show.url({
                handle: props.restaurant.handle,
            }),
        },
        { title: 'social.post_breadcrumb', href: '#' },
    ],
});

function Comment({ comment }: { comment: PostComment }) {
    const { t, i18n } = useTranslation();
    const [confirming, setConfirming] = useState(false);
    const [deleting, setDeleting] = useState(false);

    const remove = () =>
        router.delete(
            PostInteractionController.destroyComment.url({
                comment: comment.id,
            }),
            {
                preserveScroll: true,
                onStart: () => setDeleting(true),
                onFinish: () => {
                    setDeleting(false);
                    setConfirming(false);
                },
            },
        );

    return (
        <li className="flex gap-3">
            <span className="flex size-9 shrink-0 items-center justify-center overflow-hidden rounded-full bg-muted text-sm font-semibold text-muted-foreground">
                {comment.author.avatar_url ? (
                    <img
                        src={comment.author.avatar_url}
                        alt=""
                        loading="lazy"
                        decoding="async"
                        className="h-full w-full object-cover"
                    />
                ) : (
                    comment.author.name.charAt(0).toUpperCase()
                )}
            </span>
            <div className="min-w-0 flex-1 rounded-2xl bg-muted/60 px-3.5 py-2.5">
                <div className="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                    <span className="text-sm font-semibold">
                        {comment.author.name}
                    </span>
                    {comment.author.is_restaurant && (
                        <span className="rounded-full bg-primary px-1.5 py-0.5 text-[10px] font-semibold text-primary-foreground">
                            {t('social.comment_by_restaurant')}
                        </span>
                    )}
                    <time
                        dateTime={comment.created_at}
                        className="text-xs text-muted-foreground"
                    >
                        {timeAgo(comment.created_at, i18n.language)}
                    </time>
                    {comment.can_delete && (
                        <button
                            type="button"
                            onClick={() => setConfirming(true)}
                            aria-label={t('social.comment_delete')}
                            className="ml-auto flex size-7 items-center justify-center rounded-full text-muted-foreground hover:bg-background hover:text-destructive"
                        >
                            <Trash2 className="h-3.5 w-3.5" />
                        </button>
                    )}
                </div>
                <p className="mt-0.5 text-sm break-words whitespace-pre-line">
                    {comment.body}
                </p>
            </div>

            <ConfirmDialog
                open={confirming}
                onOpenChange={setConfirming}
                title={t('social.comment_delete_title')}
                description={t('social.comment_delete_description')}
                confirmLabel={t('social.comment_delete')}
                onConfirm={remove}
                processing={deleting}
            />
        </li>
    );
}

function CommentForm({ postId }: { postId: number }) {
    const { t } = useTranslation();
    const [body, setBody] = useState('');
    const [error, setError] = useState<string>();
    const [processing, setProcessing] = useState(false);
    // Kept across retries of the same comment so a re-sent request doesn't post it twice.
    const [requestKey, setRequestKey] = useState(newRequestKey);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();

        router.post(
            PostInteractionController.storeComment.url({ post: postId }),
            { body, request_key: requestKey },
            {
                preserveScroll: true,
                onStart: () => {
                    setProcessing(true);
                    setError(undefined);
                },
                onSuccess: () => {
                    setBody('');
                    setRequestKey(newRequestKey());
                },
                onError: (errors) => setError(errors.body),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <form onSubmit={submit} className="space-y-2">
            <div className="flex items-end gap-2">
                <Textarea
                    value={body}
                    onChange={(e) => setBody(e.target.value)}
                    placeholder={t('social.comment_placeholder')}
                    aria-label={t('social.comment_placeholder')}
                    maxLength={COMMENT_MAX}
                    rows={2}
                    className="min-h-0 resize-none"
                    required
                />
                <Button
                    type="submit"
                    size="icon"
                    disabled={processing || body.trim() === ''}
                    aria-label={t('social.comment_send')}
                >
                    <Send className="h-4 w-4" />
                </Button>
            </div>
            <InputError message={error} />
        </form>
    );
}
