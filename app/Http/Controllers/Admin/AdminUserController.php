<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Mail\AdminInvitationMail;
use App\Models\AdminInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** The platform's admins, and inviting a new one (the only way in besides the server command). */
class AdminUserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/admins', [
            'admins' => User::where('account_type', AccountType::ADMIN->value)
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'two_factor_confirmed_at', 'last_login_at', 'created_at'])
                ->map(fn (User $admin) => [
                    'id' => $admin->id,
                    'name' => $admin->name,
                    'email' => $admin->email,
                    'two_factor' => $admin->two_factor_confirmed_at !== null,
                    'last_login_at' => $admin->last_login_at?->toIso8601String(),
                    'created_at' => $admin->created_at?->toIso8601String(),
                ]),
            'invitations' => AdminInvitation::pending()
                ->with('invitedBy:id,name')
                ->latest()
                ->get()
                ->map(fn (AdminInvitation $invitation) => [
                    'id' => $invitation->id,
                    'email' => $invitation->email,
                    'invited_by' => $invitation->invitedBy?->name,
                    'expires_at' => $invitation->expires_at->toIso8601String(),
                ]),
            'validHours' => AdminInvitation::VALID_HOURS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class, 'email')],
        ], [
            'email.unique' => __('admin.error_admin_email_taken'),
        ]);

        // A new invitation replaces any earlier one to the same address.
        AdminInvitation::pending()->where('email', $data['email'])->delete();
        [$invitation, $token] = AdminInvitation::issue($data['email'], $request->user());

        Mail::to($invitation->email)->send(new AdminInvitationMail($request->user(), $token));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'admin_admins.msg_invited']);

        return back();
    }

    public function destroyInvitation(AdminInvitation $invitation): RedirectResponse
    {
        abort_if($invitation->accepted_at !== null, 404);

        $invitation->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'admin_admins.msg_revoked']);

        return back();
    }
}
