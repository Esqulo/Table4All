<?php

namespace App\Http\Controllers;

use App\Support\DiscoverFilters;
use App\Support\Geo\IpLocator;
use App\Support\RestaurantDirectory;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The discovery page (/descobrir): public restaurants in a grid, with a search, filters
 * (how full, price, open now) and sort orders (closest, best rated). See DiscoverFilters
 * for the address it reads them from. Changing a filter reloads only `discover`,
 * `filters` and `origin`, and starts the list over.
 */
class DiscoverController extends Controller
{
    /** Restaurants per page of the grid (infinite scroll): fills rows of 2, 3 and 4. */
    private const PER_PAGE = 24;

    public function index(Request $request, IpLocator $ipLocator): Response
    {
        $filters = DiscoverFilters::fromRequest($request);
        $origin = null;

        if ($filters->sort === DiscoverFilters::SORT_DISTANCE) {
            // The device's position when the browser shared it, otherwise roughly from the IP.
            $origin = $filters->origin !== null ? 'device' : null;

            if ($origin === null && ($found = $ipLocator->locate($request)) !== null) {
                $filters = $filters->withOrigin($found);
                $origin = 'ip';
            }
        }

        return Inertia::render('social/discover', [
            'discover' => Inertia::scroll(fn () => RestaurantDirectory::discover($filters, self::PER_PAGE, 'pagina')),
            'filters' => $filters->toArray(),
            // Where "closest first" measured from: 'device', 'ip', or null when it couldn't tell
            // (then the list keeps the default order and the page says so).
            'origin' => $origin,
        ]);
    }
}
