<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/** The privacy policy and terms of use (public). Their text lives in the frontend locales. */
class LegalController extends Controller
{
    public function privacy(): Response
    {
        return Inertia::render('legal/privacy', ['legal' => $this->details()]);
    }

    public function terms(): Response
    {
        return Inertia::render('legal/terms', ['legal' => $this->details()]);
    }

    /**
     * @return array{company_name: string, company_document: ?string, company_address: ?string, dpo_name: ?string, dpo_email: string, minimum_age: int, updated_at: string, retention: array<string, int>}
     */
    private function details(): array
    {
        return [
            'company_name' => (string) config('legal.company_name'),
            'company_document' => config('legal.company_document'),
            'company_address' => config('legal.company_address'),
            'dpo_name' => config('legal.dpo_name'),
            'dpo_email' => (string) config('legal.dpo_email'),
            'minimum_age' => (int) config('legal.minimum_age'),
            'updated_at' => (string) config('legal.updated_at'),
            'retention' => array_map('intval', (array) config('legal.retention')),
        ];
    }
}
