# account-frontend — RE-Account web UI

Sign-up, login, profile, orgs/members, plans, super-admin pages, and the **product handoff** page that products (e.g. `re`) send users to for sign-in. Talks only to `account-backend`. See `../README.md` for the whole RE-Account service.

- **Stack:** Next.js 15.5 (App Router), React 19.0, TypeScript strict, Tailwind v4 (`@tailwindcss/postcss`). No data/state/form libs, no tests.
- **Port:** 3742 · API: `NEXT_PUBLIC_API_URL` (default `http://localhost:8338/api`)
- **Run:** `docker compose up account-frontend` from `infra/local/` (or `npm run dev -- --port 3742`). Prod image: `Dockerfile` target `prod` (standalone).
- Path alias `@/*` → `./src/*`.

## Layout
- `src/app/` — routes:
  - auth: `/login`, `/register`, `/forgot-password`, `/reset-password`, `/verify-email`
  - handoff: `/auth?product=&redirect=`
  - user: `/dashboard`, `/profile`, `/invites/[token]`, `/billing/return?session_id=` (Stripe Checkout success URL)
  - orgs: `/orgs/new`, `/orgs/[slug]` (+ `/settings`, `/members`)
  - super admin: `/admin`, `/admin/users`, `/admin/orgs`, `/admin/products`, `/admin/products/[id]/plans`
- `src/components/` — `ui.tsx` primitives (Button, LinkButton, Input, Label, Field, Card, Banner); `AuthShell`, `RouteGuard` (RequireAuth / RequireGuest / RequireSuperAdmin), TopNav, AdminNav, OrgTabs, ProductSelector, PlanPicker, BillingStatus (+ `BillingErrorBanner`), SeatChangePanel, LeaveOrgButton, ThemeToggle.
- `src/lib/`:
  - `api.ts` — fetch wrapper
  - `auth-context.tsx` — AuthProvider: `/auth/me` + `/orgs`
  - `types.ts`
  - `context-storage.ts` — Personal/Org context in localStorage `reaccount:context`
  - `billing.ts` — `changePlan(path, planId, {quantity, proration_date}?)`, `previewOrgChange`, `openPortal`, `formatPrice`/`formatSeatPrice`/`formatMoney`/`formatDate`, `useBillingFlag` (`?billing=success|canceled`)
  - `theme.ts` (`useThemePref`, `setThemePref`) + `theme-script.ts` (`THEME_KEY`, inline pre-paint script)
  - `useOrg.ts` (org + `myRole` + `permissions` from the backend policy), `useProducts.ts`, `routing.ts`

## How it talks to the API (`src/lib/api.ts`)
- Sanctum SPA cookie auth: `credentials: "include"`, `Accept: application/json`.
- Before the first non-GET request it fetches `{root}/sanctum/csrf-cookie` once, then sends `X-XSRF-TOKEN` from the `XSRF-TOKEN` cookie.
- On a 401 it hard-redirects to `/login?next=…`; pass `skipAuthRedirect: true` to opt out.
- Errors throw `ApiError(status, message, errors, code?, data)`. `fieldError(field)` reads the first Laravel 422 field error. `code` is the machine-readable error: access `seat_limit_reached`, `no_product_access`, `not_super_admin`, or billing (`seats_over_limit`, `billing_period_mismatch`, `subscription_ending`, `seat_change_scheduled`, `plan_ending`, `billing_issue`, `payment_failed`, `plan_not_purchasable`, `no_billing_account`, `billing_unavailable`, `stripe_error`, `plan_has_paid_subscribers`); `data` is the full payload (e.g. `seats_used`).
- Use `api.get/post/put/delete<T>`. Don't call `fetch` directly.

## Handoff flow (`src/app/auth/page.tsx`)
1. `GET /auth/handoff?product=&redirect=` → `{handoff_session, product}`.
2. Signed in: "Continue to {product}" with a Personal/org picker → `POST /auth/handoff/complete`. Guest: login/register tabs, posting `handoff_session` and `org_id`.
3. Super-admin-only product (`product.super_admin_only`, e.g. re-data): the Register tab is hidden; a 403 `not_super_admin` shows "{product} is only available to RE-Account super admins" with "Sign in as a different account".
3. Org context without a product seat → 403 `no_product_access`: the page shows "You don't have access to {product} in {org}. Ask your organization admin." with **Continue personally** (re-posts `handoff/complete` without `org_id`).
4. The backend returns `redirect_url` (product callback + `?token=` JWT, valid 15 min). The page sets `window.location.href` to it.

## Org product access (`/orgs/[slug]/members`)
- Seat pills per product from `GET /orgs/{slug}/seats` ("RE Intel 2/3"); one members table with a toggle column per org product (`PUT /orgs/{slug}/members/{userId}/products` with `{products: [{product_id, role?}]}`). When assigned and the product declares roles (`seats[].product.roles`), a **product role select** sits next to the toggle (members see the role name read-only). Turning a product on omits `role` so the backend default applies (owner/admin → manager). A full product disables off→on toggles and shows a note (owner: link to Settings to upgrade). Members see read-only ✓/—.
- Invite form: email, role, product checkboxes (**none pre-selected**, full products disabled), plus a role select per checked product (pre-set by `defaultProductRole`, which mirrors the backend). Pending invites list their products with the role; the invite accept page shows them too.
- `/admin/products`: the product form has a **Roles** editor (name, key, one Default radio, add/remove) and a "Super admins only" checkbox; the table shows a Roles column.

## Org roles and settings
- **Gate UI on `permissions` from `useOrg`** (`update`, `manage_invites`, `manage_members`, `manage_product_access`, `manage_billing`, `delete`, `leave`), never on the role string. The backend (`OrganizationPolicy`) is the source of truth; the UI only hides what would 403.
- `/orgs/[slug]/members`: owner/admins get invite, role select (admin↔member, never on the owner row), remove (not owner, not self), product toggles. Ownership can never be transferred: there is no "Make owner" and no owner option in role selects. Members get a read-only table with a note. Admins/members see **Leave organization** (`LeaveOrgButton`: `POST /orgs/{slug}/leave`, clears the active context via `clearOrgContext`, goes to `/dashboard`).
- `/orgs/[slug]/settings`: one form, sections **Profile** (logo upload `POST /orgs/{slug}/logo`, name, website, contact email, phone, address, city) / **Dubai registration** (RERA ORN, trade licence) / **Defaults** (timezone, default Asia/Dubai; brand colour hex) → `PUT /orgs/{slug}`; members see it disabled with a banner. Then Plan & seats; then **Danger zone** (owner only: delete by typing the exact org name; the owner can't leave). Non-owners get a "Leave organization" card instead.

## Billing (Stripe, see `.project/plans/stripe.md` and, for orgs, `.project/plans/per-seat-billing.md`)
- Hosted Stripe Checkout + Customer Portal only: no card UI, no publishable key, no new env vars.
- `PUT /profile/subscription` or `/orgs/{slug}/subscription` `{plan_id}` is the single plan-change call; the backend answers `result: changed|scheduled|resumed|checkout`. `changePlan()` follows `checkout_url`; never re-implement billing rules in the UI.
- Checkout returns to `/billing/return?session_id=`, which polls `GET /billing/checkout-sessions/{id}` (2 s, 15 tries) and redirects to `/profile` or `/orgs/{slug}/settings` with `?billing=success|canceled`. Stripe's cancel URL lands there directly with `?billing=canceled`.
- **Org plans are per seat** (min 2 per product, quarterly/yearly). Settings → Plan & seats shows purchased / in use / billing date, "N seats from <date>" + "Keep N seats" for a scheduled reduction, and (owner) **Add seats** / **Reduce seats**. Picking a plan or changing seats opens `SeatChangePanel`: stepper with a live total, then `POST /orgs/{slug}/subscription/preview` (debounced) gives the action and the amount due now (Stripe proration for added seats / co-termed products); Confirm sends `{plan_id, quantity, proration_date}`. Labels come from `preview.action`; `billing_unavailable` renders as a calm info banner. PlanPicker shows `$X / seat / period` for org plans (no "up to N seats"; the seat stepper's max is `max_seats`: the global free cap for free org plans, null for paid) and gets `confirmChanges={false}` there (the panel replaces the confirm() dialogs).
- Portal: `openPortal("/profile/billing/portal" | "/orgs/{slug}/billing/portal")`; only shown when `billing_portal_available` (org: owner only).
- Admin plans (`/admin/products/[id]/plans`): org plans use "Price per seat" and Quarterly/Yearly (no seat field: plans aren't defined by seat numbers) (Monthly only for existing monthly plans); the table shows `$X / seat / quarter`. Paid plans need a billing period; `stripe_price_id` is optional (blank → use Sync). The Stripe column shows the backend's `stripe_sync_status` (Synced / Out of sync / Not synced / —) with a per-plan Sync button, plus "Sync all with Stripe" and a status line from `stripe_sync_summary`. The products list shows a summary badge. Badges + error text live in `components/StripeSync.tsx` (`billing_unavailable` → "Stripe isn't configured: set STRIPE_SECRET").

## Conventions
- **Every component is a client component** (`"use client"`). No server components, actions, route handlers or middleware.
- Data: `useEffect` + `useState` + `api.*`. Wrap pages that use `useSearchParams` in `<Suspense>`.
- Guard pages by wrapping their content in a `RouteGuard` component.
- Styling: use the theme token classes from `globals.css` (`bg-surface`, `text-muted`, `border-border`, `bg-accent`…), never raw colors. Dark by default; accent is indigo. Layouts use `max-w-5xl`. Reuse the `ui.tsx` primitives.
- Theme: Light / Dark / System (default). `ThemeToggle` (cycling icon button in TopNav, AdminNav and AuthShell) stores the choice in localStorage `reaccount:theme` and sets `<html data-theme="light|dark">`; System removes the attribute and `globals.css` follows `prefers-color-scheme` via `:root:not([data-theme])`. The root layout's `<head>` inline script applies the stored value before paint (`<html suppressHydrationWarning>`). New tokens need values in all three blocks.

## Gotchas
- `NEXT_PUBLIC_*` values are baked in at build time and passed as Docker build args.
- Cookies need the origin to match the backend's `SANCTUM_STATEFUL_DOMAINS` and `FRONTEND_URL`. Use `localhost`, not `127.0.0.1`.
- `NEXT_PUBLIC_APP_URL` is declared but unused.
- `README.md` here is the create-next-app boilerplate.
