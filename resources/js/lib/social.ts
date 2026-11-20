import DiscoverController from '@/actions/App/Http/Controllers/DiscoverController';

/** Hash that opens the discovery page with the search box focused. */
export const SEARCH_HASH = '#buscar';

/** Discovery page with the search box focused (the nav's search button). */
export function discoverSearchUrl(): string {
    return DiscoverController.index.url() + SEARCH_HASH;
}
