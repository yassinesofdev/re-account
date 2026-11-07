"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useCallback, useEffect, useState, type FormEvent } from "react";
import { StripeSyncBadge, describeSummary, stripeSyncErrorMessage } from "@/components/StripeSync";
import { Banner, Button, Card, Field, Input } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { formatPrice, formatSeatPrice } from "@/lib/billing";
import type { Plan, PlanScope, Product } from "@/lib/types";

function slugify(value: string) {
  return value
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/(^-|-$)/g, "");
}

type FormState = {
  name: string;
  slug: string;
  scope: PlanScope;
  priceDollars: string;
  billingPeriod: string;
  stripePriceId: string;
};

function isPaidForm(form: FormState) {
  return Number(form.priceDollars || "0") > 0;
}

const emptyForm: FormState = { name: "", slug: "", scope: "personal", priceDollars: "0", billingPeriod: "", stripePriceId: "" };

function planToForm(plan: Plan): FormState {
  return {
    name: plan.name,
    slug: plan.slug,
    scope: plan.scope,
    priceDollars: (plan.price_cents / 100).toString(),
    billingPeriod: plan.billing_period ?? "",
    stripePriceId: plan.stripe_price_id ?? "",
  };
}

function PlanForm({
  initial,
  submitLabel,
  onCancel,
  onSubmit,
}: {
  initial: FormState;
  submitLabel: string;
  onCancel: () => void;
  onSubmit: (form: FormState) => Promise<void>;
}) {
  const [form, setForm] = useState(initial);
  const [slugEdited, setSlugEdited] = useState(Boolean(initial.slug));
  const [error, setError] = useState<ApiError | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const paid = isPaidForm(form);
  const priceIdChanged = Boolean(initial.stripePriceId) && form.stripePriceId.trim() !== initial.stripePriceId;

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);

    try {
      await onSubmit(form);
    } catch (err) {
      if (err instanceof ApiError) setError(err);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Card>
      <form className="space-y-4" onSubmit={handleSubmit}>
        {error && !Object.keys(error.errors).length && <Banner>{error.message}</Banner>}

        <Field label="Plan name" htmlFor="pl-name" error={error?.fieldError("name")}>
          <Input
            id="pl-name"
            required
            value={form.name}
            onChange={(e) => {
              const name = e.target.value;
              setForm((f) => ({ ...f, name, slug: slugEdited ? f.slug : slugify(name) }));
            }}
          />
        </Field>

        <Field label="Slug" htmlFor="pl-slug" error={error?.fieldError("slug")}>
          <Input
            id="pl-slug"
            required
            value={form.slug}
            onChange={(e) => {
              setSlugEdited(true);
              setForm((f) => ({ ...f, slug: slugify(e.target.value) }));
            }}
          />
        </Field>

        <Field label="Scope" htmlFor="pl-scope" error={error?.fieldError("scope")}>
          <select
            id="pl-scope"
            value={form.scope}
            onChange={(e) => setForm((f) => ({ ...f, scope: e.target.value as PlanScope }))}
            className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-foreground focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
          >
            <option value="personal">Personal</option>
            <option value="organization">Organization</option>
          </select>
        </Field>

        <Field
          label={form.scope === "organization" ? "Price per seat (USD)" : "Price (USD)"}
          htmlFor="pl-price"
          error={error?.fieldError("price_cents")}
        >
          <Input
            id="pl-price"
            type="number"
            min={0}
            step="0.01"
            value={form.priceDollars}
            onChange={(e) => setForm((f) => ({ ...f, priceDollars: e.target.value }))}
          />
        </Field>

        <Field label="Billing period" htmlFor="pl-period" error={error?.fieldError("billing_period")}>
          <select
            id="pl-period"
            required={paid}
            value={form.billingPeriod}
            onChange={(e) => setForm((f) => ({ ...f, billingPeriod: e.target.value }))}
            className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-foreground focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
          >
            <option value="">{paid ? "Select a period" : "None (free)"}</option>
            {/* Org plans are prepaid per quarter or year; monthly stays only for existing plans. */}
            {(form.scope !== "organization" || initial.billingPeriod === "month") && <option value="month">Monthly</option>}
            <option value="quarter">Quarterly</option>
            <option value="year">Yearly</option>
          </select>
          {form.scope === "organization" && (
            <p className="mt-1.5 text-xs text-muted">
              Organizations pay per seat, at least 2 seats per product, prepaid per quarter or year. All products of an
              organization share one billing date, so its plans should use the same period.
            </p>
          )}
        </Field>

        {paid && (
          <Field label="Stripe Price ID" htmlFor="pl-stripe-price" error={error?.fieldError("stripe_price_id")}>
            <Input
              id="pl-stripe-price"
              placeholder="price_…"
              pattern="price_.+"
              value={form.stripePriceId}
              onChange={(e) => setForm((f) => ({ ...f, stripePriceId: e.target.value }))}
            />
            <p className="mt-1.5 text-xs text-muted">
              Optional. Leave blank and use &ldquo;Sync&rdquo; to create the Stripe Product and Price, or paste an
              existing Price ID (price_…) matching the price, currency and billing period.
            </p>
            {priceIdChanged && (
              <p className="mt-1 text-xs text-danger">Existing subscribers stay on their current Stripe price.</p>
            )}
          </Field>
        )}

        <div className="flex gap-2">
          <Button type="submit" loading={submitting}>
            {submitLabel}
          </Button>
          <Button type="button" variant="ghost" onClick={onCancel}>
            Cancel
          </Button>
        </div>
      </form>
    </Card>
  );
}

export default function AdminProductPlansPage() {
  const { id } = useParams<{ id: string }>();
  const [product, setProduct] = useState<Product | null>(null);
  const [plans, setPlans] = useState<Plan[]>([]);
  const [creating, setCreating] = useState(false);
  const [editing, setEditing] = useState<Plan | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  /** Plan id being synced, or "all". */
  const [syncing, setSyncing] = useState<string | null>(null);

  const load = useCallback(async () => {
    const [{ product }, { plans }] = await Promise.all([
      api.get<{ product: Product }>(`/admin/products/${id}`),
      api.get<{ plans: Plan[] }>(`/admin/products/${id}/plans`),
    ]);
    setProduct(product);
    setPlans(plans);
  }, [id]);

  useEffect(() => {
    load();
  }, [load]);

  function payload(form: FormState) {
    const paid = isPaidForm(form);
    return {
      product_id: id,
      name: form.name,
      slug: form.slug,
      scope: form.scope,
      price_cents: Math.round(Number(form.priceDollars || "0") * 100),
      billing_period: form.billingPeriod || null,
      stripe_price_id: paid ? form.stripePriceId.trim() || null : null,
    };
  }

  async function createPlan(form: FormState) {
    await api.post("/admin/plans", payload(form));
    setCreating(false);
    await load();
  }

  async function updatePlan(plan: Plan, form: FormState) {
    await api.put(`/admin/plans/${plan.id}`, payload(form));
    setEditing(null);
    await load();
  }

  async function toggleActive(plan: Plan) {
    try {
      await api.put(`/admin/plans/${plan.id}`, { is_active: !plan.is_active });
      await load();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not update plan.");
    }
  }

  async function syncPlan(plan: Plan) {
    setError(null);
    setNotice(null);
    setSyncing(plan.id);
    try {
      await api.post<{ plan: Plan }>(`/admin/plans/${plan.id}/stripe-sync`);
      setNotice(`"${plan.name}" is in sync with Stripe.`);
      await load();
    } catch (err) {
      setError(stripeSyncErrorMessage(err));
    } finally {
      setSyncing(null);
    }
  }

  async function syncAll() {
    setError(null);
    setNotice(null);
    setSyncing("all");
    try {
      await api.post<{ plans: Plan[] }>(`/admin/products/${id}/stripe-sync`);
      setNotice("All active paid plans are in sync with Stripe.");
      await load();
    } catch (err) {
      setError(stripeSyncErrorMessage(err));
      await load();
    } finally {
      setSyncing(null);
    }
  }

  async function deletePlan(plan: Plan) {
    if (!confirm(`Delete "${plan.name}"? This cannot be undone.`)) return;
    setError(null);
    try {
      await api.delete(`/admin/plans/${plan.id}`);
      await load();
    } catch (err) {
      if (err instanceof ApiError && err.code === "plan_has_paid_subscribers") {
        setError(err.message || `"${plan.name}" has paying subscribers and can't be deleted. Deactivate it instead.`);
        return;
      }
      setError(err instanceof ApiError ? err.message : "Could not delete plan.");
    }
  }

  return (
    <div>
      <Link href="/admin/products" className="mb-2 inline-block text-sm text-muted hover:text-foreground">
        ← Products
      </Link>

      <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-foreground">{product ? `${product.name} plans` : "Plans"}</h1>
          {product && <p className="mt-1 text-sm text-muted">{describeSummary(product.stripe_sync_summary)}</p>}
        </div>
        <div className="flex gap-2">
          {product?.stripe_sync_summary?.status === "needs_sync" && (
            <Button variant="secondary" loading={syncing === "all"} disabled={syncing !== null} onClick={syncAll}>
              Sync all with Stripe
            </Button>
          )}
          {!creating && !editing && <Button onClick={() => setCreating(true)}>New plan</Button>}
        </div>
      </div>

      {notice && (
        <div className="mb-4">
          <Banner variant="success">{notice}</Banner>
        </div>
      )}

      {error && (
        <div className="mb-4">
          <Banner>{error}</Banner>
        </div>
      )}

      {creating && (
        <div className="mb-6">
          <PlanForm initial={emptyForm} submitLabel="Create plan" onCancel={() => setCreating(false)} onSubmit={createPlan} />
        </div>
      )}

      {editing && (
        <div className="mb-6">
          <PlanForm
            initial={planToForm(editing)}
            submitLabel="Save changes"
            onCancel={() => setEditing(null)}
            onSubmit={(form) => updatePlan(editing, form)}
          />
        </div>
      )}

      <Card className="p-0 overflow-hidden">
        <table className="w-full text-sm">
          <thead className="border-b border-border text-left text-muted">
            <tr>
              <th className="px-4 py-2 font-medium">Name</th>
              <th className="px-4 py-2 font-medium">Scope</th>
              <th className="px-4 py-2 font-medium">Price</th>
              <th className="px-4 py-2 font-medium">Stripe</th>
              <th className="px-4 py-2 font-medium">Status</th>
              <th className="px-4 py-2" />
            </tr>
          </thead>
          <tbody>
            {plans.map((plan) => (
              <tr key={plan.id} className="border-b border-border last:border-0">
                <td className="px-4 py-3 text-foreground">{plan.name}</td>
                <td className="px-4 py-3 text-muted capitalize">{plan.scope}</td>
                <td className="px-4 py-3 text-muted tabular-nums">
                  {plan.scope === "organization"
                    ? formatSeatPrice(plan.price_cents, plan.billing_period)
                    : formatPrice(plan.price_cents, plan.billing_period)}
                </td>
                <td className="px-4 py-3">
                  <div className="flex items-center gap-2">
                    <StripeSyncBadge status={plan.stripe_sync_status} title={plan.stripe_price_id ?? undefined} />
                    {(plan.stripe_sync_status === "not_synced" || plan.stripe_sync_status === "out_of_sync") && (
                      <button
                        onClick={() => syncPlan(plan)}
                        disabled={syncing !== null}
                        className="text-xs text-accent hover:underline disabled:opacity-50"
                      >
                        {syncing === plan.id ? "Syncing…" : "Sync"}
                      </button>
                    )}
                  </div>
                </td>
                <td className="px-4 py-3">
                  <button
                    onClick={() => toggleActive(plan)}
                    className={`rounded-full px-2 py-0.5 text-xs ${
                      plan.is_active ? "bg-emerald-500/10 text-emerald-400" : "bg-muted/20 text-muted"
                    }`}
                  >
                    {plan.is_active ? "Active" : "Inactive"}
                  </button>
                </td>
                <td className="px-4 py-3 text-right">
                  <div className="flex justify-end gap-3">
                    <button onClick={() => setEditing(plan)} className="text-xs text-muted hover:text-foreground">
                      Edit
                    </button>
                    <button onClick={() => deletePlan(plan)} className="text-xs text-danger hover:underline">
                      Delete
                    </button>
                  </div>
                </td>
              </tr>
            ))}
            {plans.length === 0 && (
              <tr>
                <td colSpan={7} className="px-4 py-6 text-center text-muted">
                  No plans yet for this product.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </Card>
    </div>
  );
}
