import SocialFeedController from '@/actions/App/Http/Controllers/SocialFeedController';

/** Hash that opens the feed with the search box focused. */
export const SEARCH_HASH = '#buscar';

/** Feed address listing restaurants to browse, with the search box focused (the nav's search button). */
export function feedSearchUrl(): string {
    return (
        SocialFeedController.index.url({ query: { explorar: 1 } }) + SEARCH_HASH
    );
}
