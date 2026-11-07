"use client";

import { useSearchParams } from "next/navigation";
import { Suspense, useEffect, useState, type FormEvent } from "react";
import { AuthShell } from "@/components/AuthShell";
import { Banner, Button, Field, Input } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth-context";
import { getActiveContext, setActiveContext } from "@/lib/context-storage";

/** super_admin_only: only RE-Account super admins may sign in (e.g. re-data). */
type ProductInfo = { name: string; slug: string; super_admin_only?: boolean };
type HandoffInfo = { handoff_session: string; product: ProductInfo };
/** 403 no_product_access: the user is in the org but not assigned to this product. */
type NoAccess = { product: string; org: string };

function noAccessFrom(err: unknown, productName: string): NoAccess | null {
  if (!(err instanceof ApiError) || err.code !== "no_product_access") return null;
  const product = (err.data.product as { name?: string } | undefined)?.name ?? productName;
  const org = (err.data.organization as { name?: string } | undefined)?.name ?? "this organization";
  return { product, org };
}

/** 403 not_super_admin: the product (re-data) is for super admins only. */
function isNotSuperAdmin(err: unknown) {
  return err instanceof ApiError && err.code === "not_super_admin";
}

function AuthHandoffContent() {
  const params = useSearchParams();
  const product = params.get("product") ?? "";
  const redirect = params.get("redirect") ?? "";
  const { user, organizations, loading: authLoading, refresh } = useAuth();

  const [handoff, setHandoff] = useState<HandoffInfo | null>(null);
  const [handoffError, setHandoffError] = useState<string | null>(null);
  const [mode, setMode] = useState<"login" | "register">("login");

  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [passwordConfirmation, setPasswordConfirmation] = useState("");
  const [orgId, setOrgId] = useState<string>("");
  const [error, setError] = useState<ApiError | string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [switchingAccount, setSwitchingAccount] = useState(false);
  const [noAccess, setNoAccess] = useState<NoAccess | null>(null);
  const [notSuperAdmin, setNotSuperAdmin] = useState(false);

  useEffect(() => {
    if (!product || !redirect) {
      setHandoffError("This link is missing required product information.");
      return;
    }

    api
      .get<HandoffInfo>(`/auth/handoff?${new URLSearchParams({ product, redirect }).toString()}`, {
        skipAuthRedirect: true,
      })
      .then(setHandoff)
      .catch((err) => setHandoffError(err instanceof ApiError ? err.message : "Unable to start sign-in."));
  }, [product, redirect]);

  useEffect(() => {
    const active = getActiveContext();
    setOrgId(active.type === "org" ? active.orgId : "");
  }, []);

  function persistContext(nextOrgId: string) {
    setOrgId(nextOrgId);
    const org = organizations.find((o) => o.id === nextOrgId);
    setActiveContext(org ? { type: "org", orgId: org.id, slug: org.slug, name: org.name } : { type: "personal" });
  }

  async function completeForAuthenticatedUser() {
    if (!handoff) return;
    setSubmitting(true);
    setError(null);

    try {
      const { redirect_url } = await api.post<{ redirect_url: string }>("/auth/handoff/complete", {
        handoff_session: handoff.handoff_session,
        org_id: orgId || undefined,
      });
      window.location.href = redirect_url;
    } catch (err) {
      const denied = noAccessFrom(err, handoff.product.name);
      if (denied) setNoAccess(denied);
      else if (isNotSuperAdmin(err)) setNotSuperAdmin(true);
      else setError(err instanceof ApiError ? err : "Something went wrong. Please try again.");
      setSubmitting(false);
    }
  }

  /** After a no_product_access refusal: the session exists, continue as Personal. */
  async function continuePersonally() {
    if (!handoff) return;
    setSubmitting(true);
    setError(null);
    setActiveContext({ type: "personal" });
    setOrgId("");

    try {
      const { redirect_url } = await api.post<{ redirect_url: string }>("/auth/handoff/complete", {
        handoff_session: handoff.handoff_session,
      });
      window.location.href = redirect_url;
    } catch (err) {
      setNoAccess(null);
      setError(err instanceof ApiError ? err : "Something went wrong. Please try again.");
      setSubmitting(false);
    }
  }

  async function switchAccount() {
    setSwitchingAccount(true);
    setError(null);

    try {
      await api.post("/auth/logout");
      setNotSuperAdmin(false);
      await refresh();
    } catch (err) {
      setError(err instanceof ApiError ? err : "Could not sign out. Please try again.");
    } finally {
      setSwitchingAccount(false);
    }
  }

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    if (!handoff) return;
    setError(null);
    setSubmitting(true);

    const stored = getActiveContext();
    const body = {
      email,
      password,
      handoff_session: handoff.handoff_session,
      org_id: stored.type === "org" ? stored.orgId : undefined,
    };

    try {
      const { redirect_url } = await api.post<{ redirect_url: string }>(
        mode === "login" ? "/auth/login" : "/auth/register",
        mode === "login" ? body : { ...body, name, password_confirmation: passwordConfirmation },
      );
      window.location.href = redirect_url;
    } catch (err) {
      const denied = noAccessFrom(err, handoff.product.name);
      if (denied) {
        // Signed in now, just not into this org: offer Personal instead.
        setNoAccess(denied);
        refresh();
      } else if (isNotSuperAdmin(err)) {
        // Login: signed in now, but not allowed into this product.
        setNotSuperAdmin(true);
        refresh();
      } else {
        setError(err instanceof ApiError ? err : "Something went wrong. Please try again.");
      }
    } finally {
      setSubmitting(false);
    }
  }

  if (handoffError) {
    return (
      <AuthShell title="Sign-in link invalid">
        <Banner>{handoffError}</Banner>
      </AuthShell>
    );
  }

  if (!handoff || authLoading) {
    return (
      <AuthShell title="Loading…">
        <p className="text-center text-sm text-muted">Preparing sign-in…</p>
      </AuthShell>
    );
  }

  const errorMessage = error instanceof ApiError ? error.message : error;

  if (notSuperAdmin) {
    return (
      <AuthShell title={`${handoff.product.name} is restricted`} subtitle={user ? `Signed in as ${user.email}` : undefined}>
        <div className="space-y-4">
          <Banner variant="info">
            <span className="font-semibold">{handoff.product.name}</span> is only available to RE-Account super admins.
            Your account doesn&apos;t have super admin access.
          </Banner>
          {user && (
            <Button className="w-full" variant="secondary" loading={switchingAccount} onClick={switchAccount}>
              Sign in as a different account
            </Button>
          )}
        </div>
      </AuthShell>
    );
  }

  if (noAccess) {
    return (
      <AuthShell title={`Continue to ${handoff.product.name}`} subtitle={user ? `Signed in as ${user.email}` : undefined}>
        <div className="space-y-4">
          <Banner variant="info">
            You don&apos;t have access to <span className="font-semibold">{noAccess.product}</span> in{" "}
            <span className="font-semibold">{noAccess.org}</span>. Ask your organization admin.
          </Banner>
          <Button className="w-full" loading={submitting} onClick={continuePersonally}>
            Continue personally
          </Button>
          {user && (
            <button
              type="button"
              onClick={() => setNoAccess(null)}
              className="w-full text-center text-xs text-muted hover:text-foreground"
            >
              Choose another organization
            </button>
          )}
        </div>
      </AuthShell>
    );
  }

  if (user) {
    return (
      <AuthShell title={`Continue to ${handoff.product.name}`} subtitle={`Signed in as ${user.email}`}>
        <div className="space-y-4">
          {errorMessage && <Banner>{errorMessage}</Banner>}

          <Field label="Continue as" htmlFor="context">
            <select
              id="context"
              className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-foreground focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
              value={orgId}
              onChange={(e) => persistContext(e.target.value)}
            >
              <option value="">Personal</option>
              {organizations.map((org) => (
                <option key={org.id} value={org.id}>
                  {org.name}
                </option>
              ))}
            </select>
          </Field>

          <Button className="w-full" loading={submitting} onClick={completeForAuthenticatedUser}>
            Continue
          </Button>

          <button
            type="button"
            onClick={switchAccount}
            disabled={switchingAccount}
            className="w-full text-center text-xs text-muted hover:text-foreground disabled:opacity-50"
          >
            {switchingAccount ? "Signing out…" : `Not ${user.email}? Sign in as a different account`}
          </button>
        </div>
      </AuthShell>
    );
  }

  const superAdminOnly = Boolean(handoff.product.super_admin_only);

  return (
    <AuthShell title={`Sign in to access ${handoff.product.name}`}>
      {superAdminOnly ? (
        <p className="mb-4 text-center text-xs text-muted">Super admins only.</p>
      ) : (
        <div className="mb-4 flex rounded-lg border border-border p-1 text-sm">
          <button
            className={`flex-1 rounded-md py-1.5 ${mode === "login" ? "bg-surface-hover text-foreground" : "text-muted"}`}
            onClick={() => setMode("login")}
            type="button"
          >
            Sign in
          </button>
          <button
            className={`flex-1 rounded-md py-1.5 ${mode === "register" ? "bg-surface-hover text-foreground" : "text-muted"}`}
            onClick={() => setMode("register")}
            type="button"
          >
            Register
          </button>
        </div>
      )}

      <form className="space-y-4" onSubmit={onSubmit}>
        {errorMessage && <Banner>{errorMessage}</Banner>}

        {mode === "register" && (
          <Field label="Full name" htmlFor="name">
            <Input id="name" required value={name} onChange={(e) => setName(e.target.value)} />
          </Field>
        )}

        <Field label="Email" htmlFor="email">
          <Input
            id="email"
            type="email"
            required
            value={email}
            onChange={(e) => setEmail(e.target.value)}
          />
        </Field>

        <Field label="Password" htmlFor="password">
          <Input
            id="password"
            type="password"
            required
            minLength={mode === "register" ? 8 : undefined}
            value={password}
            onChange={(e) => setPassword(e.target.value)}
          />
        </Field>

        {mode === "register" && (
          <Field label="Confirm password" htmlFor="password_confirmation">
            <Input
              id="password_confirmation"
              type="password"
              required
              minLength={8}
              value={passwordConfirmation}
              onChange={(e) => setPasswordConfirmation(e.target.value)}
            />
          </Field>
        )}

        <Button type="submit" className="w-full" loading={submitting}>
          {mode === "login" ? "Sign in" : "Create account"} & continue
        </Button>
      </form>
    </AuthShell>
  );
}

export default function AuthHandoffPage() {
  return (
    <Suspense fallback={null}>
      <AuthHandoffContent />
    </Suspense>
  );
}
