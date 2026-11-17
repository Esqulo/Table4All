import SocialFeedController from '@/actions/App/Http/Controllers/SocialFeedController';

/** Hash that opens the feed with the search box focused. */
export const SEARCH_HASH = '#buscar';

/** Feed address with the search box focused (from the profile's search button). */
export function feedSearchUrl(): string {
    return SocialFeedController.index.url() + SEARCH_HASH;
}
