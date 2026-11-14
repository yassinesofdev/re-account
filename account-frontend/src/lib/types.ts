export type User = {
  id: string;
  name: string;
  email: string;
  avatar_url: string | null;
  timezone: string;
  email_verified_at: string | null;
  is_super_admin: boolean;
  created_at: string;
  updated_at: string;
};

export type OrgRole = "owner" | "admin" | "member";

export type Organization = {
  id: string;
  name: string;
  slug: string;
  logo_url: string | null;
  website: string | null;
  contact_email: string | null;
  phone: string | null;
  address: string | null;
  city: string | null;
  /** RERA office registration number (digits; unique across orgs). */
  orn: string | null;
  trade_license_number: string | null;
  /** IANA timezone, default Asia/Dubai. */
  timezone: string;
  /** Hex colour (#RRGGBB) used by products for branded reports. */
  brand_color: string | null;
  created_by: string;
  created_at: string;
  updated_at: string;
  member_count?: number;
  pivot?: {
    role: OrgRole;
    joined_at: string;
  };
};

/**
 * What the caller may do in an org, from the backend's OrganizationPolicy
 * (GET /orgs/{slug} → permissions). Never re-derive these from the role.
 */
export type OrgPermissions = {
  view: boolean;
  update: boolean;
  manage_invites: boolean;
  manage_members: boolean;
  manage_product_access: boolean;
  manage_billing: boolean;
  delete: boolean;
  leave: boolean;
};

/** Minimal product reference used in org access lists. */
export type ProductRef = { id: string; slug: string; name: string };

/** A role a product declares (admin-editable). Exactly one is the default. */
export type ProductRole = { key: string; name: string; default: boolean };

/** A product assignment (member or invite) with the product role; null when the product declares no roles. */
export type ProductAssignment = ProductRef & { role: string | null };

export type OrgMember = {
  id: string;
  org_id: string;
  user_id: string;
  role: OrgRole;
  joined_at: string;
  user: User;
  /** Products this member is assigned to in the org (one seat each), with the product role. */
  products: ProductAssignment[];
};

/** Per-product seat usage: assigned members + pending invites vs the plan's limit (null = unlimited). */
export type ProductSeats = {
  product: ProductRef & { roles: ProductRole[] };
  plan: { id: string; slug: string; name: string } | null;
  used: number;
  limit: number | null;
};

export type OrgInvite = {
  id: string;
  org_id: string;
  email: string;
  role: Exclude<OrgRole, "owner">;
  invited_by: string;
  accepted_at: string | null;
  expires_at: string;
  created_at: string;
  /** Products (+ product role) granted when the invite is accepted. */
  products: ProductAssignment[];
};

export type Product = {
  id: string;
  name: string;
  slug: string;
  description: string | null;
  redirect_url: string;
  tenant_redirect_url?: string | null;
  is_active: boolean;
  /** Roles members can have in this product (e.g. re-intel: manager, agent). */
  roles: ProductRole[];
  /** Only RE-Account super admins may sign in (e.g. re-data). */
  super_admin_only: boolean;
  created_at: string;
  updated_at: string;
  /** Admin endpoints only: rollup of the product's active plans vs Stripe. */
  stripe_sync_summary?: StripeSyncSummary;
};

export type StripeSyncStatus = "not_required" | "not_synced" | "out_of_sync" | "synced";

export type StripeSyncSummary = {
  status: "synced" | "needs_sync" | "not_required";
  synced: number;
  out_of_sync: number;
  not_synced: number;
};

export type Paginated<T> = {
  data: T[];
  current_page: number;
  last_page: number;
  total: number;
};

export type PlanScope = "personal" | "organization";

export type Plan = {
  id: string;
  product_id: string;
  name: string;
  slug: string;
  scope: PlanScope;
  price_cents: number;
  billing_period: string | null;
  is_active: boolean;
  /** Stripe Price this plan is billed with; null when not linked. */
  stripe_price_id: string | null;
  /** Free plans, or paid plans linked to a Stripe Price. */
  is_purchasable: boolean;
  /** Read-only: most seats an org can take (free org plans: the global FREE_ORG_MAX_SEATS cap; null = no cap). */
  max_seats: number | null;
  stripe_product_id?: string | null;
  /** Price/period/name vs the snapshot of what was last synced to Stripe. */
  stripe_sync_status: StripeSyncStatus;
  stripe_synced_at?: string | null;
  created_at: string;
  updated_at: string;
};

export type SubscriptionStatus = "active" | "trialing" | "past_due" | "unpaid" | "incomplete" | "canceled";

export type Subscription = {
  id: string;
  subscribable_type: string;
  subscribable_id: string;
  product_id: string;
  plan_id: string;
  status: SubscriptionStatus;
  started_at: string;
  canceled_at: string | null;
  /** null for free / complimentary subscriptions. */
  current_period_end: string | null;
  cancel_at_period_end: boolean;
  /** Backed by a Stripe subscription. */
  is_paid: boolean;
  plan: Plan;
  /** Plan it falls to at current_period_end when cancel_at_period_end. */
  next_plan: Plan | null;
  /** Org plans: purchased seats (the product's seat limit). null for personal plans. */
  quantity: number | null;
  /** Org plans: seats from the next renewal when a reduction is scheduled. */
  scheduled_quantity: number | null;
};

/** What a per-seat org change would do (POST /orgs/{slug}/subscription/preview). */
export type OrgChangePreview = {
  result: PlanChangeResult["result"];
  action:
    | "none"
    | "update_free"
    | "subscribe"
    | "add_product"
    | "add_seats"
    | "reduce_seats"
    | "keep_seats"
    | "downgrade"
    | "resume"
    | "change_plan";
  plan: Plan;
  quantity: number;
  current_quantity: number | null;
  unit_amount_cents: number;
  billing_period: string | null;
  /** quantity × per-seat price, per billing period. */
  recurring_amount_cents: number;
  /** Charged right away (prorated for co-termed / added seats; full first period for Checkout). */
  amount_due_now_cents: number;
  currency: string;
  effective_at: string | null;
  /** The org's billing anniversary (next renewal), when it already has a subscription. */
  renews_at: string | null;
  /** Pass back on PUT so the charge equals the preview. */
  proration_date: number | null;
};

export type PlanChangeResult = {
  result: "changed" | "scheduled" | "resumed" | "checkout";
  checkout_url: string | null;
  subscription: Subscription | null;
  seats_used?: number;
  seat_limit?: number | null;
  seats_assigned?: number;
  pending_invites?: number;
  min_seats?: number;
};

export type CheckoutSessionInfo = {
  status: "complete" | "open" | "expired";
  subscription: Subscription | null;
  context: { type: "personal" | "organization"; org_slug: string | null; product: string };
};
