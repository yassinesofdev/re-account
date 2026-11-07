"use client";

import Link from "next/link";
import { useState } from "react";
import type { ApiError } from "@/lib/api";
import { formatDate, hasBillingIssue } from "@/lib/billing";
import type { Subscription, SubscriptionStatus } from "@/lib/types";
import { Banner, Button } from "./ui";

const STATUS_LABELS: Record<SubscriptionStatus, string> = {
  active: "Active",
  trialing: "Trial",
  past_due: "Past due",
  unpaid: "Unpaid",
  incomplete: "Incomplete",
  canceled: "Canceled",
};

function StatusBadge({ status }: { status: SubscriptionStatus }) {
  const ok = status === "active" || status === "trialing";
  const styles = ok
    ? "bg-emerald-500/10 text-emerald-400"
    : status === "canceled"
      ? "bg-muted/20 text-muted"
      : "bg-danger/10 text-danger";

  return <span className={`rounded-full px-2 py-0.5 text-xs ${styles}`}>{STATUS_LABELS[status]}</span>;
}

/** Calls an async action with a local busy flag (the action handles its own errors). */
function useBusy() {
  const [busy, setBusy] = useState(false);
  async function run(action: () => Promise<void>) {
    setBusy(true);
    try {
      await action();
    } finally {
      setBusy(false);
    }
  }
  return [busy, run] as const;
}

/** Plan name, status, renewal / end date, payment-failure banner and portal button. */
export function BillingStatus({
  subscription,
  portalAvailable,
  canManage,
  onPortal,
  onResume,
}: {
  subscription: Subscription | null;
  portalAvailable: boolean;
  canManage: boolean;
  onPortal: () => Promise<void>;
  onResume: () => Promise<void>;
}) {
  const [portalBusy, runPortal] = useBusy();
  const [resumeBusy, runResume] = useBusy();

  if (!subscription) return <p className="text-sm text-muted">No plan</p>;

  const { plan } = subscription;
  const issue = hasBillingIssue(subscription.status);

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center gap-2">
        <span className="text-sm font-medium text-foreground">{plan.name}</span>
        <StatusBadge status={subscription.status} />
        {subscription.is_paid && subscription.current_period_end && (
          <span className="text-sm text-muted">
            {subscription.cancel_at_period_end
              ? `Ends ${formatDate(subscription.current_period_end)}${subscription.next_plan ? ` → ${subscription.next_plan.name}` : ""}`
              : `Renews ${formatDate(subscription.current_period_end)}`}
          </span>
        )}
      </div>

      {issue && (
        <Banner>
          <div className="flex flex-wrap items-center justify-between gap-2">
            <span>Your last payment failed. Update your payment method to keep {plan.name}.</span>
            {canManage && (
              <Button variant="danger" loading={portalBusy} onClick={() => runPortal(onPortal)}>
                Update payment method
              </Button>
            )}
          </div>
        </Banner>
      )}

      {canManage && (subscription.cancel_at_period_end || (portalAvailable && !issue)) && (
        <div className="flex flex-wrap gap-2">
          {subscription.cancel_at_period_end && (
            <Button loading={resumeBusy} onClick={() => runResume(onResume)}>
              Keep {plan.name}
            </Button>
          )}
          {portalAvailable && !issue && (
            <Button variant="secondary" loading={portalBusy} onClick={() => runPortal(onPortal)}>
              Manage billing
            </Button>
          )}
        </div>
      )}
    </div>
  );
}

/**
 * Renders a plan-change / portal error by its billing `code`
 * (see the API contract in .project/plans/stripe.md).
 */
export function BillingErrorBanner({
  error,
  targetPlanName,
  membersHref,
  onPortal,
}: {
  error: ApiError;
  targetPlanName?: string;
  membersHref?: string;
  onPortal?: () => Promise<void>;
}) {
  const [busy, run] = useBusy();

  switch (error.code) {
    case "billing_issue":
    case "payment_failed":
      return (
        <Banner>
          <div className="flex flex-wrap items-center justify-between gap-2">
            <span>{error.message}</span>
            {onPortal && (
              <Button variant="danger" loading={busy} onClick={() => run(onPortal)}>
                {error.code === "billing_issue" ? "Update payment method" : "Manage billing"}
              </Button>
            )}
          </div>
        </Banner>
      );
    case "seats_over_limit":
      return (
        <Banner>
          {error.message ||
            `You have ${String(error.data.seats_used ?? "?")} seats in use; ${targetPlanName ?? "that plan"} allows ${String(error.data.seat_limit ?? "?")}.`}
          {membersHref && (
            <>
              {" "}
              <Link href={membersHref} className="underline">
                Go to members
              </Link>
            </>
          )}
        </Banner>
      );
    case "billing_unavailable":
      return <Banner variant="info">Payments aren&apos;t available right now. Try again later.</Banner>;
    case "stripe_error":
      return <Banner>{error.message || "Something went wrong with our payment provider."} Try again.</Banner>;
    default:
      return <Banner>{error.message}</Banner>;
  }
}
