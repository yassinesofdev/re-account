"use client";

import { useCallback, useEffect, useState } from "react";
import { api } from "./api";
import type { OrgPermissions, OrgRole, Organization, Subscription } from "./types";

type SubscriptionInfo = {
  subscription: Subscription | null;
  /** Assigned members + pending invites for this product. */
  seats_used: number;
  seats_assigned: number;
  pending_invites: number;
  /** Purchased seats (null = unlimited / no plan). */
  seat_limit: number | null;
  /** Per-seat org billing: at least this many seats per product. */
  min_seats: number;
  /** Free org plans: the global free-plan cap (null = no cap, i.e. paid plans). */
  max_seats: number | null;
  /** True when the org has a Stripe customer and the caller is the owner. */
  billing_portal_available: boolean;
};

const NO_PERMISSIONS: OrgPermissions = {
  view: false,
  update: false,
  manage_invites: false,
  manage_members: false,
  manage_product_access: false,
  manage_billing: false,
  delete: false,
  leave: false,
};

export function useOrg(slug: string, productSlug: string) {
  const [organization, setOrganization] = useState<Organization | null>(null);
  const [myRole, setMyRole] = useState<OrgRole | null>(null);
  const [permissions, setPermissions] = useState<OrgPermissions>(NO_PERMISSIONS);
  const [subscriptionInfo, setSubscriptionInfo] = useState<SubscriptionInfo | null>(null);
  const [loading, setLoading] = useState(true);
  const [notFound, setNotFound] = useState(false);

  const refreshSubscription = useCallback(async () => {
    if (!productSlug) return;
    const data = await api.get<SubscriptionInfo>(`/orgs/${slug}/subscription?product=${productSlug}`);
    setSubscriptionInfo(data);
  }, [slug, productSlug]);

  const refresh = useCallback(async () => {
    setLoading(true);
    try {
      const data = await api.get<{ organization: Organization; my_role: OrgRole; permissions: OrgPermissions }>(
        `/orgs/${slug}`,
      );
      setOrganization(data.organization);
      setMyRole(data.my_role);
      setPermissions(data.permissions ?? NO_PERMISSIONS);
      setNotFound(false);
    } catch {
      setNotFound(true);
    } finally {
      setLoading(false);
    }
  }, [slug]);

  useEffect(() => {
    refresh();
  }, [refresh]);

  useEffect(() => {
    refreshSubscription();
  }, [refreshSubscription]);

  return {
    organization,
    myRole,
    permissions,
    setOrganization,
    loading,
    notFound,
    refresh,
    subscription: subscriptionInfo?.subscription ?? null,
    plan: subscriptionInfo?.subscription?.plan ?? null,
    billingPortalAvailable: Boolean(subscriptionInfo?.billing_portal_available),
    seatsUsed: subscriptionInfo?.seats_used ?? 0,
    seatLimit: subscriptionInfo?.seat_limit ?? null,
    seatsAssigned: subscriptionInfo?.seats_assigned ?? 0,
    pendingInvites: subscriptionInfo?.pending_invites ?? 0,
    minSeats: subscriptionInfo?.min_seats ?? 2,
    maxSeats: subscriptionInfo?.max_seats ?? null,
    refreshSubscription,
  };
}
