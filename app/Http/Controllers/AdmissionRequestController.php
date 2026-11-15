<?php

namespace App\Http\Controllers;

use App\Mail\AdmissionRequestMail;
use App\Models\AdmissionRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;

/**
 * Public endpoint behind the "get a head start" form on the contact page.
 */
class AdmissionRequestController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        // Honeypot: real visitors never see or fill this field. Bots get a fake success.
        if ($request->filled('website')) {
            return to_route('contact');
        }

        $validated = $request->validate([
            'business_name' => ['required', 'string', 'max:120'],
            'contact_email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'regex:/^\(\d{2}\) \d{4,5}-\d{4}$/'],
            'city' => ['nullable', 'string', 'max:120'],
            'tables' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        $admissionRequest = AdmissionRequest::create($validated);

        Mail::to(config('app.contact_email'))->queue(new AdmissionRequestMail($admissionRequest));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'contact.form.sent_toast']);

        return to_route('contact');
    }
}
