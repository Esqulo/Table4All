import { http } from '@inertiajs/core';
import { Link } from '@inertiajs/react';
import { SmilePlus } from 'lucide-react';
import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import PostInteractionController from '@/actions/App/Http/Controllers/PostInteractionController';
import { cn } from '@/lib/utils';
import { login } from '@/routes';
import type { PostReactions } from '@/types';

/** More emoji offered in the picker after the suggested ones; any other can be typed. */
const MORE_EMOJI = [
    '🍻',
    '🥂',
    '🍷',
    '🍹',
    '🍸',
    '🥃',
    '☕',
    '🍕',
    '🍔',
    '🍟',
    '🌮',
    '🍣',
    '🍖',
    '🍗',
    '🥩',
    '🧀',
    '🍰',
    '🍦',
    '😍',
    '🤤',
    '🤩',
    '🥳',
    '😎',
    '😂',
    '👏',
    '🙌',
    '💯',
    '🎶',
    '🎸',
    '⚽',
    '🙏',
    '😮',
];

/** Rough client check; the server has the final say on what counts as one emoji. */
const LOOKS_LIKE_EMOJI = /^(\p{Extended_Pictographic}|\p{Regional_Indicator})/u;

/** Applies "the viewer reacted with `emoji`" (or removed it, when null) to a summary. */
function applyReaction(
    state: PostReactions,
    emoji: string | null,
): PostReactions {
    const counts = new Map(state.summary.map((r) => [r.emoji, r.count]));

    if (state.mine) {
        counts.set(state.mine, (counts.get(state.mine) ?? 1) - 1);
    }

    if (emoji) {
        counts.set(emoji, (counts.get(emoji) ?? 0) + 1);
    }

    const summary = [...counts]
        .filter(([, count]) => count > 0)
        .map(([e, count]) => ({ emoji: e, count }))
        .sort((a, b) => b.count - a.count || a.emoji.localeCompare(b.emoji));

    return {
        summary,
        total: summary.reduce((n, r) => n + r.count, 0),
        mine: emoji,
    };
}

export function ReactionBar({
    postId,
    initial,
    suggested,
    canInteract,
    signedIn,
}: {
    postId: number;
    initial: PostReactions;
    suggested: string[];
    canInteract: boolean;
    signedIn: boolean;
}) {
    const { t } = useTranslation();
    const [state, setState] = useState(initial);
    const [open, setOpen] = useState(false);
    const [custom, setCustom] = useState('');
    // Only the answer to the latest tap counts; earlier ones may arrive late on slow networks.
    const latest = useRef(0);

    const send = async (emoji: string | null) => {
        const previous = state;
        const ticket = ++latest.current;
        setState(applyReaction(state, emoji));
        setOpen(false);

        try {
            const route = emoji
                ? PostInteractionController.react({ post: postId })
                : PostInteractionController.unreact({ post: postId });
            const response = await http.getClient().request({
                method: route.method,
                url: route.url,
                data: emoji ? { emoji } : undefined,
                headers: { Accept: 'application/json' },
            });

            if (ticket === latest.current) {
                setState(JSON.parse(response.data) as PostReactions);
            }
        } catch {
            if (ticket === latest.current) {
                setState(previous);
                toast.error(t('social.reaction_failed'));
            }
        }
    };

    const toggle = (emoji: string) => send(state.mine === emoji ? null : emoji);

    const submitCustom = (e: React.FormEvent) => {
        e.preventDefault();
        const value = custom.trim();

        if (!LOOKS_LIKE_EMOJI.test(value)) {
            toast.error(t('social.reaction_invalid'));

            return;
        }

        setCustom('');
        void toggle(value);
    };

    return (
        <div className="space-y-2">
            <div className="flex flex-wrap items-center gap-1.5">
                {state.summary.map((r) => {
                    const mine = r.emoji === state.mine;
                    const chip = (
                        <>
                            <span className="text-base leading-none">
                                {r.emoji}
                            </span>
                            <span className="tabular-nums">{r.count}</span>
                        </>
                    );
                    const classes = cn(
                        'inline-flex h-8 items-center gap-1.5 rounded-full border px-2.5 text-xs font-medium transition-colors',
                        mine
                            ? 'border-primary bg-primary/10 text-primary'
                            : 'bg-card hover:bg-muted',
                    );

                    return canInteract ? (
                        <button
                            key={r.emoji}
                            type="button"
                            onClick={() => toggle(r.emoji)}
                            aria-pressed={mine}
                            aria-label={t(
                                mine
                                    ? 'social.reaction_remove'
                                    : 'social.reaction_add',
                                { emoji: r.emoji, count: r.count },
                            )}
                            className={classes}
                        >
                            {chip}
                        </button>
                    ) : (
                        <span key={r.emoji} className={classes}>
                            {chip}
                        </span>
                    );
                })}

                {canInteract ? (
                    <button
                        type="button"
                        onClick={() => setOpen((o) => !o)}
                        aria-expanded={open}
                        className="inline-flex h-8 items-center gap-1.5 rounded-full border border-dashed px-2.5 text-xs font-medium text-muted-foreground transition-colors hover:border-primary hover:text-primary"
                    >
                        <SmilePlus className="h-4 w-4" />
                        {state.summary.length === 0 && t('social.react')}
                        <span className="sr-only">{t('social.react')}</span>
                    </button>
                ) : (
                    !signedIn && (
                        <Link
                            href={login.url()}
                            className="inline-flex h-8 items-center gap-1.5 rounded-full border border-dashed px-2.5 text-xs font-medium text-muted-foreground hover:text-primary"
                        >
                            <SmilePlus className="h-4 w-4" />
                            {t('social.login_to_react')}
                        </Link>
                    )
                )}
            </div>

            {open && (
                <div className="space-y-3 rounded-xl border bg-card p-3 shadow-sm">
                    <div className="flex justify-between gap-1">
                        {suggested.map((emoji) => (
                            <EmojiButton
                                key={emoji}
                                emoji={emoji}
                                selected={state.mine === emoji}
                                onClick={() => toggle(emoji)}
                                large
                            />
                        ))}
                    </div>
                    <div className="grid grid-cols-8 gap-1 border-t border-dashed pt-3">
                        {MORE_EMOJI.map((emoji) => (
                            <EmojiButton
                                key={emoji}
                                emoji={emoji}
                                selected={state.mine === emoji}
                                onClick={() => toggle(emoji)}
                            />
                        ))}
                    </div>
                    <form
                        onSubmit={submitCustom}
                        className="flex items-center gap-2 border-t border-dashed pt-3"
                    >
                        <input
                            value={custom}
                            onChange={(e) => setCustom(e.target.value)}
                            placeholder={t('social.reaction_custom')}
                            aria-label={t('social.reaction_custom')}
                            maxLength={32}
                            className="h-9 min-w-0 flex-1 rounded-md border bg-transparent px-3 text-base outline-none focus-visible:border-ring md:text-sm"
                        />
                        <button
                            type="submit"
                            disabled={custom.trim() === ''}
                            className="h-9 rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground disabled:opacity-50"
                        >
                            {t('social.reaction_custom_send')}
                        </button>
                    </form>
                </div>
            )}
        </div>
    );
}

function EmojiButton({
    emoji,
    selected,
    onClick,
    large,
}: {
    emoji: string;
    selected: boolean;
    onClick: () => void;
    large?: boolean;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={selected}
            className={cn(
                'flex items-center justify-center rounded-lg transition-transform hover:scale-110 hover:bg-muted',
                large ? 'size-12 text-3xl' : 'size-9 text-xl',
                selected && 'bg-primary/15 ring-2 ring-primary',
            )}
        >
            {emoji}
        </button>
    );
}
