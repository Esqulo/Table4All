<?php

/*
 * Who answers for the personal data (LGPD Art. 9 and 41) and how long it is kept (Art. 15/16).
 * The privacy policy and terms of use pages read these values, so fill the LEGAL_* variables
 * before going live. See docs/privacy.md.
 */

return [

    // Bump when the privacy policy or the terms change in substance: new sign-ups record it.
    'version' => '2026-11-22',
    'updated_at' => '2026-11-22',

    // The controller: the company that runs Table4All.
    'company_name' => env('LEGAL_COMPANY_NAME') ?: 'Table4All',
    'company_document' => env('LEGAL_COMPANY_DOCUMENT') ?: null, // CNPJ
    'company_address' => env('LEGAL_COMPANY_ADDRESS') ?: null,

    // The encarregado (DPO): the channel for data subjects and the ANPD.
    'dpo_name' => env('LEGAL_DPO_NAME') ?: null,
    'dpo_email' => env('LEGAL_DPO_EMAIL') ?: env('CONTACT_EMAIL', 'contato@table4all.com.br'),

    // Minimum age to have an account (Art. 14: younger people need a guardian's consent).
    'minimum_age' => 18,

    // Retention, in days, enforced by `privacy:prune` (scheduled daily).
    'retention' => [
        // Chat messages and reactions of a table, counted from when it closed.
        'table_chat_days' => (int) env('RETENTION_TABLE_CHAT_DAYS', 30),
        // Waiter / bill calls, counted from when they were resolved.
        'table_calls_days' => (int) env('RETENTION_TABLE_CALLS_DAYS', 90),
        // Used or expired QR sign-in links.
        'link_tokens_days' => 7,
        // Accepted or expired staff and admin invitations.
        'invitations_days' => 30,
        // Answered admission requests (contact form), counted from the last change.
        'admission_requests_days' => (int) env('RETENTION_ADMISSION_REQUESTS_DAYS', 365),
        // Failed queue jobs (their payloads may carry personal data).
        'failed_jobs_days' => 30,
    ],

];
