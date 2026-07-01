<?php

namespace App\Http\Controllers\Restaurant;

use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Mail\WaiterInvitationMail;
use App\Models\User;
use App\Models\WaiterInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class WaiterController extends Controller
{
    public function index(Request $request): Response
    {
        $restaurantId = $request->user()->id;

        $waiters = User::where('restaurant_id', $restaurantId)
            ->where('account_type', AccountType::WAITER)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'created_at']);

        $invitations = WaiterInvitation::where('restaurant_id', $restaurantId)
            ->pending()
            ->orderByDesc('created_at')
            ->get(['id', 'email', 'created_at']);

        return Inertia::render('restaurant/waiters', [
            'waiters'     => $waiters,
            'invitations' => $invitations,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $restaurantId = $request->user()->id;
        $email        = $validated['email'];

        if (User::where('email', $email)
                ->where('restaurant_id', $restaurantId)
                ->where('account_type', AccountType::WAITER)
                ->exists()) {
            return back()->withErrors(['email' => __('waiters.error_already_waiter')]);
        }

        // Remove any previous pending invite for this email at this restaurant
        WaiterInvitation::where('restaurant_id', $restaurantId)
            ->where('email', $email)
            ->whereNull('accepted_at')
            ->delete();

        $invitation = WaiterInvitation::create([
            'restaurant_id' => $restaurantId,
            'email'         => $email,
            'token'         => Str::random(64),
        ]);

        Mail::to($email)->send(new WaiterInvitationMail($invitation, $request->user()));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'waiters.msg_invited']);

        return to_route('restaurant.waiters.index');
    }

    public function cancelInvitation(Request $request, WaiterInvitation $invitation): RedirectResponse
    {
        abort_unless($invitation->restaurant_id === $request->user()->id, 403);

        $invitation->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'waiters.msg_invite_cancelled']);

        return to_route('restaurant.waiters.index');
    }

    public function destroy(Request $request, User $waiter): RedirectResponse
    {
        abort_unless($waiter->restaurant_id === $request->user()->id, 403);

        $waiter->update([
            'restaurant_id' => null,
            'account_type'  => AccountType::CUSTOMER,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'waiters.msg_deleted']);

        return to_route('restaurant.waiters.index');
    }
}
