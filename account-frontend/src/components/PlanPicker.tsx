"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { formatDate, formatPrice, formatSeatPrice, isPaidPlan } from "@/lib/billing";
import type { Plan, PlanScope, Subscription } from "@/lib/types";
import { Card } from "./ui";

type PlanAction = { label: string; enabled: boolean };

/** Button label for a plan card, in the precedence order from the billing plan. */
function actionFor(plan: Plan, subscription: Subscription | null): PlanAction {
  const isCurrent = plan.id === subscription?.plan_id;

  if (isCurrent && subscription?.cancel_at_period_end) return { label: "Keep plan", enabled: true };
  if (isCurrent) return { label: "Current plan", enabled: false };
  if (subscription?.next_plan && plan.id === subscription.next_plan.id) {
    return { label: `Starts ${formatDate(subscription.current_period_end)}`, enabled: false };
  }
  if (!plan.is_purchasable) return { label: "Unavailable", enabled: false };
  if (isPaidPlan(plan)) return { label: subscription?.is_paid ? "Switch" : "Upgrade", enabled: true };
  if (subscription?.is_paid) return { label: "Downgrade", enabled: true };
  return { label: "Switch", enabled: true };
}

export function PlanPicker({
  productSlug,
  scope,
  subscription,
  onSelect,
  disabled = false,
  disabledReason,
  switchingPlanId,
  confirmChanges = true,
}: {
  productSlug: string;
  scope: PlanScope;
  subscription: Subscription | null;
  onSelect: (plan: Plan) => void;
  disabled?: boolean;
  /** When set, every button is disabled and this is shown above the plans. */
  disabledReason?: string;
  switchingPlanId?: string | null;
  /** Ask confirm() before paid changes. Off when the caller shows its own preview (org seat panel). */
  confirmChanges?: boolean;
}) {
  const [plans, setPlans] = useState<Plan[]>([]);

  useEffect(() => {
    if (!productSlug) return;
    api.get<{ plans: Plan[] }>(`/plans?product=${productSlug}&scope=${scope}`).then((res) => setPlans(res.plans));
  }, [productSlug, scope]);

  if (!productSlug) return null;

  function select(plan: Plan) {
    const isCurrent = plan.id === subscription?.plan_id;

    if (confirmChanges && !isCurrent && subscription?.is_paid) {
      if (isPaidPlan(plan) && !confirm("You'll be charged the prorated difference now.")) return;
      if (
        !isPaidPlan(plan) &&
        !confirm(
          `Your ${subscription.plan.name} stays active until ${formatDate(subscription.current_period_end)}, then switches to ${plan.name}.`,
        )
      ) {
        return;
      }
    }

    onSelect(plan);
  }

  const anySwitching = Boolean(switchingPlanId);

  return (
    <div className="space-y-3">
      {disabledReason && <p className="text-sm text-muted">{disabledReason}</p>}
      <div className="grid gap-3 sm:grid-cols-2">
        {plans.map((plan) => {
          const isCurrent = plan.id === subscription?.plan_id;
          const isSwitching = switchingPlanId === plan.id;
          const action = actionFor(plan, subscription);
          const goesToCheckout = isPaidPlan(plan) && !subscription?.is_paid;
          const label = isSwitching ? (goesToCheckout ? "Redirecting…" : "Switching…") : action.label;
          const isDisabled = disabled || Boolean(disabledReason) || !action.enabled || anySwitching;

          return (
            <Card key={plan.id} className={`flex flex-col gap-1 ${isCurrent ? "border-accent" : ""}`}>
              <p className="font-medium text-foreground">{plan.name}</p>
              <p className="text-sm text-muted tabular-nums">
                {plan.scope === "organization"
                  ? formatSeatPrice(plan.price_cents, plan.billing_period)
                  : formatPrice(plan.price_cents, plan.billing_period)}
              </p>
              <button
                type="button"
                disabled={isDisabled}
                onClick={() => select(plan)}
                className={`mt-2 rounded-lg px-3 py-1.5 text-sm font-medium transition-colors disabled:cursor-not-allowed ${
                  action.enabled
                    ? "bg-accent text-white hover:bg-accent-hover disabled:opacity-50"
                    : "bg-surface-hover text-muted"
                }`}
              >
                {label}
              </button>
            </Card>
          );
        })}
      </div>
    </div>
  );
}
