# Privacy and LGPD

How Table4All meets Brazil's General Data Protection Law (Lei 13.709/2018, LGPD) in code and in
operations. The public texts are `/privacidade` and `/termos` (`resources/js/pages/legal/*`, text
in `legal.*` of the frontend locales, details from `config/legal.php`). This file is for the team.
It is not legal advice: have a lawyer review the texts before launch.

## Before going live

- [ ] Fill `LEGAL_COMPANY_NAME`, `LEGAL_COMPANY_DOCUMENT` (CNPJ), `LEGAL_COMPANY_ADDRESS`,
      `LEGAL_DPO_NAME` and `LEGAL_DPO_EMAIL`. The policy names them as controller and DPO (Art. 41).
- [ ] Serve over HTTPS with `APP_URL=https://…`. The session cookie becomes `Secure` on its own
      (`config/session.php`). Add HSTS where TLS terminates (proxy, Cloudflare or nginx).
- [ ] Keep web server access logs (IP, date, time, URL) for **6 months**, no less: Marco Civil da
      Internet, Art. 15. Rotate and delete them after that. Laravel's own logs keep 14 days
      (`LOG_DAILY_DAYS`) and must not contain personal data beyond ids.
- [ ] Encrypt database backups, keep them in Brazil or with an Art. 33 guarantee, and expire them
      (e.g. 30 days). A restored backup brings back deleted accounts: re-run deletions after a restore.
- [ ] Sign data processing terms with the operators: hosting, email provider, Stripe, Mercado Pago. List any new
      one in the policy's "sharing" section.
- [ ] The scheduler must run (`schedule:run` every minute): `privacy:prune` and `auth:clear-resets`
      enforce retention.

## Data map

| Data | Where | Legal basis | Kept |
| --- | --- | --- | --- |
| Account (name, email, phone optional, avatar) | `users` | contract (Art. 7 V) | until the account is deleted |
| Consent to terms | `users.terms_accepted_at`, `terms_version` | proof of consent (Art. 8 §2) | with the account |
| Deactivation by an admin (date, admin, reason) | `users.deactivated_*` | legitimate interest, security and abuse prevention | while deactivated (cleared on reactivation) |
| Sessions, sign-in dates | `sessions`, `users.last_login_at/last_seen_at` | legitimate interest, security | session lifetime / account |
| Tables joined, orders, bill lines | `restaurant_table_customers`, `queue_items`, `restaurant_table_product` | contract; legal obligation for bills | 5 years (tax), unlinked on deletion |
| Payments | `table_payments`, `online_payments` | contract; legal obligation | 5 years, unlinked on deletion |
| Table chat, reactions | `table_messages`, `table_reactions` | contract | `RETENTION_TABLE_CHAT_DAYS` (30) after close |
| Waiter / bill calls | `table_calls` | contract | `RETENTION_TABLE_CALLS_DAYS` (90) after resolved |
| Comments, reactions, reviews | `post_*`, `restaurant_reviews` | contract (the person posts) | until deleted |
| Loyalty, personal coupons | `loyalty_rewards`, `coupons.customer_id` | contract | until the account is deleted |
| Contact form | `admission_requests` | pre-contract (Art. 7 V) | 365 days after answered |
| Invitations, QR sign-in links | `waiter_invitations`, `admin_invitations`, `customer_link_tokens` | contract | days after use/expiry |
| Device location | not stored (rounded ~100 m, in the discovery URL only) | consent in the browser | — |

Outside services: Stripe (payments, abroad: Art. 33), Mercado Pago (payments, for restaurants that
choose it; receives the payer's email; restaurants' OAuth tokens are stored encrypted), Nominatim (restaurant addresses only), the
email provider. The IP geolocation lookup (`IP_LOCATION_URL`) is **off** by default because it sends
visitors' IPs abroad; turning it on requires adding it to the policy. Fonts are bundled
(`@fontsource`), so no page calls Google Fonts.

## Data subject rights (Art. 18)

- **Access and portability**: Settings > Profile > "Baixar meus dados" (`profile.export`,
  `App\Support\Privacy\PersonalDataExport`). When a table gains a new kind of personal data, add it there.
- **Correction**: Settings > Profile.
- **Deletion**: customers and staff delete their own account. Rows that are financial records keep
  only a null user reference (`nullOnDelete`); personal content goes with `cascadeOnDelete`.
  Restaurants can't delete themselves (it would cascade through every bill): the team runs
  `php artisan restaurants:close {id}`, which anonymises the owner, deletes its public media and
  profile content and its staff accounts, and keeps tables, bills and payments.
- **Requests by email** (`LEGAL_DPO_EMAIL`): confirm the requester owns the account's email, answer
  within **15 days** (Art. 19 II) and keep a record of the request and the answer.

## Security incidents (Art. 48)

1. Contain: revoke credentials/keys, block the access path, preserve logs.
2. Assess within 24 h: what data, how many people, risk of relevant harm.
3. If there is risk or relevant harm, notify the **ANPD within 3 business days** (Resolução CD/ANPD
   nº 15/2024) through its form, and the affected people in clear language: what happened, which
   data, what we did, what they should do, the DPO contact.
4. Record every incident, notified or not, with facts, decisions and measures (kept for 5 years).

## Rules for new code

- Collect only what a feature needs (Art. 6 III); optional unless the feature can't work without it.
- Never show a person's email or phone to other customers or to staff; show the name.
- Public pictures go through `ImageOptimizer::storeReencoded()` (drops EXIF, including GPS).
- New personal data: add it to the export, to the policy's data list, and give it a retention
  period in `config/legal.php` + `privacy:prune` if it isn't needed forever.
- New outside service that receives personal data: policy "sharing" section, and a processing contract.
- Index every foreign key to `users` (deletion runs `ON DELETE` over each referencing table).
