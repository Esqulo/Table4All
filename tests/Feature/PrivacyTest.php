<?php

namespace Tests\Feature;

use App\Enums\AdmissionRequestStatus;
use App\Models\AdmissionRequest;
use App\Models\RestaurantPhoto;
use App\Models\RestaurantTable;
use App\Models\TablePayment;
use App\Models\User;
use App\Models\WaiterInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/** LGPD: transparency, consent, minimisation, data subject rights, retention. See docs/privacy.md. */
class PrivacyTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** @return array<string, string> */
    private function signUp(array $overrides = []): array
    {
        return [
            'name' => 'Cliente',
            'email' => 'cliente@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms' => '1',
            ...$overrides,
        ];
    }

    // ── policy and terms ─────────────────────────────────────────────────────

    public function test_privacy_policy_and_terms_are_public_and_name_the_controller(): void
    {
        config([
            'legal.company_name' => 'Table4All Tecnologia Ltda',
            'legal.company_document' => '00.000.000/0001-00',
            'legal.dpo_email' => 'privacidade@example.com',
        ]);

        foreach (['privacy' => 'legal/privacy', 'terms' => 'legal/terms'] as $route => $component) {
            $this->get(route($route))->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component($component)
                ->where('legal.company_name', 'Table4All Tecnologia Ltda')
                ->where('legal.company_document', '00.000.000/0001-00')
                ->where('legal.dpo_email', 'privacidade@example.com')
                ->where('legal.minimum_age', 18)
                ->where('legal.retention.table_chat_days', 30));
        }
    }

    // ── consent and minimisation at sign-up ──────────────────────────────────

    public function test_signing_up_requires_accepting_the_terms(): void
    {
        foreach ([[], ['terms' => '0']] as $without) {
            $data = $this->signUp($without);

            if ($without === []) {
                unset($data['terms']);
            }

            $this->post(route('register.store'), $data)->assertSessionHasErrors('terms');
        }

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_sign_up_records_which_terms_were_accepted_and_the_phone_is_optional(): void
    {
        $this->post(route('register.store'), $this->signUp())->assertSessionHasNoErrors();

        $user = User::where('email', 'cliente@example.com')->sole();
        $this->assertNull($user->phone);
        $this->assertNotNull($user->terms_accepted_at);
        $this->assertSame(config('legal.version'), $user->terms_version);
    }

    public function test_a_phone_given_at_sign_up_is_still_validated(): void
    {
        $this->post(route('register.store'), $this->signUp(['phone' => '123']))->assertSessionHasErrors('phone');
        $this->post(route('register.store'), $this->signUp(['phone' => '(11) 99999-9999']))->assertSessionHasNoErrors();

        $this->assertSame('+5511999999999', User::where('email', 'cliente@example.com')->value('phone'));
    }

    public function test_a_waiter_must_accept_the_terms_to_join(): void
    {
        $invitation = WaiterInvitation::create([
            'restaurant_id' => $this->restaurant()->id,
            'email' => 'novo@bar.test',
            'token' => Str::random(64),
        ]);

        $this->post(route('waiter.invite.accept', $invitation->token), [
            'name' => 'Novo', 'password' => 'segredo123', 'password_confirmation' => 'segredo123',
        ])->assertSessionHasErrors('terms');

        $this->assertFalse(User::where('email', 'novo@bar.test')->exists());
    }

    public function test_the_phone_can_be_removed_from_the_profile(): void
    {
        $user = User::factory()->create(['phone' => '+5511999999999']);

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name, 'email' => $user->email, 'phone' => '',
        ])->assertSessionHasNoErrors();

        $this->assertNull($user->fresh()->phone);
    }

    // ── public pictures ──────────────────────────────────────────────────────

    public function test_avatars_are_re_encoded_never_stored_as_sent(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $upload = UploadedFile::fake()->image('me.jpg', 1600, 1200);

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name, 'email' => $user->email, 'avatar' => $upload,
        ])->assertSessionHasNoErrors();

        $path = (string) $user->fresh()->avatar;
        Storage::disk('public')->assertExists($path);
        $this->assertStringStartsWith('avatars/', $path);
        // A new file (re-encoded and resized), not the upload's bytes under a new name.
        $this->assertNotSame(file_get_contents($upload->getRealPath()), Storage::disk('public')->get($path));
        [$width] = getimagesizefromstring((string) Storage::disk('public')->get($path));
        $this->assertLessThanOrEqual(512, $width);
    }

    // ── account deletion ─────────────────────────────────────────────────────

    public function test_a_restaurant_cannot_delete_its_own_account_and_keeps_its_bills(): void
    {
        $restaurant = $this->restaurant();
        $table = $this->closedTable($restaurant);
        TablePayment::forceCreate(['restaurant_table_id' => $table->id, 'method' => 'cash', 'amount' => 50]);

        $this->actingAs($restaurant)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasErrors('password');

        $this->assertNotNull($restaurant->fresh());
        $this->assertSame(1, TablePayment::where('restaurant_table_id', $table->id)->count());
    }

    public function test_a_customer_deleting_the_account_leaves_bills_without_their_name(): void
    {
        $restaurant = $this->restaurant();
        $customer = $this->customer();
        $table = $this->closedTable($restaurant);
        $this->billLine($table, $this->product($restaurant), 1, 10.0, $customer->id);
        DB::table('table_messages')->insert(['restaurant_table_id' => $table->id, 'user_id' => $customer->id, 'body' => 'Oi']);

        $this->actingAs($customer)->delete(route('profile.destroy'), ['password' => 'password'])->assertRedirect('/');

        $this->assertNull(User::find($customer->id));
        $this->assertSame(1, DB::table('restaurant_table_product')->where('restaurant_table_id', $table->id)->whereNull('ordered_by_user_id')->count());
        $this->assertSame(0, DB::table('table_messages')->where('user_id', $customer->id)->count());
    }

    // ── access and portability ───────────────────────────────────────────────

    public function test_people_download_their_own_data_and_nobody_elses(): void
    {
        $restaurant = $this->restaurant(['name' => 'Bar do Zé']);
        $customer = $this->customer();
        $other = $this->customer();
        $table = $this->openTable($restaurant);
        $product = $this->product($restaurant, ['name' => 'Chopp']);
        $this->queueItem($table, $product, ['ordered_by_user_id' => $customer->id, 'notes' => 'sem gelo']);
        $this->queueItem($table, $product, ['ordered_by_user_id' => $other->id, 'notes' => 'do outro']);
        DB::table('table_messages')->insert([
            ['restaurant_table_id' => $table->id, 'user_id' => $customer->id, 'body' => 'minha mensagem'],
            ['restaurant_table_id' => $table->id, 'user_id' => $other->id, 'body' => 'mensagem alheia'],
        ]);

        $response = $this->actingAs($customer)->get(route('profile.export'));

        $response->assertOk()->assertDownload();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $data = json_decode($response->streamedContent(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($customer->email, $data['account']['email']);
        $this->assertSame(['sem gelo'], array_column($data['items_ordered'], 'notes'));
        $this->assertSame('Bar do Zé', $data['items_ordered'][0]['restaurant']);
        $this->assertSame(['minha mensagem'], array_column($data['table_messages'], 'body'));
        $this->assertStringNotContainsString($other->email, $response->streamedContent());
        $this->assertStringNotContainsString('mensagem alheia', $response->streamedContent());
    }

    public function test_the_data_download_needs_a_signed_in_user(): void
    {
        $this->get(route('profile.export'))->assertRedirect(route('login'));
    }

    // ── what other people see ────────────────────────────────────────────────

    public function test_people_at_the_same_table_never_see_each_others_email(): void
    {
        $table = $this->openTable($this->restaurant());
        $owner = $this->customer();
        $member = $this->customer();
        $url = route('customer.table', $table->access_code);

        $this->actingAs($owner)->get($url)->assertOk();
        $this->actingAs($member)->get($url)->assertOk();
        $this->actingAs($owner)->post(route('customer.join-requests.approve', [$table->access_code, $member->id]));
        $this->actingAs($member)->post(route('customer.chat.send', $table->access_code), ['body' => 'Oi']);

        $response = $this->actingAs($owner)->get($url)->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('chat.messages.0.name', $member->name)
            ->etc());
        // Anywhere in the props the owner's screen receives, not only the chat.
        $this->assertStringNotContainsString($member->email, json_encode($response->viewData('page'), JSON_THROW_ON_ERROR));
    }

    // ── retention ────────────────────────────────────────────────────────────

    public function test_prune_deletes_personal_data_past_its_retention_and_keeps_the_rest(): void
    {
        $restaurant = $this->restaurant();
        $customer = $this->customer();
        $oldTable = $this->closedTable($restaurant);
        $oldTable->forceFill(['closed_at' => now()->subDays(31)])->save();
        $recentTable = $this->closedTable($restaurant);
        $openTable = $this->openTable($restaurant);

        foreach ([$oldTable, $recentTable, $openTable] as $table) {
            DB::table('table_messages')->insert(['restaurant_table_id' => $table->id, 'user_id' => $customer->id, 'body' => 'Oi']);
            DB::table('table_reactions')->insert(['restaurant_table_id' => $table->id, 'user_id' => $customer->id, 'emoji' => '🍺']);
        }

        $call = fn (RestaurantTable $table, ?\DateTimeInterface $resolvedAt) => DB::table('table_calls')->insertGetId([
            'user_id' => $restaurant->id, 'restaurant_table_id' => $table->id, 'type' => 'waiter',
            'resolved_at' => $resolvedAt, 'resolution' => $resolvedAt ? 'answered' : null,
            'created_at' => now()->subDays(100), 'updated_at' => now(),
        ]);
        $oldCall = $call($oldTable, now()->subDays(91));
        $recentCall = $call($recentTable, now()->subDays(5));
        $openCall = $call($openTable, null);

        $token = fn (int $daysAgo) => DB::table('customer_link_tokens')->insertGetId([
            'user_id' => $customer->id, 'token' => Str::random(64), 'expires_at' => now()->subDays($daysAgo),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $oldToken = $token(8);
        $liveToken = $token(-1);

        $admission = fn (AdmissionRequestStatus $status, int $daysAgo) => AdmissionRequest::forceCreate([
            'business_name' => 'Bar', 'contact_email' => 'bar@example.com', 'status' => $status,
            'created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo),
        ])->id;
        $oldAnswered = $admission(AdmissionRequestStatus::CONTACTED, 400);
        $oldPending = $admission(AdmissionRequestStatus::PENDING, 400);
        $recentAnswered = $admission(AdmissionRequestStatus::ARCHIVED, 10);

        $staleInvitation = WaiterInvitation::create(['restaurant_id' => $restaurant->id, 'email' => 'a@bar.test', 'token' => Str::random(64)]);
        $staleInvitation->forceFill(['created_at' => now()->subDays(40)])->save();
        $liveInvitation = WaiterInvitation::create(['restaurant_id' => $restaurant->id, 'email' => 'b@bar.test', 'token' => Str::random(64)]);

        $this->artisan('privacy:prune')->assertSuccessful();

        $this->assertSame([$recentTable->id, $openTable->id], DB::table('table_messages')->orderBy('restaurant_table_id')->pluck('restaurant_table_id')->all());
        $this->assertSame([$recentTable->id, $openTable->id], DB::table('table_reactions')->orderBy('restaurant_table_id')->pluck('restaurant_table_id')->all());
        $this->assertEqualsCanonicalizing([$recentCall, $openCall], DB::table('table_calls')->pluck('id')->all());
        $this->assertNotContains($oldCall, DB::table('table_calls')->pluck('id')->all());
        $this->assertSame([$liveToken], DB::table('customer_link_tokens')->pluck('id')->all());
        $this->assertNotContains($oldToken, DB::table('customer_link_tokens')->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$oldPending, $recentAnswered], AdmissionRequest::pluck('id')->all());
        $this->assertNotContains($oldAnswered, AdmissionRequest::pluck('id')->all());
        $this->assertSame([$liveInvitation->id], WaiterInvitation::pluck('id')->all());
        $this->assertNotNull($oldTable->fresh(), 'bills are financial records and stay');
    }

    // ── closing a restaurant ─────────────────────────────────────────────────

    public function test_closing_a_restaurant_removes_personal_data_and_keeps_financial_records(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('logos/l.webp', 'x');
        Storage::disk('public')->put('venue/p.webp', 'x');
        $restaurant = $this->restaurant([
            'name' => 'Bar do Zé', 'phone' => '+5511999990000', 'address' => 'Rua A, 1',
            'logo' => 'logos/l.webp', 'profile_public' => true,
        ]);
        RestaurantPhoto::create(['user_id' => $restaurant->id, 'path' => 'venue/p.webp', 'position' => 0]);
        $waiter = $this->waiterOf($restaurant);
        $other = $this->restaurant();
        $table = $this->closedTable($restaurant);
        $payment = TablePayment::forceCreate(['restaurant_table_id' => $table->id, 'method' => 'cash', 'amount' => 80, 'registered_by_id' => $waiter->id]);

        $this->artisan('restaurants:close', ['id' => $restaurant->id, '--force' => true])->assertSuccessful();

        $closed = $restaurant->fresh();
        $this->assertNotSame('Bar do Zé', $closed->name);
        $this->assertStringEndsWith('@closed.invalid', $closed->email);
        $this->assertNull($closed->phone);
        $this->assertNull($closed->address);
        $this->assertNull($closed->logo);
        $this->assertFalse($closed->profile_public);
        Storage::disk('public')->assertMissing('logos/l.webp');
        Storage::disk('public')->assertMissing('venue/p.webp');
        $this->assertSame(0, RestaurantPhoto::where('user_id', $restaurant->id)->count());
        $this->assertNull(User::find($waiter->id));

        // The records the law requires stay, without the staff member's name.
        $this->assertNotNull($table->fresh());
        $this->assertNull($payment->fresh()->registered_by_id);
        $this->assertNotNull($other->fresh(), 'other restaurants are untouched');
    }

    public function test_closing_refuses_anything_but_a_restaurant(): void
    {
        $customer = $this->customer();

        $this->artisan('restaurants:close', ['id' => $customer->id, '--force' => true])->assertFailed();
        $this->assertSame($customer->email, $customer->fresh()->email);
    }
}
