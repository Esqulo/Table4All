<?php

namespace Tests\Feature\Restaurant;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\RestaurantTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/** Short messages and floating emoji reactions among the customers at a table. */
class TableChatTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private RestaurantTable $table;

    private User $owner;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->table = $this->openTable($this->restaurant());
        $this->owner = $this->customer();
        $this->member = $this->customer();

        $this->actingAs($this->owner)->get($this->tableUrl())->assertOk();
        $this->actingAs($this->member)->get($this->tableUrl())->assertOk();
        $this->actingAs($this->owner)->post(route('customer.join-requests.approve', [$this->code(), $this->member->id]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function code(): string
    {
        return (string) $this->table->access_code;
    }

    private function tableUrl(): string
    {
        return route('customer.table', $this->code());
    }

    /** What the chat's live refresh asks for: only the chat prop. */
    private function pollChat(User $user): TestResponse
    {
        return $this->actingAs($user)->get($this->tableUrl(), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) (new HandleInertiaRequests)->version(request()),
            'X-Inertia-Partial-Component' => 'customer/show',
            'X-Inertia-Partial-Data' => 'chat',
        ]);
    }

    public function test_members_message_each_other(): void
    {
        $this->actingAs($this->member)
            ->post(route('customer.chat.send', $this->code()), ['body' => 'Alguém pede mais uma?'])
            ->assertRedirect($this->tableUrl());

        $this->actingAs($this->owner)->get($this->tableUrl())
            ->assertInertia(fn (Assert $page) => $page
                ->has('chat.messages', 1)
                ->where('chat.messages.0.body', 'Alguém pede mais uma?')
                ->where('chat.messages.0.name', $this->member->name)
                ->where('chat.messages.0.mine', false)
                ->where('chatMaxLength', 140));
        $this->actingAs($this->member)->get($this->tableUrl())
            ->assertInertia(fn (Assert $page) => $page->where('chat.messages.0.mine', true));
    }

    public function test_the_chat_refresh_carries_only_the_chat(): void
    {
        $this->actingAs($this->member)->post(route('customer.chat.send', $this->code()), ['body' => 'Oi']);

        $props = $this->pollChat($this->owner)->assertOk()->json('props');

        $this->assertSame('Oi', $props['chat']['messages'][0]['body']);
        foreach (['menu', 'products', 'preparing', 'total', 'members'] as $heavy) {
            $this->assertArrayNotHasKey($heavy, $props);
        }
    }

    public function test_messages_are_short_and_not_blank(): void
    {
        foreach (['', '   ', str_repeat('a', 141)] as $body) {
            $this->actingAs($this->member)
                ->post(route('customer.chat.send', $this->code()), ['body' => $body])
                ->assertSessionHasErrors('body');
        }

        $this->actingAs($this->member)
            ->post(route('customer.chat.send', $this->code()), ['body' => str_repeat('a', 140)])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('table_messages', 1);
    }

    public function test_only_customers_at_the_table_read_and_write_the_chat(): void
    {
        $this->actingAs($this->member)->post(route('customer.chat.send', $this->code()), ['body' => 'Só nós']);
        $waiting = $this->customer();
        $this->actingAs($waiting)->get($this->tableUrl())
            ->assertInertia(fn (Assert $page) => $page
                ->component('customer/waiting')
                ->missing('chat'));

        foreach ([$waiting, $this->customer()] as $outsider) {
            $this->actingAs($outsider)
                ->post(route('customer.chat.send', $this->code()), ['body' => 'Oi'])
                ->assertForbidden();
            $this->actingAs($outsider)
                ->post(route('customer.chat.react', $this->code()), ['emoji' => '🔥'])
                ->assertForbidden();
        }

        $restaurant = User::findOrFail($this->table->user_id);
        $this->actingAs($restaurant)
            ->post(route('customer.chat.send', $this->code()), ['body' => 'Oi'])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseCount('table_messages', 1);
        $this->assertDatabaseCount('table_reactions', 0);
    }

    public function test_someone_who_left_can_no_longer_write(): void
    {
        $this->actingAs($this->member)->post(route('customer.leave', $this->code()));

        $this->actingAs($this->member)
            ->post(route('customer.chat.send', $this->code()), ['body' => 'Oi'])
            ->assertForbidden();
    }

    public function test_a_closed_table_has_no_chat(): void
    {
        $code = $this->code();
        $this->table->forceFill(['closed_at' => now()])->save();

        $this->actingAs($this->member)->post(route('customer.chat.send', $code), ['body' => 'Oi'])->assertNotFound();
        $this->actingAs($this->member)->post(route('customer.chat.react', $code), ['emoji' => '🔥'])->assertNotFound();
    }

    public function test_each_table_sees_only_its_own_chat(): void
    {
        $other = $this->openTable($this->restaurant());
        $stranger = $this->customer();
        $this->actingAs($stranger)->get(route('customer.table', $other->access_code))->assertOk();
        $this->actingAs($stranger)->post(route('customer.chat.send', $other->access_code), ['body' => 'Outra mesa']);
        $this->actingAs($stranger)->post(route('customer.chat.react', $other->access_code), ['emoji' => '🎉']);

        $this->actingAs($this->owner)->get($this->tableUrl())
            ->assertInertia(fn (Assert $page) => $page
                ->where('chat.messages', [])
                ->where('chat.reactions', []));
    }

    public function test_the_screen_shows_the_latest_messages_oldest_first(): void
    {
        $rows = [];
        for ($i = 1; $i <= 55; $i++) {
            $rows[] = ['restaurant_table_id' => $this->table->id, 'user_id' => $this->member->id, 'body' => "m{$i}", 'created_at' => now()];
        }
        DB::table('table_messages')->insert($rows);

        $this->actingAs($this->owner)->get($this->tableUrl())
            ->assertInertia(fn (Assert $page) => $page
                ->has('chat.messages', 50)
                ->where('chat.messages.0.body', 'm6')
                ->where('chat.messages.49.body', 'm55'));
    }

    public function test_reactions_reach_everyone_at_the_table_for_a_few_seconds(): void
    {
        Carbon::setTestNow(now()->startOfSecond());

        $this->actingAs($this->member)
            ->post(route('customer.chat.react', $this->code()), ['emoji' => '🍻'])
            ->assertRedirect($this->tableUrl());

        $this->assertSame([['emoji' => '🍻', 'mine' => false]], array_map(
            fn ($r) => ['emoji' => $r['emoji'], 'mine' => $r['mine']],
            $this->pollChat($this->owner)->json('props.chat.reactions'),
        ));

        Carbon::setTestNow(now()->addSeconds(11));

        $this->assertSame([], $this->pollChat($this->owner)->json('props.chat.reactions'));
    }

    public function test_only_the_offered_emoji_can_be_sent(): void
    {
        foreach (['', 'oi', '🍕', '🔥🔥'] as $emoji) {
            $this->actingAs($this->member)
                ->post(route('customer.chat.react', $this->code()), ['emoji' => $emoji])
                ->assertSessionHasErrors('emoji');
        }

        $this->actingAs($this->owner)->get($this->tableUrl())
            ->assertInertia(fn (Assert $page) => $page->where('chatReactions', ['🍻', '😂', '❤️', '🔥', '👏', '🎉']));
        $this->assertDatabaseCount('table_reactions', 0);
    }

    public function test_old_reactions_are_pruned(): void
    {
        $this->actingAs($this->member)->post(route('customer.chat.react', $this->code()), ['emoji' => '🔥']);

        Carbon::setTestNow(now()->addMinutes(2));
        $this->actingAs($this->member)->post(route('customer.chat.react', $this->code()), ['emoji' => '👏']);

        $this->assertSame(['👏'], DB::table('table_reactions')->pluck('emoji')->all());
    }
}
