"use client";

import { useParams, useRouter } from "next/navigation";
import { Suspense, useEffect, useRef, useState, type ChangeEvent, type FormEvent, type ReactNode } from "react";
import { BillingErrorBanner, BillingStatus } from "@/components/BillingStatus";
import { LeaveOrgButton } from "@/components/LeaveOrgButton";
import { OrgTabs } from "@/components/OrgTabs";
import { PlanPicker } from "@/components/PlanPicker";
import { SeatChangePanel, type SeatChangeMode } from "@/components/SeatChangePanel";
import { ProductSelector } from "@/components/ProductSelector";
import { RequireAuth } from "@/components/RouteGuard";
import { TopNav } from "@/components/TopNav";
import { Banner, Button, Card, Field, Input } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth-context";
import { clearOrgContext } from "@/lib/context-storage";
import {
  changePlan,
  formatDate,
  formatMoney,
  hasBillingIssue,
  openPortal,
  planChangeNotice,
  useBillingFlag,
} from "@/lib/billing";
import type { OrgChangePreview, Organization, Plan, PlanChangeResult } from "@/lib/types";
import { useOrg } from "@/lib/useOrg";

function PlanSeatsCard({
  slug,
  isOwner,
  productSlug,
  setProductSlug,
  org,
}: {
  slug: string;
  isOwner: boolean;
  productSlug: string;
  setProductSlug: (slug: string) => void;
  org: ReturnType<typeof useOrg>;
}) {
  const {
    subscription,
    seatsUsed,
    seatsAssigned,
    pendingInvites,
    seatLimit,
    minSeats,
    maxSeats,
    billingPortalAvailable,
    refreshSubscription,
  } = org;
  const [switchingPlanId, setSwitchingPlanId] = useState<string | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const [targetPlanName, setTargetPlanName] = useState<string | undefined>();
  const [notice, setNotice] = useState<string | null>(null);
  const [portalHidden, setPortalHidden] = useState(false);
  /** Open seat panel: a plan to choose seats for, or add/reduce on the current plan. */
  const [panel, setPanel] = useState<{ mode: SeatChangeMode; plan: Plan } | null>(null);
  const billingFlag = useBillingFlag(`/orgs/${slug}/settings`);

  const purchased = subscription?.quantity ?? seatLimit;
  const floor = Math.max(minSeats, seatsUsed);
  const issue = hasBillingIssue(subscription?.status);
  const ending = Boolean(subscription?.cancel_at_period_end);

  useEffect(() => setPanel(null), [productSlug]);

  async function portal() {
    setError(null);
    try {
      await openPortal(`/orgs/${slug}/billing/portal`);
    } catch (err) {
      if (err instanceof ApiError && err.code === "no_billing_account") {
        setPortalHidden(true);
        return;
      }
      setError(err instanceof ApiError ? err : new ApiError(0, "Could not open billing."));
    }
  }

  /** Direct change without the seat panel: resume a plan, keep scheduled seats. */
  async function directChange(nextPlan: Plan, quantity?: number) {
    setSwitchingPlanId(nextPlan.id);
    setTargetPlanName(nextPlan.name);
    setError(null);
    setNotice(null);

    try {
      const res = await changePlan(
        `/orgs/${slug}/subscription`,
        nextPlan.id,
        quantity != null ? { quantity } : undefined,
      );
      setNotice(
        quantity != null ? `You keep ${quantity} seats.` : planChangeNotice(res.result, res.subscription, nextPlan),
      );
      await refreshSubscription();
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError(0, "Could not switch plan."));
    } finally {
      setSwitchingPlanId(null);
    }
  }

  function selectPlan(nextPlan: Plan) {
    // Re-selecting a plan that is set to end resumes it; anything else picks seats first.
    if (subscription && nextPlan.id === subscription.plan_id && ending) {
      directChange(nextPlan);
      return;
    }
    setNotice(null);
    setError(null);
    setPanel({ mode: "plan", plan: nextPlan });
  }

  async function panelDone(res: PlanChangeResult, preview: OrgChangePreview | null) {
    const plan = panel?.plan;
    setPanel(null);
    if (preview?.action === "add_seats" || preview?.action === "add_product") {
      setNotice(
        `Done. ${formatMoney(preview.amount_due_now_cents)} was charged and you now have ${res.subscription?.quantity ?? preview.quantity} seats.`,
      );
    } else if (preview?.action === "reduce_seats") {
      setNotice(`${preview.quantity} seats from ${formatDate(preview.effective_at)}. You keep your current seats until then.`);
    } else if (plan) {
      setNotice(planChangeNotice(res.result, res.subscription, plan));
    }
    await refreshSubscription();
  }

  /** Free org plans share one global cap (an org already above it keeps its seats); paid plans have none. */
  function maxFor(plan: Plan): number | null {
    if (plan.id === subscription?.plan_id) return maxSeats;
    return plan.max_seats ?? null;
  }

  function initialFor(mode: SeatChangeMode, plan: Plan): number {
    const current = purchased ?? minSeats;
    if (mode === "add") return current + 1;
    if (mode === "reduce") return Math.max(floor, (subscription?.scheduled_quantity ?? current) - 1);
    const wanted = Math.max(minSeats, seatsUsed, subscription?.quantity ?? 0);
    const cap = maxFor(plan);
    return cap != null ? Math.min(cap, wanted) : wanted;
  }

  const canAdd = isOwner && subscription && !issue && !ending && (maxSeats == null || (purchased ?? 0) < maxSeats);
  const canReduce = isOwner && subscription && !issue && !ending && (purchased ?? 0) > floor;
  const isOrgPlan = subscription?.plan.scope === "organization";

  return (
    <Card className="mt-6">
      <div className="mb-3 flex items-center justify-between">
        <h2 className="text-lg font-semibold text-foreground">Plan &amp; seats</h2>
        <ProductSelector value={productSlug} onChange={setProductSlug} />
      </div>

      <div className="mb-4 space-y-3">
        {billingFlag === "success" && (
          <Banner variant="success">
            {subscription ? `You're now on ${subscription.plan.name}.` : "Payment complete."}
          </Banner>
        )}
        {billingFlag === "canceled" && <Banner variant="info">Checkout canceled.</Banner>}
        {notice && <Banner variant="success">{notice}</Banner>}
        {error && (
          <BillingErrorBanner
            error={error}
            targetPlanName={targetPlanName}
            membersHref={`/orgs/${slug}/members`}
            onPortal={isOwner ? portal : undefined}
          />
        )}

        <BillingStatus
          subscription={subscription}
          portalAvailable={billingPortalAvailable && !portalHidden}
          canManage={isOwner}
          onPortal={portal}
          onResume={async () => {
            if (subscription) await directChange(subscription.plan);
          }}
        />

        {subscription && isOrgPlan && (
          <div className="grid gap-3 sm:grid-cols-3">
            <SeatStat value={purchased ?? "∞"} label="Seats purchased" />
            <SeatStat
              value={seatsUsed}
              label={pendingInvites > 0 ? `In use (${seatsAssigned} assigned, ${pendingInvites} invited)` : "Assigned"}
            />
            <SeatStat
              value={subscription.current_period_end ? formatDate(subscription.current_period_end) : "—"}
              label={subscription.is_paid ? "Renews (billing date)" : "No renewal (free)"}
            />
          </div>
        )}
        {!isOrgPlan && (
          <p className="text-sm text-muted tabular-nums">
            {seatsUsed} / {seatLimit ?? "∞"} seats used
          </p>
        )}

        {subscription?.scheduled_quantity != null && (
          <div className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-border px-4 py-3">
            <span className="text-sm text-foreground tabular-nums">
              {subscription.scheduled_quantity} seats from {formatDate(subscription.current_period_end)}
              <span className="text-muted"> · {purchased} until then</span>
            </span>
            {isOwner && (
              <Button
                variant="secondary"
                loading={switchingPlanId === subscription.plan_id}
                onClick={() => directChange(subscription.plan, purchased ?? undefined)}
              >
                Keep {purchased} seats
              </Button>
            )}
          </div>
        )}

        {isOwner && subscription && isOrgPlan && !panel && (canAdd || canReduce) && (
          <div className="flex flex-wrap gap-2">
            {canAdd && <Button onClick={() => setPanel({ mode: "add", plan: subscription.plan })}>Add seats</Button>}
            {canReduce && (
              <Button variant="secondary" onClick={() => setPanel({ mode: "reduce", plan: subscription.plan })}>
                Reduce seats
              </Button>
            )}
          </div>
        )}

        {panel && (
          <SeatChangePanel
            key={`${panel.mode}-${panel.plan.id}`}
            slug={slug}
            plan={panel.plan}
            mode={panel.mode}
            initialQuantity={initialFor(panel.mode, panel.plan)}
            minQuantity={panel.mode === "add" ? (purchased ?? minSeats) + 1 : floor}
            maxQuantity={
              panel.mode === "reduce" ? Math.max(floor, (purchased ?? floor) - 1) : maxFor(panel.plan)
            }
            seatsUsed={seatsUsed}
            onDone={panelDone}
            onCancel={() => setPanel(null)}
          />
        )}
      </div>

      {isOwner ? (
        <PlanPicker
          productSlug={productSlug}
          scope="organization"
          subscription={subscription}
          onSelect={selectPlan}
          switchingPlanId={switchingPlanId}
          confirmChanges={false}
          disabledReason={issue ? "Update your payment method before changing plans." : undefined}
        />
      ) : (
        <p className="text-sm text-muted">Only the organization owner can change the plan or seats.</p>
      )}
    </Card>
  );
}

/** Number-forward stat: value first, muted label below. */
function SeatStat({ value, label }: { value: ReactNode; label: string }) {
  return (
    <div className="rounded-xl border border-border px-4 py-3">
      <p className="text-xl font-semibold text-foreground tabular-nums">{value}</p>
      <p className="mt-0.5 text-xs text-muted">{label}</p>
    </div>
  );
}

const FIELDS = [
  "name",
  "website",
  "contact_email",
  "phone",
  "address",
  "city",
  "orn",
  "trade_license_number",
  "timezone",
  "brand_color",
] as const;

type FieldKey = (typeof FIELDS)[number];
type FormState = Record<FieldKey, string>;

const DEFAULT_TIMEZONE = "Asia/Dubai";
const HEX = /^#[0-9A-Fa-f]{6}$/;

function timezones(current: string): string[] {
  const intl = Intl as unknown as { supportedValuesOf?: (key: string) => string[] };
  const all = intl.supportedValuesOf?.("timeZone") ?? [DEFAULT_TIMEZONE, "UTC", "Europe/London", "Asia/Riyadh"];
  return Array.from(new Set([DEFAULT_TIMEZONE, current, ...all])).filter(Boolean);
}

function toForm(org: Organization): FormState {
  return Object.fromEntries(
    FIELDS.map((key) => [key, (org[key] as string | null) ?? (key === "timezone" ? DEFAULT_TIMEZONE : "")]),
  ) as FormState;
}

/** One settings section: title + one-line hint, then its fields. */
function Section({ title, hint, children }: { title: string; hint: string; children: ReactNode }) {
  return (
    <Card className="mt-6 first:mt-0">
      <h2 className="text-lg font-semibold text-foreground">{title}</h2>
      <p className="mb-5 mt-1 text-sm text-muted">{hint}</p>
      <div className="space-y-4">{children}</div>
    </Card>
  );
}

const selectClass =
  "w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-foreground focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent disabled:opacity-60";

function LogoField({
  organization,
  canEdit,
  onUploaded,
}: {
  organization: Organization;
  canEdit: boolean;
  onUploaded: (org: Organization) => void;
}) {
  const inputRef = useRef<HTMLInputElement>(null);
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function upload(e: ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    e.target.value = "";
    if (!file) return;
    setError(null);
    setUploading(true);
    try {
      const body = new FormData();
      body.append("logo", file);
      const res = await api.post<{ organization: Organization }>(`/orgs/${organization.slug}/logo`, body);
      onUploaded(res.organization);
    } catch (err) {
      setError(err instanceof ApiError ? (err.fieldError("logo") ?? err.message) : "Could not upload the logo.");
    } finally {
      setUploading(false);
    }
  }

  async function remove() {
    setError(null);
    try {
      const res = await api.put<{ organization: Organization }>(`/orgs/${organization.slug}`, { logo_url: null });
      onUploaded(res.organization);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not remove the logo.");
    }
  }

  return (
    <div>
      <p className="mb-1.5 text-sm font-medium text-foreground">Logo</p>
      <div className="flex items-center gap-4">
        {organization.logo_url ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={organization.logo_url} alt="" className="h-16 w-16 rounded-xl border border-border object-cover" />
        ) : (
          <div className="flex h-16 w-16 items-center justify-center rounded-xl bg-surface-hover text-lg font-semibold text-muted">
            {organization.name.slice(0, 2).toUpperCase()}
          </div>
        )}
        {canEdit && (
          <div className="flex flex-wrap items-center gap-2">
            <input ref={inputRef} type="file" accept="image/png,image/jpeg,image/webp" className="hidden" onChange={upload} />
            <Button type="button" variant="secondary" loading={uploading} onClick={() => inputRef.current?.click()}>
              Upload logo
            </Button>
            {organization.logo_url && (
              <Button type="button" variant="ghost" onClick={remove}>
                Remove
              </Button>
            )}
            <p className="w-full text-xs text-muted">PNG, JPG or WebP, up to 2 MB. Square works best.</p>
          </div>
        )}
      </div>
      {error && <p className="mt-1.5 text-xs text-danger">{error}</p>}
    </div>
  );
}

function DangerZone({ organization, onError }: { organization: Organization; onError: (message: string) => void }) {
  const router = useRouter();
  const { refreshOrganizations } = useAuth();
  const [confirmName, setConfirmName] = useState("");
  const [deleting, setDeleting] = useState(false);

  async function destroy() {
    setDeleting(true);
    try {
      await api.delete(`/orgs/${organization.slug}`);
      clearOrgContext(organization.id);
      await refreshOrganizations();
      router.push("/dashboard");
    } catch (err) {
      onError(err instanceof ApiError ? err.message : "Could not delete the organization.");
      setDeleting(false);
    }
  }

  return (
    <Card className="mt-6 border-danger/30">
      <h2 className="text-lg font-semibold text-foreground">Danger zone</h2>

      <div className="mt-5 space-y-3">
        <div>
          <h3 className="text-sm font-semibold text-foreground">Delete organization</h3>
          <p className="mt-1 text-sm text-muted">
            Removes all members and pending invites, and cancels any paid subscription immediately, without a refund.
            This cannot be undone.
          </p>
        </div>
        <Field label={`Type "${organization.name}" to confirm`} htmlFor="confirm_name">
          <Input
            id="confirm_name"
            autoComplete="off"
            value={confirmName}
            onChange={(e) => setConfirmName(e.target.value)}
            className="max-w-xs"
          />
        </Field>
        <Button variant="danger" disabled={confirmName !== organization.name} loading={deleting} onClick={destroy}>
          Delete organization
        </Button>
      </div>
    </Card>
  );
}

function SettingsContent() {
  const { slug } = useParams<{ slug: string }>();
  const router = useRouter();
  const { refreshOrganizations } = useAuth();
  const [productSlug, setProductSlug] = useState("");
  const org = useOrg(slug, productSlug);
  const { organization, permissions, setOrganization } = org;

  const [form, setForm] = useState<FormState | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [saved, setSaved] = useState(false);

  const canEdit = permissions.update;
  const isOwner = permissions.manage_billing;

  useEffect(() => {
    if (organization) setForm(toForm(organization));
  }, [organization]);

  function set(key: FieldKey, value: string) {
    setSaved(false);
    setForm((f) => (f ? { ...f, [key]: value } : f));
  }

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    if (!form || !organization) return;
    setError(null);
    setSaved(false);
    setSubmitting(true);

    try {
      const body = Object.fromEntries(FIELDS.map((key) => [key, form[key].trim() || null]));
      const res = await api.put<{ organization: Organization }>(`/orgs/${slug}`, body);
      setOrganization(res.organization);
      await refreshOrganizations();
      if (res.organization.slug !== slug) router.replace(`/orgs/${res.organization.slug}/settings`);
      setSaved(true);
    } catch (err) {
      if (err instanceof ApiError) setError(err);
    } finally {
      setSubmitting(false);
    }
  }

  if (!organization || !form) return null;

  const fieldProps = (key: FieldKey) => ({
    id: key,
    disabled: !canEdit,
    value: form[key],
    onChange: (e: ChangeEvent<HTMLInputElement>) => set(key, e.target.value),
  });
  const colorValid = HEX.test(form.brand_color);

  return (
    <div className="min-h-screen">
      <TopNav />
      <main className="mx-auto max-w-2xl px-4 py-10">
        <OrgTabs slug={slug} />

        {!canEdit && (
          <div className="mb-6">
            <Banner variant="info">You can view these settings. Only the owner and admins can change them.</Banner>
          </div>
        )}
        {actionError && (
          <div className="mb-6">
            <Banner>{actionError}</Banner>
          </div>
        )}

        <form onSubmit={onSubmit} noValidate>
          <Section title="Profile" hint="How your organization appears to members and in product reports.">
            <LogoField organization={organization} canEdit={canEdit} onUploaded={setOrganization} />
            <Field label="Organization name" htmlFor="name" error={error?.fieldError("name")}>
              <Input {...fieldProps("name")} required />
            </Field>
            <Field label="Website" htmlFor="website" error={error?.fieldError("website")}>
              <Input {...fieldProps("website")} type="url" inputMode="url" placeholder="https://example.ae" />
            </Field>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Contact email" htmlFor="contact_email" error={error?.fieldError("contact_email")}>
                <Input {...fieldProps("contact_email")} type="email" placeholder="hello@example.ae" />
              </Field>
              <Field label="Phone" htmlFor="phone" error={error?.fieldError("phone")}>
                <Input {...fieldProps("phone")} type="tel" placeholder="+971 4 123 4567" />
              </Field>
            </div>
            <Field label="Address" htmlFor="address" error={error?.fieldError("address")}>
              <Input {...fieldProps("address")} placeholder="Building, street, office" />
            </Field>
            <Field label="City" htmlFor="city" error={error?.fieldError("city")}>
              <Input {...fieldProps("city")} placeholder="Dubai" />
            </Field>
          </Section>

          <Section title="Dubai registration" hint="Optional. Shown on branded reports for regulated brokerages.">
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="RERA ORN" htmlFor="orn" error={error?.fieldError("orn")}>
                <Input {...fieldProps("orn")} inputMode="numeric" placeholder="e.g. 12345" />
              </Field>
              <Field label="Trade licence number" htmlFor="trade_license_number" error={error?.fieldError("trade_license_number")}>
                <Input {...fieldProps("trade_license_number")} placeholder="e.g. 987654" />
              </Field>
            </div>
            <p className="text-xs text-muted">The ORN is your office registration number with RERA. Each ORN can belong to one organization.</p>
          </Section>

          <Section title="Defaults" hint="Products use these for dates and branded reports.">
            <Field label="Timezone" htmlFor="timezone" error={error?.fieldError("timezone")}>
              <select
                id="timezone"
                disabled={!canEdit}
                value={form.timezone}
                onChange={(e) => set("timezone", e.target.value)}
                className={selectClass}
              >
                {timezones(form.timezone).map((tz) => (
                  <option key={tz} value={tz}>
                    {tz.replace(/_/g, " ")}
                  </option>
                ))}
              </select>
            </Field>
            <Field label="Brand colour" htmlFor="brand_color" error={error?.fieldError("brand_color")}>
              <div className="flex items-center gap-3">
                <input
                  type="color"
                  aria-label="Pick brand colour"
                  disabled={!canEdit}
                  value={colorValid ? form.brand_color : "#000000"}
                  onChange={(e) => set("brand_color", e.target.value.toUpperCase())}
                  className="h-10 w-12 shrink-0 cursor-pointer rounded-lg border border-border bg-surface p-1 disabled:cursor-not-allowed"
                />
                <Input {...fieldProps("brand_color")} placeholder="#10B981" maxLength={7} className="max-w-[9rem] font-mono" />
                {canEdit && form.brand_color && (
                  <Button type="button" variant="ghost" onClick={() => set("brand_color", "")}>
                    Clear
                  </Button>
                )}
              </div>
            </Field>
          </Section>

          {canEdit && (
            <div className="mt-6 flex items-center gap-3">
              <Button type="submit" loading={submitting}>
                Save changes
              </Button>
              {saved && <span className="text-sm text-muted">Changes saved.</span>}
              {error && !Object.keys(error.errors).length && <span className="text-sm text-danger">{error.message}</span>}
              {error && Object.keys(error.errors).length > 0 && (
                <span className="text-sm text-danger">Check the highlighted fields.</span>
              )}
            </div>
          )}
        </form>

        <Suspense fallback={null}>
          <PlanSeatsCard
            slug={slug}
            isOwner={isOwner}
            productSlug={productSlug}
            setProductSlug={setProductSlug}
            org={org}
          />
        </Suspense>

        {permissions.delete ? (
          <DangerZone organization={organization} onError={setActionError} />
        ) : (
          permissions.leave && (
            <Card className="mt-6">
              <h2 className="text-lg font-semibold text-foreground">Leave organization</h2>
              <p className="mb-4 mt-1 text-sm text-muted">
                You lose access to this organization&apos;s products. Only the owner can delete the organization.
              </p>
              <LeaveOrgButton organization={organization} onError={setActionError} />
            </Card>
          )
        )}
      </main>
    </div>
  );
}

export default function OrgSettingsPage() {
  return (
    <RequireAuth>
      <SettingsContent />
    </RequireAuth>
  );
}
