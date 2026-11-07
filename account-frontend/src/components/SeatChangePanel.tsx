"use client";

import { useEffect, useState } from "react";
import { ApiError } from "@/lib/api";
import { changePlan, formatDate, formatMoney, formatSeatPrice, isPaidPlan, previewOrgChange } from "@/lib/billing";
import type { OrgChangePreview, Plan, PlanChangeResult } from "@/lib/types";
import { BillingErrorBanner } from "./BillingStatus";
import { Banner, Button } from "./ui";

export type SeatChangeMode = "plan" | "add" | "reduce";

const TITLES: Record<SeatChangeMode, string> = {
  plan: "Choose seats",
  add: "Add seats",
  reduce: "Reduce seats",
};

/** Confirm button label, from what the backend says the change is. */
function confirmLabel(preview: OrgChangePreview | null, plan: Plan): string {
  if (!preview) return "Confirm";
  switch (preview.action) {
    case "subscribe":
      return "Continue to payment";
    case "add_product":
    case "add_seats":
    case "change_plan":
      return preview.amount_due_now_cents > 0 ? `Pay ${formatMoney(preview.amount_due_now_cents)} now` : "Confirm";
    case "reduce_seats":
      return `Reduce from ${formatDate(preview.effective_at)}`;
    case "downgrade":
      return `Switch to ${plan.name} at renewal`;
    case "keep_seats":
      return `Keep ${preview.quantity} seats`;
    case "none":
      return "No change";
    default:
      return "Save";
  }
}

/** One line under the total explaining when money moves. */
function chargeNote(preview: OrgChangePreview): string {
  switch (preview.action) {
    case "subscribe":
      return `You pay ${formatMoney(preview.amount_due_now_cents)} today at checkout. Your billing date is set to today.`;
    case "add_product":
      return `Due today: ${formatMoney(preview.amount_due_now_cents)} (prorated to your billing date${
        preview.renews_at ? `, ${formatDate(preview.renews_at)}` : ""
      }). Same invoice as your other products from then on.`;
    case "add_seats":
      return `Due today: ${formatMoney(preview.amount_due_now_cents)}, prorated until ${formatDate(preview.renews_at)}.`;
    case "change_plan":
      return `Due today: ${formatMoney(preview.amount_due_now_cents)} (prorated difference).`;
    case "reduce_seats":
      return `You keep your current seats until ${formatDate(preview.effective_at)}. No refund for the current period.`;
    case "downgrade":
      return `Your paid plan stays active until ${formatDate(preview.effective_at)}.`;
    case "update_free":
      return "Free plan: no payment.";
    default:
      return "Nothing is charged.";
  }
}

/**
 * Seat picker with a live total and the backend's preview (Stripe's prorated
 * amount for added seats / co-termed products). Confirm sends the same
 * {plan_id, quantity, proration_date} to PUT /orgs/{slug}/subscription.
 */
export function SeatChangePanel({
  slug,
  plan,
  mode,
  initialQuantity,
  minQuantity,
  maxQuantity,
  seatsUsed,
  onDone,
  onCancel,
}: {
  slug: string;
  plan: Plan;
  mode: SeatChangeMode;
  initialQuantity: number;
  minQuantity: number;
  maxQuantity: number | null;
  seatsUsed: number;
  onDone: (result: PlanChangeResult, preview: OrgChangePreview | null) => void;
  onCancel: () => void;
}) {
  const [quantity, setQuantity] = useState(initialQuantity);
  const [preview, setPreview] = useState<OrgChangePreview | null>(null);
  const [previewError, setPreviewError] = useState<ApiError | null>(null);
  const [loadingPreview, setLoadingPreview] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);

  const clamp = (n: number) => Math.max(minQuantity, maxQuantity != null ? Math.min(maxQuantity, n) : n);
  const paid = isPaidPlan(plan);
  const liveTotal = quantity * plan.price_cents;

  useEffect(() => {
    let cancelled = false;
    setLoadingPreview(true);
    setPreviewError(null);
    const timer = setTimeout(async () => {
      try {
        const res = await previewOrgChange(slug, plan.id, quantity);
        if (!cancelled) setPreview(res);
      } catch (err) {
        if (!cancelled) {
          setPreview(null);
          setPreviewError(err instanceof ApiError ? err : new ApiError(0, "Could not load the price preview."));
        }
      } finally {
        if (!cancelled) setLoadingPreview(false);
      }
    }, 250);
    return () => {
      cancelled = true;
      clearTimeout(timer);
    };
  }, [slug, plan.id, quantity]);

  async function confirm() {
    setSubmitting(true);
    setError(null);
    try {
      const res = await changePlan(`/orgs/${slug}/subscription`, plan.id, {
        quantity,
        proration_date: preview?.proration_date ?? null,
      });
      onDone(res, preview);
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError(0, "Could not update seats."));
    } finally {
      setSubmitting(false);
    }
  }

  const atMin = quantity <= minQuantity;
  const atMax = maxQuantity != null && quantity >= maxQuantity;
  const blocked = !preview || preview.action === "none" || loadingPreview;

  return (
    <div className="space-y-4 rounded-2xl border border-border bg-surface p-5">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h3 className="font-semibold text-foreground">
          {TITLES[mode]} · {plan.name}
        </h3>
        <span className="text-sm text-muted tabular-nums">{formatSeatPrice(plan.price_cents, plan.billing_period)}</span>
      </div>

      <div className="flex flex-wrap items-center gap-4">
        <div className="inline-flex items-center rounded-full border border-border" role="group" aria-label="Seats">
          <button
            type="button"
            aria-label="Fewer seats"
            disabled={atMin}
            onClick={() => setQuantity((q) => clamp(q - 1))}
            className="h-9 w-9 rounded-full text-lg text-foreground transition-colors hover:bg-surface-hover disabled:opacity-30"
          >
            −
          </button>
          <input
            type="number"
            aria-label="Number of seats"
            value={quantity}
            min={minQuantity}
            max={maxQuantity ?? undefined}
            onChange={(e) => {
              const n = Number(e.target.value);
              if (Number.isFinite(n) && n > 0) setQuantity(clamp(Math.round(n)));
            }}
            className="w-14 bg-transparent text-center text-base font-semibold text-foreground tabular-nums focus:outline-none [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none"
          />
          <button
            type="button"
            aria-label="More seats"
            disabled={atMax}
            onClick={() => setQuantity((q) => clamp(q + 1))}
            className="h-9 w-9 rounded-full text-lg text-foreground transition-colors hover:bg-surface-hover disabled:opacity-30"
          >
            +
          </button>
        </div>
        <div>
          <p className="text-2xl font-semibold text-foreground tabular-nums">
            {paid ? formatMoney(liveTotal) : "Free"}
            {paid && plan.billing_period && <span className="text-sm font-normal text-muted"> / {plan.billing_period}</span>}
          </p>
          <p className="text-xs text-muted tabular-nums">
            {quantity} seats · {seatsUsed} in use · min {minQuantity}
            {maxQuantity != null ? ` · max ${maxQuantity}` : ""}
          </p>
        </div>
      </div>

      {mode === "reduce" && minQuantity > 2 && atMin && (
        <p className="text-sm text-muted">
          {seatsUsed} seats are in use (assigned members and pending invites). Free some up on the Members page to go lower.
        </p>
      )}

      <div className="min-h-[1.25rem] text-sm text-muted tabular-nums" aria-live="polite">
        {loadingPreview && !preview && "Calculating…"}
        {preview && !previewError && chargeNote(preview)}
      </div>

      {previewError && <PreviewError error={previewError} />}
      {error && <BillingErrorBanner error={error} targetPlanName={plan.name} membersHref={`/orgs/${slug}/members`} />}

      <div className="flex flex-wrap gap-2">
        <Button onClick={confirm} loading={submitting} disabled={blocked}>
          {confirmLabel(preview, plan)}
        </Button>
        <Button variant="ghost" onClick={onCancel}>
          Cancel
        </Button>
      </div>
    </div>
  );
}

function PreviewError({ error }: { error: ApiError }) {
  if (error.code === "billing_unavailable") {
    return (
      <Banner variant="info">
        Payments aren&apos;t available right now, so the prorated price can&apos;t be calculated. Try again later.
      </Banner>
    );
  }
  return <BillingErrorBanner error={error} />;
}
