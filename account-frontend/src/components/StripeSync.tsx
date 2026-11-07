"use client";

import { ApiError } from "@/lib/api";
import type { StripeSyncStatus, StripeSyncSummary } from "@/lib/types";

const badgeBase = "inline-block rounded-full px-2 py-0.5 text-xs whitespace-nowrap";

const planBadges: Record<Exclude<StripeSyncStatus, "not_required">, { label: string; className: string }> = {
  synced: { label: "Synced", className: "bg-emerald-500/10 text-emerald-400" },
  out_of_sync: { label: "Out of sync", className: "bg-amber-500/10 text-amber-400" },
  not_synced: { label: "Not synced", className: "bg-danger/10 text-danger" },
};

/** Per-plan Stripe sync badge ("—" for free plans). */
export function StripeSyncBadge({ status, title }: { status: StripeSyncStatus; title?: string }) {
  if (status === "not_required") return <span className="text-muted">—</span>;
  const { label, className } = planBadges[status];
  return (
    <span className={`${badgeBase} ${className}`} title={title}>
      {label}
    </span>
  );
}

/** Product-level badge from `stripe_sync_summary`. */
export function StripeSummaryBadge({ summary }: { summary?: StripeSyncSummary }) {
  if (!summary || summary.status === "not_required") {
    return <span className={`${badgeBase} bg-muted/20 text-muted`}>No paid plans</span>;
  }
  if (summary.status === "synced") {
    return <span className={`${badgeBase} bg-emerald-500/10 text-emerald-400`}>Stripe synced</span>;
  }
  const pending = summary.out_of_sync + summary.not_synced;
  return (
    <span className={`${badgeBase} bg-amber-500/10 text-amber-400`}>
      {pending} plan{pending === 1 ? "" : "s"} to sync
    </span>
  );
}

/** One-line description of a product's sync state. */
export function describeSummary(summary?: StripeSyncSummary): string {
  if (!summary || summary.status === "not_required") return "No active paid plans: nothing to sync with Stripe.";
  if (summary.status === "synced") return `All ${summary.synced} active paid plan${summary.synced === 1 ? "" : "s"} are in sync with Stripe.`;
  const parts = [
    summary.not_synced && `${summary.not_synced} not synced`,
    summary.out_of_sync && `${summary.out_of_sync} out of sync`,
    summary.synced && `${summary.synced} synced`,
  ].filter(Boolean);
  return `Stripe: ${parts.join(", ")}.`;
}

export function stripeSyncErrorMessage(err: unknown): string {
  if (err instanceof ApiError) {
    if (err.code === "billing_unavailable") return "Stripe isn't configured: set STRIPE_SECRET.";
    if (err.code === "stripe_error") return "Stripe returned an error. Check the plan's Stripe IDs and try again.";
    return err.fieldError("billing_period") ?? err.message;
  }
  return "Could not sync with Stripe.";
}
