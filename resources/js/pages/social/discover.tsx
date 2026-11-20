import { Head } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import DiscoverController from '@/actions/App/Http/Controllers/DiscoverController';
import SocialFeedController from '@/actions/App/Http/Controllers/SocialFeedController';
import Heading from '@/components/heading';
import { DiscoverBrowser } from '@/components/social/discover';
import type { DiscoverOrigin } from '@/components/social/discover';
import type { DiscoverFilters, DiscoverRestaurant } from '@/types';

type Props = {
    /** One page of the grid; InfiniteScroll merges the next ones in. */
    discover: { data: DiscoverRestaurant[] };
    /** The search, filters and order the server read from the address. */
    filters: DiscoverFilters;
    /** Where "closest first" measured from (null: unknown, or another order). */
    origin: DiscoverOrigin;
};

/** Finding restaurants: search, filters (how busy, price, open now) and sort orders. */
export default function Discover({ discover, filters, origin }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('social.discover_title')} />

            <div className="mx-auto w-full max-w-5xl space-y-5 p-4 sm:p-6">
                <Heading
                    title={t('social.discover_title')}
                    description={t('social.discover_description')}
                />

                <DiscoverBrowser
                    filters={filters}
                    origin={origin}
                    restaurants={discover.data}
                />
            </div>
        </>
    );
}

Discover.layout = {
    breadcrumbs: [
        {
            title: 'social.feed_title',
            href: SocialFeedController.index.url(),
        },
        {
            title: 'social.discover_title',
            href: DiscoverController.index.url(),
        },
    ],
};
