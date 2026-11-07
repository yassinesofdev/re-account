# account-backend — RE-Account API

The central identity service: users, organizations, members and invites, products, plans and subscriptions, and the **product sign-in handoff** (JWT). The UI is `../account-frontend`; the first product is `re` (slug `re-intel`). `../README.md` describes the service (including its Billing section). **The code is the source of truth.**

- **Stack:** Laravel 13 (PHP 8.3+, image `serversideup/php:8.4-fpm-nginx`), Sanctum 4, `firebase/php-jwt`, `stripe/stripe-php` (no Cashier). DB is Postgres `re_account` (same container as data-source). Mail goes through Mailpit.
- **Port:** 8338 on the host → 8080 in the container. **Run:** `docker compose up account-backend` from `infra/local/`.
- **Commands:** `docker compose exec account-backend php artisan migrate` · `… db:seed` · `… composer test` (SQLite `:memory:`; Postgres-only migration SQL is guarded by `DB::getDriverName() === 'pgsql'`, keep doing that) · `… php artisan billing:sync-stripe-plans [--dry-run]`.

## Layout
- `routes/api.php` holds the user and product API.
- `routes/admin.php` is mounted at `api/admin` with `auth:sanctum` + `superadmin` (see `bootstrap/app.php`). It's kept separate on purpose so it can become its own service.
- `app/Http/Controllers/Api/`:
  - user/product: Auth, Token, Profile, ProfileSubscription, Org, OrgMember, OrgInvite, OrgSubscription (plan + seats + preview), Plan, Product, Billing (checkout session + portals), StripeWebhook
  - `Admin/`: the admin controllers
- `app/Services/` holds the business logic: AuthService, TokenService (JWT), OrgService, SubscriptionService, and `Billing/` (BillingService for personal plans + Checkout + the sync path, OrgBillingService for per-seat org plans, StripePlanSync, the `StripeGateway` interface + `StripeSdkGateway`, bound in AppServiceProvider).
- `app/Exceptions/BillingException.php`: billing errors rendered as `{message, code, ...extra}` (registered in `bootstrap/app.php`, which also maps Stripe `CardException` → 402 `payment_failed` and other `ApiErrorException` → 502 `stripe_error`).
- `app/Console/Commands/SyncStripePlans.php`; tests in `tests/Feature/Billing/` use `tests/Fakes/FakeStripeGateway.php` (never hits Stripe).
- `app/Mail/` + `resources/views/emails/`: verify-email, reset-password, invite.
- Other files: `app/Http/Middleware/SuperAdmin.php`, `config/jwt.php`, `config/cors.php`.

## Routes (`/api`)
- **Public:**
  - `auth/register`, `auth/login`, `auth/handoff` (GET), `auth/verify-email/{token}`, `auth/forgot-password`, `auth/reset-password`
  - `verify-token`, which is guarded by the product secret, not Sanctum (returns the identity incl. the user's `timezone`)
  - `product/user-status` (POST `{product, user_id, org_id?}` + `X-Product-Secret`) → `{exists, active, org_member, timezone}`; `org_member` is null without `org_id`; `active` == `exists` (no suspended state yet). Used by data-source before scheduled report emails. Controller: `ProductUserStatusController`; test: `tests/Feature/ProductUserStatusTest.php`.
  - `stripe/webhook` (POST), verified by the `Stripe-Signature` header; CSRF-exempt
- **auth:sanctum:**
  - `auth/logout`, `auth/me`, `auth/resend-verification`, `auth/handoff/complete`
  - `products`, `plans`
  - `profile` (+ `/avatar`, `/subscription?product=`, `/billing/portal`)
  - `orgs/{slug}` CRUD (GET adds `my_role` + `permissions`; PUT = settings), `/logo` (POST multipart `logo`), `/leave` (POST), `/members` (each with `products`), `/members/{userId}/role`, `/members/{userId}/products` (PUT `{products: [{product_id, role?}]}` or legacy `{product_ids: [id | {product_id, role?}]}`), `/seats`, `/subscription` (+ POST `/subscription/preview`), `/billing/portal`, `/invites`. Who may call what: see **Org roles** below.
  - `billing/checkout-sessions/{sessionId}` (return page; only the user who started the checkout)
  - `invites/{token}` (+ `/accept`)
- **Admin:** `users`, `orgs`, `products` (+ `/{product}`, `/plans`, `/stripe-sync`; POST/PUT accept `roles` + `super_admin_only`), `plans` (+ `/{plan}/stripe-sync`).

## Data model (all IDs are UUIDs)
- `users` has `is_super_admin`.
- `organizations` (slug) ↔ `org_members` (role owner/admin/member). Settings columns (migration `2026_11_02_000001`): `website`, `contact_email`, `phone`, `address`, `city`, `orn` (RERA ORN, digits, **unique** when set), `trade_license_number`, `timezone` (default `Asia/Dubai`), `brand_color` (`#RRGGBB`, stored uppercase); Postgres-only CHECKs on `orn`/`brand_color`. Validation lives in `OrgController::update` (`website` without a scheme gets `https://`). Logos are stored on the `public` disk under `org-logos/` (same pattern as avatars).
- `org_invites`: the token is stored as a sha256 hash, and invites expire.
- `products`: `redirect_url`, and `secret_key` **hashed** with bcrypt (the plain value is shown once). `roles` jsonb `[{key, name, default}]` (normalised on save by `Product::normalizeRoles`: exactly one default; key `^[a-z][a-z0-9_-]*$`, distinct) and `super_admin_only` bool (migration `2026_11_02_000004`).
- **Product roles** (contract: `.project/plans/restructure.md`, "Contract: RE-Account additions"): `org_member_products.role` / `org_invite_products.role` (nullable; null iff the product declares no roles). `Product::defaultRoleFor($orgRole)`: owner/admin → `manager` when declared, else the default role. `OrgService::resolveProductAssignments()` validates (422 `product_role`) and fills defaults (a plain id keeps the member's current role); `setMemberProducts` updates roles in place (no seat check). Invites accept `products: [{product_id, role?}]` (or `product_ids` with ids/objects); accept carries the role (default if missing). Members list, invites and `GET invites/{token}` return `products: [{id, slug, name, role}]`; `seats[].product` includes `roles`. Admin role-list edits run `OrgService::reconcileProductRoles` (removed role → default). The migration seeded re-intel's roles and backfilled (owners/admins → manager, members → agent; portable `backfill()`, tested in `tests/Feature/ProductRolesTest.php`).
- `plans`: scope personal/organization, `price_cents`, `billing_period` (`month`|`quarter`|`year`). **Org plans are per seat**: `price_cents` = per seat per period. **Plans are not defined by seat numbers** (`plans.seat_limit` was dropped, migration `2026_11_02_000003`): an org's limit is the quantity it bought. Free org plans (price 0) share one global cap, `config('billing.free_org_max_seats')` (env `FREE_ORG_MAX_SEATS`, default 3) → `Plan::seatCap()` (null for paid/personal), exposed read-only as `max_seats` in plan JSON.
- `subscriptions` is polymorphic (User or Organization), with **one per subject per product**. Org rows carry `quantity` (purchased seats) and `scheduled_quantity` (seats from next renewal); personal rows have `quantity = null`.
- **Per-product access** (plan: `.project/plans/org-product-access.md`): `org_member_products` (org_id, user_id, product_id, assigned_by; composite FK → `org_members`, so removing a member removes their access) and `org_invite_products` (invite_id, product_id; granted on accept). Only **org products** (products the org has a subscription row for) can be assigned. An org's first subscription to a product auto-assigns its owner (`Subscription::booted` → `OrgService::assignOwnerToNewProduct`).
- Seats are **per product**: `OrgService::seatsUsed($org, $product)` (= `seatUsage()` assigned + pending unexpired invites including that product) vs `seatLimitFor` (entitled subscription's `quantity`; null = unlimited). Assigning/inviting past it → `SeatLimitExceededException` (renders itself: 422 `{message, code: seat_limit_reached, product, seats_used, seat_limit}`). `GET orgs/{slug}/seats` → `{seats: [{product, plan, used, limit}]}`. Invites take `product_ids` (legacy `product_slug` still accepted). Migration `2026_10_31_000001` backfilled existing members + pending invites with all their org's subscribed products (portable `backfill()`, exercised in `tests/Feature/OrgProductAccessTest.php`).
- Stripe columns: `users`/`organizations.stripe_customer_id` (hidden, set via `forceFill`), `plans.stripe_price_id`/`stripe_product_id` + `stripe_synced_*` snapshot, `subscriptions.stripe_subscription_id` (not unique: an org's product rows share one Stripe subscription) / `stripe_subscription_item_id` (unique) / `stripe_price_id` (hidden) + `current_period_end`, `cancel_at_period_end`; `stripe_webhook_events` (idempotency). `subscriptions.plan_id` FK is `restrictOnDelete`.

## Billing (Stripe)
- Contract: `.project/plans/stripe.md` (personal; base contract) and `.project/plans/per-seat-billing.md` (org plans, binding). Env: `STRIPE_SECRET` (empty → 503 `billing_unavailable`; free plans still work), `STRIPE_WEBHOOK_SECRET`, `STRIPE_CURRENCY` (usd).
- Personal: `PUT profile/subscription` → `BillingService::changePlan` decides `changed | checkout | scheduled | resumed` (quantity 1, one Stripe subscription per user+product).
- **Org (per seat)**: `PUT orgs/{slug}/subscription {plan_id, quantity?, proration_date?}` and `POST …/subscription/preview {plan_id, quantity}` → `OrgBillingService` (`plan()` classifies, `changePlan()` executes, `preview()` prices). One Stripe subscription per org, **one item per product** (quantity = seats); the first paid product goes through Checkout (sets the anniversary), later products are added as co-termed items (`always_invoice`). Min 2 seats (`OrgBillingService::MIN_SEATS`). Added seats: prorated, charged now (`error_if_incomplete`; preview via `invoices.createPreview`, its `proration_date` is passed back). Fewer seats: item quantity lowered with `proration_behavior=none` + item metadata `paid_quantity`/`paid_until` → shown as `scheduled_quantity` until renewal. Paid → free for one of several products: item deleted (no proration), the row keeps its plan with `cancel_at_period_end` until `current_period_end`; the last item uses subscription `cancel_at_period_end`. Free org plans: quantity changes are local and instant (max = the global free cap; an org already on that free plan above the cap keeps its seats). Paid org plans have no max beyond min 2. `GET …/subscription` `max_seats` = that cap (null for paid). Checks: 404 plan → 403 owner → 409 `billing_issue` → 422 `quantity` (< 2, > plan max) → 409 `seats_over_limit` (+ `assigned`, `pending_invites`) → 409 `plan_not_purchasable` → 409 `billing_period_mismatch` / `subscription_ending` / `seat_change_scheduled` / `plan_ending` → 503.
- **`BillingService::syncSubscription` is the only code that writes Stripe state locally.** Webhooks and the checkout return endpoint call it with a freshly fetched subscription. Ended statuses (`canceled`, `unpaid`, `incomplete_expired`, `paused`) fall back to the product's free plan for that scope (or `status=canceled` if none; org quantity clamped to the global free cap). Org subscriptions (`syncOrgSubscription`) map every item → plan (price) → product row and write plan/item/quantity/scheduled quantity; linked rows whose item is gone fall back to free unless ending and still inside the paid period.
- Entitlement: `Subscription::isEntitled()` (`active`, `trialing`, `past_due`). verify-token and `OrgService::seatLimitFor` only use entitled plans. `is_paid` = has a Stripe subscription; a paid plan without one is "complimentary".
- Webhooks run synchronously (no queue worker in compose). Local: `stripe listen --forward-to localhost:8338/api/stripe/webhook`.
- Admin plans: paid org plans must bill per `quarter` or `year` when created/re-priced (existing monthly ones keep working); no seat field. Optional `stripe_price_id` (`^price_`, unique) / `stripe_product_id`. A hand-pasted price ID is verified via `retrievePrice` (`StripePlanSync::verifyManualLink`); if Stripe is unreachable or the Price doesn't match, the plan reads `out_of_sync`. Deleting a plan with paid subscribers → 409 `plan_has_paid_subscribers`.
- Plan sync (`Services/Billing/StripePlanSync`, used by the admin endpoints and `billing:sync-stripe-plans`): `plans.stripe_synced_{price_cents,billing_period,name,at}` snapshot what Stripe holds; `Plan.stripe_sync_status` = `not_required` (free) · `not_synced` (no price ID) · `out_of_sync` (differs from the snapshot, or never verified) · `synced`. Sync creates Product + per-unit Price (`recurring.interval_count` 3 for quarter; org Products get `unit_label: seat`), renames the Product on name changes, and on price/period changes creates a new Price on the same Product (set as `default_price`) and archives the old one; existing subscribers stay on the old Price. `POST admin/plans/{plan}/stripe-sync` → `{plan}`; `POST admin/products/{product}/stripe-sync` → `{plans, summary}` (active paid plans). Admin product responses carry `stripe_sync_summary` `{status: synced|needs_sync|not_required, synced, out_of_sync, not_synced}` (active plans only).

## Auth
- **account-frontend:** Sanctum SPA cookie auth.
  - `statefulApi()`; CORS allows only `FRONTEND_URL`, with credentials.
  - Needs `SANCTUM_STATEFUL_DOMAINS=localhost:3742,…`.
- **Product handoff** (all JWTs are HS256 with `JWT_SECRET`):
  1. The product sends the user to `FRONTEND_URL/auth?product=<slug>&redirect=<url>`.
  2. `GET auth/handoff` checks the product is active and `redirect` starts with `products.redirect_url` (a prefix match). It returns `handoff_session` (JWT, 15 min).
  3. Login, register or `handoff/complete` with `handoff_session` (+ optional `org_id`) returns `redirect_url` = redirect + `token=<handoff JWT>` (TTL `JWT_TTL`).
     - Register also attaches the product's free personal plan.
     - An `org_id` the user isn't a member of silently falls back to personal context.
     - **Super-admin-only products** (`super_admin_only`, i.e. `re-data`): non-super-admins get 403 `{code: not_super_admin, product}` from login / handoff/complete, and register refuses before creating the user. Super admins skip the seat/org-access check (no plans needed). `GET auth/handoff` returns `product.super_admin_only`.
     - Org context requires a product seat: a member not assigned to the product in that org gets 403 `{code: no_product_access, product, organization}` (also for login). Personal context is unaffected; verify-token doesn't re-check.
  4. The product server calls `POST verify-token` with `X-Product-Secret` and `{token}`, and gets back the identity plus `plan` (taken from the org or user subscription).
     - `plan` is only set for an entitled subscription and includes `status` + `current_period_end`; `seat_limit` is kept for compatibility (`re` types it) and is the org's purchased quantity (null for personal), not a plan setting (+ additive `quantity`).
     - Any failure → 401.
     - Tokens are not single-use.
     - Additive (restructure contract): top-level `is_super_admin` (bool, live from `users`) and `product_role` (string|null: the member's `org_member_products.role` for this product in org context, looked up live; null in personal context).
     - Org context also returns `org: {id, name, slug, logo_url, brand_color, timezone, orn}` (`Organization::productProfile()`); `null` in personal context. Additive: the flat `org_id`/`org_name`/`org_role` stay.
- **Seeder:**
  - Super admin `admin@re-account.com` / `secret`
  - Product `re-intel` (redirect `http://localhost:3741/auth/callback`, dev secret `re-intel-dev-secret-key-change-me`), roles `manager`/`agent` (set only when empty)
  - Product `re-data` "Data Admin" (redirect `http://localhost:3743/auth/callback`, dev secret `re-data-dev-secret-key-change-me`, `super_admin_only`, no plans)
  - Plans personal-free/pro, team-free (capped by `FREE_ORG_MAX_SEATS`), team-pro ($49/seat/quarter) and team-pro-annual (fresh installs; `firstOrCreate`, existing dev rows untouched)

## Conventions
- Thin controllers with inline `$request->validate([...])` (no FormRequests); logic lives in Services (constructor-injected).
- Responses: `response()->json([...])` with named keys (`{user}`, `{organization}`, `{members}`, `{subscription}`…). Simple actions return `{message}`; creates return 201. Errors use `abort_if(cond, code, msg)`.
- Org authorization is centralised in `app/Policies/OrganizationPolicy.php` (see **Org roles**). Controllers use the `Concerns\ResolvesOrganization` trait: `$this->resolveOrg($request, $slug, 'ability')` (404 unknown slug, 403 with the policy's message). Never add per-controller role checks.
- User routes use `{slug}` strings; admin routes use route-model binding.
- Mail is sent synchronously (`Mail::to()->send()`). Email links are built from `config('app.frontend_url')`.

## Org roles (`OrganizationPolicy::MATRIX`, the single source of truth)
| Ability | owner | admin | member |
|---|---|---|---|
| `view` (org, members, seats, subscription) | ✓ | ✓ | ✓ |
| `update` (settings, logo) · `manageInvites` · `manageMembers` (remove, admin↔member) · `manageProductAccess` | ✓ | ✓ | |
| `manageBilling` (plan change, portal) · `delete` | ✓ | | |
| `leave` | | ✓ | ✓ |
- Target rules: `removeMember` / `changeRole` deny when the target is the owner (403). Exactly one owner, the org's creator, and **ownership can never be transferred** (product decision: there is no transfer endpoint; `POST /transfer` → 404). The role endpoint only accepts `admin|member` (422 for `owner`), and the owner can't leave (403 "Delete the organization instead"); they can only delete the org. Removing yourself via DELETE members → 422 (use `/leave`).
- `GET orgs/{slug}` returns `permissions` (snake_case flags from `permissionsFor()`), which the frontend uses to show/hide actions.
- Tests: `tests/Feature/Org/OrgRoleMatrixTest.php` (data provider: owner/admin/member/outsider × every endpoint + edge cases) and `OrgSettingsTest.php`.

## Gotchas
- `public/storage` must be a **relative** symlink (`../storage/app/public`), created inside the container; a host-made `storage:link` points at a host path and 403s in Docker.
- **Always send `Accept: application/json`.** Without it, an unauthenticated API request gets a 500 "Route [login] not defined", because there is no `login` web route.
- `JWT_SECRET` in `.env` is a placeholder. Set a real one outside dev.
- `AGENTS.md` here is the generic Laravel Boost bootstrap text. **Ignore it**; it is not project guidance.
