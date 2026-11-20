<?php

namespace Tests\Feature\Restaurant;

use App\Models\Category;
use App\Models\RestaurantTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/** The owner's dashboard: periods, filters, comparisons and the insights behind the charts. */
class DashboardTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // 15:00 in Brasilia, Friday 2026-11-20.
        Carbon::setTestNow(Carbon::parse('2026-11-20 18:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function closedAt(RestaurantTable $table, string $utc, ?string $openedUtc = null): RestaurantTable
    {
        $table->forceFill([
            'closed_at' => Carbon::parse($utc, 'UTC'),
            'created_at' => Carbon::parse($openedUtc ?? $utc, 'UTC'),
        ])->save();

        return $table;
    }

    /** @param  array<string, mixed>  $query */
    private function dashboard(array $query = []): TestResponse
    {
        return $this->get(route('restaurant.dashboards.index', $query));
    }

    // ── periods ──────────────────────────────────────────────────────────────

    public function test_today_counts_only_todays_local_sales_hour_by_hour(): void
    {
        $restaurant = $this->restaurant();
        $beer = $this->product($restaurant);
        // 02:00 UTC today is 23:00 yesterday in Brasilia: not today.
        $this->billLine($this->closedAt($this->closedTable($restaurant), '2026-11-20 02:00:00'), $beer, 1, 10);
        // 16:30 UTC is 13:30 local.
        $this->billLine($this->closedAt($this->closedTable($restaurant), '2026-11-20 16:30:00'), $beer, 2, 10);

        $this->actingAs($restaurant);
        $this->dashboard(['period' => 'today'])
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.period', 'today')
                ->where('filters.from', '2026-11-20')
                ->where('filters.to', '2026-11-20')
                ->where('filters.granularity', 'hour')
                ->where('summary.total_revenue', 20)
                ->has('timeline', 24)
                ->where('timeline.13.bucket', '13')
                ->where('timeline.13.revenue', 20)
                // Yesterday's 23:00 is the previous period's same hour.
                ->where('timeline.23.previous_revenue', 10)
                ->where('previous_summary.total_revenue', 10)
                ->has('heatmap', 0)
            );
    }

    public function test_presets_cover_whole_local_days_ending_today(): void
    {
        $this->actingAs($this->restaurant());

        $this->dashboard(['period' => 7])
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.from', '2026-11-14')
                ->where('filters.to', '2026-11-20')
                ->where('filters.previous_from', '2026-11-07')
                ->where('filters.previous_to', '2026-11-13')
                ->where('filters.granularity', 'day')
                ->has('timeline', 7)
                ->where('timeline.0.bucket', '2026-11-14')
            );

        $this->dashboard(['period' => 90])
            ->assertInertia(fn (Assert $page) => $page->has('timeline', 90));
    }

    public function test_custom_range_is_used_as_given(): void
    {
        $restaurant = $this->restaurant();
        $beer = $this->product($restaurant);
        $this->billLine($this->closedAt($this->closedTable($restaurant), '2026-10-05 20:00:00'), $beer, 1, 15);
        $this->billLine($this->closedAt($this->closedTable($restaurant), '2026-10-11 20:00:00'), $beer, 1, 99); // after

        $this->actingAs($restaurant);
        $this->dashboard(['period' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-10'])
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.period', 'custom')
                ->where('filters.from', '2026-10-01')
                ->where('filters.to', '2026-10-10')
                ->where('filters.previous_from', '2026-09-21')
                ->has('timeline', 10)
                ->where('summary.total_revenue', 15)
            );
    }

    public function test_a_single_custom_day_is_charted_by_hour(): void
    {
        $this->actingAs($this->restaurant());
        $this->dashboard(['period' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-01'])
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.granularity', 'hour')
                ->has('timeline', 24)
            );
    }

    public function test_custom_range_is_capped_at_ninety_days_and_today(): void
    {
        $this->actingAs($this->restaurant());

        $this->dashboard(['period' => 'custom', 'from' => '2026-01-01', 'to' => '2026-10-31'])
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.from', '2026-08-03')
                ->where('filters.to', '2026-10-31')
                ->has('timeline', 90)
            );

        $this->dashboard(['period' => 'custom', 'from' => '2026-11-18', 'to' => '2026-12-31'])
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.from', '2026-11-18')
                ->where('filters.to', '2026-11-20')
            );
    }

    public function test_invalid_custom_ranges_fall_back_to_thirty_days(): void
    {
        $this->actingAs($this->restaurant());

        foreach ([
            ['from' => '2026-10-10', 'to' => '2026-10-01'],   // reversed
            ['from' => '2026-02-31', 'to' => '2026-03-05'],   // no such day
            ['from' => '2026-12-01', 'to' => '2026-12-05'],   // entirely in the future
            ['from' => 'yesterday', 'to' => '2026-11-20'],
            ['from' => ['2026-10-01'], 'to' => '2026-11-20'],
            [],
        ] as $query) {
            $this->dashboard(['period' => 'custom', ...$query])
                ->assertInertia(fn (Assert $page) => $page
                    ->where('filters.period', '30')
                    ->where('filters.from', '2026-10-22')
                );
        }
    }

    // ── comparisons and insights ─────────────────────────────────────────────

    public function test_previous_period_is_reported_for_comparison(): void
    {
        $restaurant = $this->restaurant();
        $beer = $this->product($restaurant);
        $this->billLine($this->closedAt($this->closedTable($restaurant), '2026-11-19 20:00:00'), $beer, 2, 10);
        $this->billLine($this->closedAt($this->closedTable($restaurant), '2026-11-10 20:00:00'), $beer, 1, 50);
        $this->billLine($this->closedAt($this->closedTable($restaurant), '2026-11-01 20:00:00'), $beer, 1, 70); // before both

        $this->actingAs($restaurant);
        $this->dashboard(['period' => 7])
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.total_revenue', 20)
                ->where('previous_summary.total_revenue', 50)
                ->where('previous_summary.tables_closed', 1)
                // 2026-11-10 is the 4th day of the previous week (11-07 … 11-13), aligned with 11-17.
                ->where('timeline.3.bucket', '2026-11-17')
                ->where('timeline.3.previous_revenue', 50)
            );
    }

    public function test_summary_reports_duration_items_per_table_and_qr_share(): void
    {
        $restaurant = $this->restaurant();
        $beer = $this->product($restaurant);
        $customer = $this->customer();

        $quick = $this->closedAt($this->closedTable($restaurant), '2026-11-20 16:00:00', '2026-11-20 15:30:00');
        $this->billLine($quick, $beer, 2, 10, $customer->id);  // by QR
        $this->billLine($quick, $this->product($restaurant, ['name' => 'Fritas']), 1, 30); // by staff
        $slow = $this->closedAt($this->closedTable($restaurant), '2026-11-20 17:00:00', '2026-11-20 15:30:00');
        $this->billLine($slow, $beer, 3, 10);

        $this->actingAs($restaurant);
        $this->dashboard(['period' => 'today'])
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.total_revenue', 80)
                ->where('summary.tables_closed', 2)
                ->where('summary.items_sold', 6)
                ->where('summary.avg_items_per_table', 3)
                ->where('summary.avg_duration_minutes', 60)   // (30 + 90) / 2
                ->where('summary.qr_revenue', 20)
            );
    }

    public function test_heatmap_buckets_orders_by_local_weekday_and_hour(): void
    {
        $restaurant = $this->restaurant();
        $burger = $this->product($restaurant, ['price' => 30]);
        $table = $this->closedAt($this->closedTable($restaurant), '2026-11-19 02:00:00');
        // 01:00 UTC on Thursday the 19th is 22:00 on Wednesday (ISO 3) in Brasilia.
        $item = $this->queueItem($table, $burger, ['quantity' => 2]);
        $item->forceFill(['created_at' => Carbon::parse('2026-11-19 01:00:00', 'UTC')])->save();

        $this->actingAs($restaurant);
        $this->dashboard(['period' => 7])
            ->assertInertia(fn (Assert $page) => $page
                ->has('heatmap', 1)
                ->where('heatmap.0.weekday', 3)
                ->where('heatmap.0.hour', 22)
                ->where('heatmap.0.items', 2)
                ->where('heatmap.0.revenue', 60)
            );
    }

    public function test_top_products_are_ranked_by_revenue_and_by_quantity(): void
    {
        $restaurant = $this->restaurant();
        $table = $this->closedTable($restaurant);
        $this->billLine($table, $this->product($restaurant, ['name' => 'Chopp']), 10, 8);    // 80
        $this->billLine($table, $this->product($restaurant, ['name' => 'Picanha']), 1, 120); // 120

        $this->actingAs($restaurant);
        $this->dashboard()
            ->assertInertia(fn (Assert $page) => $page
                ->where('top_products.0.name', 'Picanha')
                ->where('top_products_by_qty.0.name', 'Chopp')
                ->where('top_products_by_qty.0.qty', 10)
            );
    }

    public function test_revenue_is_broken_down_by_category(): void
    {
        $restaurant = $this->restaurant();
        $drinks = Category::create(['name' => 'Bebidas']);
        $table = $this->closedTable($restaurant);
        $this->billLine($table, $this->product($restaurant, ['category_id' => $drinks->id]), 2, 10);
        $this->billLine($table, $this->product($restaurant, ['name' => 'Sem categoria']), 1, 5);

        $this->actingAs($restaurant);
        $this->dashboard()
            ->assertInertia(fn (Assert $page) => $page
                ->has('revenue_by_category', 2)
                ->where('revenue_by_category.0.name', 'Bebidas')
                ->where('revenue_by_category.0.revenue', 20)
                ->where('revenue_by_category.1.name', null)
                ->has('categories', 1)
                ->where('categories.0.name', 'Bebidas')
            );
    }

    public function test_category_filter_narrows_product_figures_but_not_payments(): void
    {
        $restaurant = $this->restaurant();
        $drinks = Category::create(['name' => 'Bebidas']);
        $food = Category::create(['name' => 'Pratos']);
        $beer = $this->product($restaurant, ['name' => 'Chopp', 'category_id' => $drinks->id]);
        $steak = $this->product($restaurant, ['name' => 'Picanha', 'category_id' => $food->id]);

        $both = $this->closedTable($restaurant);
        $this->billLine($both, $beer, 2, 10);
        $this->billLine($both, $steak, 1, 100);
        $both->payments()->create(['method' => 'pix', 'amount' => 120]);
        $foodOnly = $this->closedTable($restaurant);
        $this->billLine($foodOnly, $steak, 1, 100);

        $this->actingAs($restaurant);
        $this->dashboard(['category' => $drinks->id])
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.category', $drinks->id)
                ->where('summary.total_revenue', 20)
                ->where('summary.tables_closed', 1)
                ->where('summary.items_sold', 2)
                ->has('top_products', 1)
                ->where('top_products.0.name', 'Chopp')
                ->has('revenue_by_category', 0)
                ->where('payment_methods.0.total', 120)
            );
    }

    public function test_category_filter_ignores_categories_the_restaurant_does_not_sell(): void
    {
        $restaurant = $this->restaurant();
        $this->billLine($this->closedTable($restaurant), $this->product($restaurant), 1, 10);
        $unused = Category::create(['name' => 'Sobremesas']);
        $other = $this->restaurant();
        $this->product($other, ['category_id' => $unused->id]);

        $this->actingAs($restaurant);
        $this->dashboard(['category' => $unused->id])
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.category', null)
                ->where('summary.total_revenue', 10)
                ->has('categories', 0)
            );
    }

    public function test_payments_follow_the_tables_closed_in_the_period(): void
    {
        $restaurant = $this->restaurant();
        // Paid last month, closed today: counts today, with its table.
        $table = $this->closedTable($restaurant);
        $payment = $table->payments()->create(['method' => 'cash', 'amount' => 40]);
        $payment->forceFill(['created_at' => now()->subDays(40)])->save();

        $this->actingAs($restaurant);
        $this->dashboard(['period' => 'today'])
            ->assertInertia(fn (Assert $page) => $page
                ->has('payment_methods', 1)
                ->where('payment_methods.0.method', 'cash')
            );
    }

    public function test_recent_tables_carry_utc_timestamps(): void
    {
        $restaurant = $this->restaurant();
        $this->closedAt($this->closedTable($restaurant), '2026-11-20 16:00:00', '2026-11-20 15:00:00');

        $this->actingAs($restaurant);
        $this->dashboard(['period' => 'today'])
            ->assertInertia(fn (Assert $page) => $page
                ->where('recent_tables.0.closed_at', '2026-11-20T16:00:00Z')
                ->where('recent_tables.0.created_at', '2026-11-20T15:00:00Z')
            );
    }

    public function test_waiters_are_sent_away_from_the_dashboard(): void
    {
        $this->actingAs($this->waiterOf($this->restaurant()));
        $this->dashboard()->assertRedirect(route('dashboard'));
    }
}
