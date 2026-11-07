"use client";

import { useRouter, useSearchParams } from "next/navigation";
import { useEffect, useState } from "react";
import { api } from "./api";
import type { OrgChangePreview, Plan, PlanChangeResult, Subscription, SubscriptionStatus } from "./types";

/**
 * Billing helpers. The backend decides whether a plan change needs payment
 * (`PUT …/subscription` answers with `result`); the frontend never re-implements
 * billing rules — it just follows the Checkout / Portal redirects.
 */

/**
 * `PUT {path} {plan_id}`. On `result === "checkout"` the browser is sent to
 * Stripe Checkout and the returned promise never resolves (the page unloads).
 */
export async function changePlan(
  path: string,
  planId: string,
  seats?: { quantity: number; proration_date?: number | null },
): Promise<PlanChangeResult> {
  const res = await api.put<PlanChangeResult>(path, { plan_id: planId, ...seats });

  if (res.result === "checkout" && res.checkout_url) {
    window.location.href = res.checkout_url;
    return new Promise<PlanChangeResult>(() => {});
  }

  return res;
}

/** `POST {path}` (a `…/billing/portal` endpoint), then go to the Stripe Customer Portal. */
export async function openPortal(path: string): Promise<void> {
  const { url } = await api.post<{ url: string }>(path);
  window.location.href = url;
  return new Promise<void>(() => {});
}

/** Org change preview: what `PUT /orgs/{slug}/subscription {plan_id, quantity}` would do and cost. */
export async function previewOrgChange(slug: string, planId: string, quantity: number): Promise<OrgChangePreview> {
  const res = await api.post<{ preview: OrgChangePreview }>(`/orgs/${slug}/subscription/preview`, {
    plan_id: planId,
    quantity,
  });
  return res.preview;
}

export function formatMoney(cents: number): string {
  const amount = (cents / 100).toLocaleString("en-US", {
    minimumFractionDigits: cents % 100 === 0 ? 0 : 2,
    maximumFractionDigits: 2,
  });
  return `$${amount}`;
}

export function formatPrice(cents: number, period: string | null): string {
  if (cents === 0) return "Free";
  return period ? `${formatMoney(cents)}/${period}` : formatMoney(cents);
}

/** Org plans are priced per seat: "$30 / seat / quarter". */
export function formatSeatPrice(cents: number, period: string | null): string {
  if (cents === 0) return "Free";
  return `${formatMoney(cents)} / seat${period ? ` / ${period}` : ""}`;
}

export function formatDate(iso: string | null | undefined): string {
  if (!iso) return "";
  return new Date(iso).toLocaleDateString(undefined, { year: "numeric", month: "short", day: "numeric" });
}

export function isPaidPlan(plan: Pick<Plan, "price_cents">): boolean {
  return plan.price_cents > 0;
}

/** Success message after a non-checkout plan change. */
export function planChangeNotice(
  result: PlanChangeResult["result"],
  subscription: Subscription | null,
  target: Plan,
): string {
  if (result === "scheduled") {
    const current = subscription?.plan.name ?? "Your plan";
    const until = subscription?.current_period_end ? ` until ${formatDate(subscription.current_period_end)}` : "";
    return `${current} stays active${until}, then switches to ${subscription?.next_plan?.name ?? target.name}.`;
  }
  if (result === "resumed") return `${target.name} will keep renewing.`;
  return `You're now on ${subscription?.plan.name ?? target.name}.`;
}

/** Statuses where the last payment failed and plan changes are blocked (409 `billing_issue`). */
export function hasBillingIssue(status: SubscriptionStatus | undefined): boolean {
  return status === "past_due" || status === "unpaid" || status === "incomplete";
}

/**
 * Reads `?billing=success|canceled` (set by /billing/return or Stripe's cancel URL),
 * remembers it, and strips it from the URL. Callers must sit inside `<Suspense>`.
 */
export function useBillingFlag(basePath: string): "success" | "canceled" | null {
  const params = useSearchParams();
  const router = useRouter();
  const [flag, setFlag] = useState<"success" | "canceled" | null>(null);

  useEffect(() => {
    const value = params.get("billing");
    if (value === "success" || value === "canceled") {
      setFlag(value);
      router.replace(basePath);
    }
  }, [params, router, basePath]);

  return flag;
}
