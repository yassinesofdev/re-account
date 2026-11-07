# RE-Account

RE-Account is the central identity and account management service for the RE product platform. It's the single place a user creates an account, manages their profile, and manages the organizations they belong to. Every other RE product (RE Intel, and future products) delegates authentication to RE-Account instead of implementing its own — a user signs in once and can move between products, personally or in the context of an organization, without a separate login per product.

It covers identity, organizations, per-product plans and seats, per-product member access, and **Stripe billing for paid plans** (see [Billing](#billing)). Product-level roles, SSO/OAuth, and 2FA are out of scope for now.

## Stack

| | |
|---|---|
| Backend | Laravel 13, PostgreSQL — `account-backend/` |
| Frontend | Next.js 15 (App Router), TypeScript, Tailwind CSS — `account-frontend/` |
| Auth | Laravel Sanctum (session cookies) for the frontend; short-lived signed JWTs (15 min) for the product handoff flow |
| Email | Laravel Mail + Mailpit (dev SMTP catcher) |
| Ports | API `8338`, Web `3742`, Postgres `5433` (shared with `data-source`), Mailpit SMTP `1026` / UI `8026` |

`account-backend` and `account-frontend` are two independent applications living in this one folder — there is no shared build step between them.

Postgres and Mailpit are **not** started from this folder — they're part of the root `docker-compose.yml` (`re-root/docker-compose.yml`), shared with `data-source`. Account data lives in its own `re_account` database inside that same Postgres container (`re_data` is `data-source`'s database) — created automatically on first boot via `docker/postgres-init/01-create-re-account-db.sql`.

## Setup

### 1. Start Postgres + Mailpit (from the repo root, not this folder)

```bash
cd re-root
docker compose up -d
```

### 2. Backend (`account-backend/`)

```bash
cd account-backend
composer install
cp .env.example .env   # already pre-filled for local dev; adjust as needed
php artisan key:generate
php artisan migrate
php artisan db:seed
php artisan storage:link
php artisan serve --port=8338
```

The seeder creates:

- Super admin: `admin@re-account.com` / `secret`
- A test product: `re-intel` (redirects to `http://localhost:3741/auth/callback`, dev secret key `re-intel-dev-secret-key-change-me`), with product roles `manager` and `agent` (default `agent`)
- `re-data` ("Data Admin", redirects to `http://localhost:3743/auth/callback`, dev secret key `re-data-dev-secret-key-change-me`): **super admins only**, no plans or seats

Emails sent in development land in Mailpit's web UI at `http://localhost:8026`.

### 3. Frontend (`account-frontend/`)

```bash
cd account-frontend
npm install
cp .env.local.example .env.local   # already pre-filled for local dev
npm run dev -- --port 3742
```

Visit `http://localhost:3742` — you'll land on `/login`.

## How other products integrate

A product never implements its own login form. Instead it redirects the user to RE-Account and gets a verified identity back.

1. **Redirect the user to RE-Account.** From your product's frontend or backend, send the browser to:

   ```
   http://localhost:3742/auth?product=<your-product-slug>&redirect=<your-callback-url>
   ```

   `product` must match a `slug` registered in RE-Account's `products` table, and `redirect` must be a URL under that product's registered `redirect_url`. RE-Account rejects the request otherwise.

2. **The user signs in or registers**, in the context of your product (RE-Account shows your product's name on the page). If the user is already signed in to RE-Account, they instead see a lightweight "continue as..." confirmation with a context switcher (Personal, or one of their organizations).

3. **RE-Account redirects back to your `redirect` URL** with a short-lived (15 minute) signed JWT appended as `?token=...`.

4. **Your backend verifies the token** by calling RE-Account directly (server-to-server, not from the browser):

   ```
   POST http://localhost:8338/api/verify-token
   Headers: X-Product-Secret: <your product's secret key>
   Body: { "token": "<the token from the redirect>" }
   ```

   RE-Account checks the token's signature and expiry, confirms the product secret matches, and responds with the user's identity and org context:

   ```json
   {
     "user_id": "uuid",
     "name": "John Doe",
     "email": "john@example.com",
     "context": "org",
     "org_id": "uuid",
     "org_name": "Acme Real Estate",
     "org_role": "member",
     "is_super_admin": false,
     "product_role": "agent",
     "product": "re-intel",
     "plan": { "slug": "personal-pro", "name": "Pro", "seat_limit": null, "quantity": null, "price_cents": 1200, "billing_period": "month", "status": "active", "current_period_end": "ISO|null" },
     "org": { "id": "uuid", "name": "Acme Real Estate", "slug": "acme", "logo_url": "https://…|null", "brand_color": "#0F766E|null", "timezone": "Asia/Dubai", "orn": "12345|null" }
   }
   ```

   `plan` is the plan the subject (the org in org context, otherwise the user) is **entitled** to — subscription status `active`, `trialing` or `past_due` (grace while Stripe retries). It's `null` when there is no entitled subscription.

   `org` carries the organization's branding and defaults for branded reports (`null` in personal context).

   `is_super_admin` is the user's RE-Account super-admin flag (read live). `product_role` is the user's role **for this product in the org** (from the members page, e.g. `manager`/`agent` for re-intel); `null` in personal context, or when the product declares no roles.

   `context` is `"personal"` (with `org_id`/`org_name`/`org_role` all `null`) when the user chose to continue as themselves rather than as an organization.

5. **Your product creates its own local session** from that response. RE-Account has no further involvement — session management inside your product is your product's responsibility. Org roles (`owner`/`admin`/`member`) describe the user's standing in the *organization*, not in your product; if your product needs its own permission model, layer it on top of this identity.

Registering a new product (name, slug, `redirect_url`, and a generated secret key) is done via the super-admin-only `POST /api/admin/products` endpoint, or directly in `DatabaseSeeder` for local development.

## Organization roles

One matrix, enforced by `OrganizationPolicy` in the backend (the frontend hides what isn't allowed, using the `permissions` returned by `GET /api/orgs/{slug}`):

| | Owner | Admin | Member |
|---|---|---|---|
| View the org, its members and seats | ✓ | ✓ | ✓ |
| Edit organization settings, upload logo | ✓ | ✓ | |
| Invite / revoke invites, remove members (never the owner), switch admin ↔ member, assign product access | ✓ | ✓ | |
| Plans, billing portal, delete the organization | ✓ | | |
| Leave the organization (`POST /api/orgs/{slug}/leave`) | (delete instead) | ✓ | ✓ |

There is always exactly one owner: the user who created the organization. Ownership can never be transferred, and nobody can be promoted to owner (the role endpoint accepts only `admin` or `member`). The owner can't be removed, demoted or leave; they can only delete the organization. Forbidden actions return 403.

## Organization settings

`PUT /api/orgs/{slug}` (owner/admin) accepts: `name`, `slug`, `logo_url`, `website`, `contact_email`, `phone`, `address`, `city`, `orn` (RERA office registration number, digits, unique across organizations), `trade_license_number`, `timezone` (default `Asia/Dubai`), `brand_color` (`#RRGGBB`). Logo upload: `POST /api/orgs/{slug}/logo` (multipart `logo`, PNG/JPG/WebP ≤ 2 MB). The settings page has the sections Profile, Dubai registration, Defaults and Danger zone (owner only: transfer ownership, delete by typing the org name).

## Organizations and product access

People join an organization once. The org subscribes to each product separately, and the owner/admins decide which members use which product on the org's **Members** page (one toggle per product). Members can only view this.

- **Seats are counted per product**: members assigned to the product + pending invites that include it, against the seats the org bought for that product (no entitled plan = unlimited). Assigning or inviting past the limit fails with 422 `seat_limit_reached`, and the error names the product.
- Invites carry the products they grant (`product_ids`; none are pre-selected in the UI). Accepting the invite creates the membership and the assignments.
- The owner gets a seat automatically when the org first subscribes to a product. Removing a member removes their access.
- **Product sign-in:** in org context, a user must be assigned to the product in that org. Otherwise the handoff returns 403 `no_product_access`, and RE-Account shows "You don't have access to {product} in {org}. Ask your organization admin." with an option to continue personally. Personal context is unaffected.
- API: `GET /api/orgs/{slug}/seats`, `PUT /api/orgs/{slug}/members/{userId}/products {product_ids}`; members and invites include `products`. Full design: `.project/plans/org-product-access.md`.
- **Product roles:** each product declares a list of roles (`products.roles`: `[{key, name, default}]`, edited by super admins under Admin → Products; re-intel: `manager`, `agent`, default `agent`). Every assignment and invite product carries a `role`. When none is given, owners/admins get `manager` (if the product has it) and everyone else gets the product's default. Owners/admins pick the role next to each product toggle on the members page and per product in the invite form. Accepting an invite keeps its roles. Products send `product_role` through verify-token.
- **Super-admin-only products** (`products.super_admin_only`, e.g. `re-data`): only super admins can sign in (others get 403 `not_super_admin` at handoff, and the page explains why). There are no plan, seat or org-access checks.

## Environment variables

### `account-backend/.env`

| Variable | Purpose |
|---|---|
| `APP_URL` | `http://localhost:8338` |
| `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | `127.0.0.1` / `5433` / `re_account` / `re_user` / `re_password` — same Postgres container as `data-source`, separate database |
| `FRONTEND_URL` | `http://localhost:3742` — used for CORS, Sanctum's stateful domain, and building links in emails |
| `SANCTUM_STATEFUL_DOMAINS` | `localhost:3742,127.0.0.1:3742` |
| `JWT_SECRET` | Random 64-char secret used to sign/verify handoff and email-verification tokens |
| `JWT_TTL` | Handoff token lifetime in seconds (default `900` = 15 min) |
| `MAIL_HOST` / `MAIL_PORT` | `127.0.0.1` / `1026` (Mailpit) |
| `STRIPE_SECRET` | Stripe secret key (`sk_test_…` in dev). Empty = billing disabled: billing endpoints return 503 `billing_unavailable`, free plans keep working |
| `STRIPE_WEBHOOK_SECRET` | `whsec_…` signing secret for `POST /api/stripe/webhook` (printed by `stripe listen`) |
| `STRIPE_CURRENCY` | Currency of the Stripe Prices (default `usd`) |

### `account-frontend/.env.local`

| Variable | Purpose |
|---|---|
| `NEXT_PUBLIC_API_URL` | `http://localhost:8338/api` |
| `NEXT_PUBLIC_APP_URL` | `http://localhost:3742` |

## Billing

Paid plans go through Stripe (`stripe/stripe-php`, no Cashier): hosted **Checkout** to subscribe, the hosted **Customer Portal** for payment methods, invoices and cancellation. No card UI and no publishable key in the frontend. USD, org billing is owner-only, no tax/trials/promo codes in v1.

- **Personal plans** are fixed-price (quantity 1), one Stripe subscription per user and product.
- **Organization plans are per seat per product** (plan: `.project/plans/per-seat-billing.md`): `price_cents` is per seat per period, no included seats, **minimum 2 seats**, prepaid **quarterly or yearly**. The purchased quantity is the product's seat limit; **plans are not defined by seat numbers** (no per-plan seat cap). Each org has **one Stripe subscription with one item per product**: the first paid product goes through Checkout and sets the org's billing date; later products are added as co-termed items (prorated now), so the org gets one invoice. **Added seats** are charged now, prorated, after a preview. **Removed seats** apply at renewal with no refund and never below the seats in use. Free org plans need no Stripe; the owner still picks a seat count up to one global cap, `FREE_ORG_MAX_SEATS` (default 3; an org already above it keeps its seats). In verify-token, `plan.seat_limit` is the org's purchased quantity (null for personal).

**Flow**

- `PUT /api/profile/subscription` `{plan_id}` or `PUT /api/orgs/{slug}/subscription` `{plan_id, quantity?, proration_date?}` is the single "make this my plan / seats" call; `POST /api/orgs/{slug}/subscription/preview` `{plan_id, quantity}` returns what it would do and the amount due now. The backend decides and returns `result`:
  - `changed` — free→free (instant), paid→paid (price swapped now, prorated difference charged now; a failed payment → 402 `payment_failed`), org seats added or a product added to the org's subscription (prorated, charged now), or a no-op.
  - `checkout` — free/none/complimentary → paid: open `checkout_url`; the local row only changes once Stripe confirms.
  - `scheduled` — paid→free: the plan ends at period end, then the subject drops to the free plan (`next_plan`); or org seats reduced from the next renewal (`scheduled_quantity`).
  - `resumed` — re-selecting the current paid plan while a cancellation is scheduled, or keeping the current seats while a reduction is scheduled.
- Checkout returns to `FRONTEND_URL/billing/return?session_id=…`; that page calls `GET /api/billing/checkout-sessions/{id}`, which syncs synchronously (works without webhooks).
- Portal: `POST /api/profile/billing/portal` / `POST /api/orgs/{slug}/billing/portal` → `{url}`.
- `POST /api/stripe/webhook` keeps the local rows in sync (checkout completed, subscription created/updated/deleted, invoice paid/failed). Failed payments: `past_due` keeps the plan while Stripe retries; when Stripe gives up the subject drops to the free plan.
- Deleting an org cancels its Stripe subscriptions immediately (no refund). Admins can't delete a plan that has paid subscribers (409 `plan_has_paid_subscribers`).
- Billing errors carry a machine-readable `code` (`plan_not_purchasable`, `seats_over_limit` (with `seats_used`, `assigned`, `pending_invites`), `billing_period_mismatch`, `subscription_ending`, `seat_change_scheduled`, `plan_ending`, `billing_issue`, `payment_failed`, `no_billing_account`, `billing_unavailable`, `stripe_error`). Full contract: `.project/plans/stripe.md`.

**Linking plans to Stripe Prices** — either paste the `price_…` (and optionally `prod_…`) ID into the admin plan form, or run `php artisan billing:sync-stripe-plans` (`--dry-run` to preview), which creates a Product + recurring per-unit Price for every active paid plan without one (quarterly = `month` × `interval_count` 3; org Products get `unit_label: seat`). A paid plan without a price ID is not purchasable (`is_purchasable: false`).

**Local testing (Stripe test mode)**

1. Put your `sk_test_…` key in `account-backend/.env` as `STRIPE_SECRET`.
2. `docker compose exec account-backend php artisan billing:sync-stripe-plans`
3. `stripe listen --forward-to localhost:8338/api/stripe/webhook` (host Stripe CLI) and copy the printed `whsec_…` into `STRIPE_WEBHOOK_SECRET`. `.env` is bind-mounted, so no rebuild is needed.
4. Stripe Dashboard (test mode): Customer portal → enable payment methods, invoice history, cancel at period end, **disable plan switching and quantity changes** (seats and products are changed only in the app); Revenue recovery → Smart Retries, then cancel the subscription after the last retry.
5. Test cards: `4242 4242 4242 4242` (succeeds), `4000 0025 0000 3155` (3DS), `4000 0000 0000 0341` (later charges fail → `past_due`). Use Test Clocks for renewals and end-of-period downgrades.

## Delivery checklist

- [x] Migrations, models, and relationships (`users`, `organizations`, `org_members`, `org_invites`, `products`, `password_reset_tokens`)
- [x] All API routes with proper auth middleware (`auth:sanctum` for user routes, a `superadmin` middleware for `/api/admin/*`, org-role checks centralised in `OrganizationPolicy`)
- [x] Form validation with proper error responses (422 + field errors via Laravel's standard validation exceptions)
- [x] Email templates for verification, password reset, and org invites (Mailpit-tested)
- [x] JWT signing and verification (`App\Services\TokenService`, `firebase/php-jwt`)
- [x] All Next.js pages listed in the spec, including the `/auth` product handoff page
- [x] Context switcher in the top navigation (Personal / per-organization), persisted in `localStorage`
- [x] Postgres 16 (shared with `data-source`, separate `re_account` database) and Mailpit, both in the root `docker-compose.yml`
- [x] `DatabaseSeeder` with a super admin and a test product
- [x] This README

### Notable Phase 1 additions beyond the original endpoint list

- `POST /api/auth/handoff/complete` (authenticated) — lets an already-signed-in user complete a product handoff without re-entering credentials, by confirming/choosing a context (Personal or an org) on the `/auth` page.
- `GET /api/invites/{token}` (authenticated) — lets the frontend preview an invite (org name, role, expiry) before the user accepts it.
- `GET /api/orgs/{slug}` also returns the requesting user's `my_role` in that org, so the frontend doesn't need a second round trip to know what UI to show.
