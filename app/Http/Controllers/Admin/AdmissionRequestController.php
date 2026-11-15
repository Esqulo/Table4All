<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdmissionRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\AdmissionRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AdmissionRequestController extends Controller
{
    public function index(Request $request): Response
    {
        $status = AdmissionRequestStatus::tryFrom((string) $request->query('status')) ?? AdmissionRequestStatus::PENDING;
        $showAll = $request->query('status') === 'all';

        $counts = AdmissionRequest::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return Inertia::render('admin/admission-requests', [
            'requests' => AdmissionRequest::query()
                ->when(! $showAll, fn ($query) => $query->where('status', $status))
                ->latest()
                ->get(),
            'filter' => $showAll ? 'all' : $status->value,
            'counts' => [
                'pending' => (int) ($counts['pending'] ?? 0),
                'contacted' => (int) ($counts['contacted'] ?? 0),
                'archived' => (int) ($counts['archived'] ?? 0),
            ],
        ]);
    }

    public function update(Request $request, AdmissionRequest $admissionRequest): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(AdmissionRequestStatus::class)],
        ]);

        $admissionRequest->update($validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'admission_requests.msg_updated']);

        return back();
    }
}
