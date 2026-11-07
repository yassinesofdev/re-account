"use client";

import { useRouter } from "next/navigation";
import { useEffect, type ReactNode } from "react";
import { useAuth } from "@/lib/auth-context";
import { homeRouteFor } from "@/lib/routing";

export function RequireAuth({ children, allowSuperAdmin = false }: { children: ReactNode; allowSuperAdmin?: boolean }) {
  const { user, loading } = useAuth();
  const router = useRouter();
  const bounceAdmin = !allowSuperAdmin && user?.is_super_admin;

  useEffect(() => {
    if (loading) return;

    if (!user) {
      // Keep the query string so e.g. /billing/return?session_id=… survives a re-login.
      const next = typeof window !== "undefined" ? window.location.pathname + window.location.search : "/dashboard";
      router.replace(`/login?next=${encodeURIComponent(next)}`);
      return;
    }

    // Super admins manage the platform from /admin, not the end-user app —
    // except pages (like /profile) that are about the admin's own account.
    if (bounceAdmin) {
      router.replace("/admin");
    }
  }, [loading, user, bounceAdmin, router]);

  if (loading || !user || bounceAdmin) return null;

  return <>{children}</>;
}

export function RequireGuest({ children }: { children: ReactNode }) {
  const { user, loading } = useAuth();
  const router = useRouter();

  useEffect(() => {
    if (!loading && user) {
      router.replace(homeRouteFor(user));
    }
  }, [loading, user, router]);

  if (loading || user) return null;

  return <>{children}</>;
}

export function RequireSuperAdmin({ children }: { children: ReactNode }) {
  const { user, loading } = useAuth();
  const router = useRouter();

  useEffect(() => {
    if (loading) return;

    if (!user) {
      const next = typeof window !== "undefined" ? window.location.pathname : "/admin";
      router.replace(`/login?next=${encodeURIComponent(next)}`);
      return;
    }

    if (!user.is_super_admin) {
      router.replace("/dashboard");
    }
  }, [loading, user, router]);

  if (loading || !user || !user.is_super_admin) return null;

  return <>{children}</>;
}
