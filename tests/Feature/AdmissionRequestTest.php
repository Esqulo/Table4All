<?php

namespace Tests\Feature;

use App\Enums\AdmissionRequestStatus;
use App\Mail\AdmissionRequestMail;
use App\Models\AdmissionRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdmissionRequestTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Bar do Zé',
            'contact_email' => 'ze@bardoze.com.br',
            'phone' => '(31) 99999-9999',
            'city' => 'Belo Horizonte',
            'tables' => '12',
            'message' => 'Abrimos de terça a domingo.',
            'website' => '',
        ], $overrides);
    }

    public function test_guest_can_send_a_request_which_is_stored_and_emailed(): void
    {
        Mail::fake();

        $this->post(route('contact.store'), $this->validPayload())
            ->assertRedirect(route('contact'))
            ->assertSessionHasNoErrors();

        $request = AdmissionRequest::sole();
        $this->assertSame('Bar do Zé', $request->business_name);
        $this->assertSame(12, $request->tables);
        $this->assertSame(AdmissionRequestStatus::PENDING, $request->status);

        Mail::assertQueued(AdmissionRequestMail::class, function (AdmissionRequestMail $mail) use ($request) {
            return $mail->hasTo(config('app.contact_email'))
                && $mail->hasReplyTo('ze@bardoze.com.br')
                && $mail->admissionRequest->is($request);
        });
    }

    public function test_only_name_and_email_are_required(): void
    {
        Mail::fake();

        $this->post(route('contact.store'), [
            'business_name' => 'Cantina da Nona',
            'contact_email' => 'nona@cantina.com.br',
        ])->assertSessionHasNoErrors();

        $request = AdmissionRequest::sole();
        $this->assertNull($request->phone);
        $this->assertNull($request->tables);
    }

    public function test_request_is_validated(): void
    {
        Mail::fake();

        $this->post(route('contact.store'), $this->validPayload([
            'business_name' => '',
            'contact_email' => 'not-an-email',
            'phone' => '31999999999',
            'tables' => '0',
        ]))->assertSessionHasErrors(['business_name', 'contact_email', 'phone', 'tables']);

        $this->assertDatabaseCount('admission_requests', 0);
        Mail::assertNothingQueued();
    }

    public function test_honeypot_submissions_are_silently_dropped(): void
    {
        Mail::fake();

        $this->post(route('contact.store'), $this->validPayload(['website' => 'https://spam.example']))
            ->assertRedirect(route('contact'));

        $this->assertDatabaseCount('admission_requests', 0);
        Mail::assertNothingQueued();
    }

    public function test_admin_sees_requests_filtered_by_status(): void
    {
        $admin = User::factory()->admin()->create();
        AdmissionRequest::factory()->count(2)->create();
        AdmissionRequest::factory()->create(['status' => AdmissionRequestStatus::ARCHIVED]);

        $this->actingAs($admin)
            ->get(route('admin.admission-requests.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/admission-requests')
                ->where('filter', 'pending')
                ->has('requests', 2)
                ->where('counts.pending', 2)
                ->where('counts.archived', 1)
            );

        $this->actingAs($admin)
            ->get(route('admin.admission-requests.index', ['status' => 'all']))
            ->assertInertia(fn (Assert $page) => $page->where('filter', 'all')->has('requests', 3));
    }

    public function test_only_admins_can_access_the_panel(): void
    {
        $request = AdmissionRequest::factory()->create();

        $this->get(route('admin.admission-requests.index'))->assertRedirect(route('admin.login'));

        foreach (['restaurant', 'waiter', 'customer'] as $type) {
            $user = User::factory()->create(['account_type' => $type]);

            $this->actingAs($user)->get(route('admin.admission-requests.index'))->assertForbidden();
            $this->actingAs($user)
                ->patch(route('admin.admission-requests.update', $request), ['status' => 'archived'])
                ->assertForbidden();
        }

        $this->assertSame(AdmissionRequestStatus::PENDING, $request->fresh()->status);
    }

    public function test_admin_can_change_the_status(): void
    {
        $admin = User::factory()->admin()->create();
        $request = AdmissionRequest::factory()->create();

        $this->actingAs($admin)
            ->patch(route('admin.admission-requests.update', $request), ['status' => 'contacted'])
            ->assertRedirect();

        $this->assertSame(AdmissionRequestStatus::CONTACTED, $request->fresh()->status);

        $this->actingAs($admin)
            ->patch(route('admin.admission-requests.update', $request), ['status' => 'deleted'])
            ->assertSessionHasErrors('status');
    }
}
