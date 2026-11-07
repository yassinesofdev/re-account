"use client";

import { Suspense, useCallback, useEffect, useRef, useState, type ChangeEvent, type FormEvent } from "react";
import { BillingErrorBanner, BillingStatus } from "@/components/BillingStatus";
import { PlanPicker } from "@/components/PlanPicker";
import { ProductSelector } from "@/components/ProductSelector";
import { RequireAuth } from "@/components/RouteGuard";
import { TopNav } from "@/components/TopNav";
import { Banner, Button, Card, Field, Input } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth-context";
import { changePlan, hasBillingIssue, openPortal, planChangeNotice, useBillingFlag } from "@/lib/billing";
import type { Plan, Subscription, User } from "@/lib/types";

const TIMEZONES = Intl.supportedValuesOf
  ? ["UTC", ...Intl.supportedValuesOf("timeZone").filter((tz) => tz !== "UTC")]
  : ["UTC"];

function ProfileForm() {
  const { user, refresh } = useAuth();
  const [name, setName] = useState(user?.name ?? "");
  const [timezone, setTimezone] = useState(user?.timezone ?? "UTC");
  const [error, setError] = useState<ApiError | null>(null);
  const [saved, setSaved] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setSaved(false);
    setSubmitting(true);

    try {
      await api.put<{ user: User }>("/profile", { name, timezone });
      await refresh();
      setSaved(true);
    } catch (err) {
      if (err instanceof ApiError) setError(err);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Card>
      <h2 className="mb-4 text-lg font-semibold text-foreground">Profile</h2>
      <form className="space-y-4" onSubmit={onSubmit}>
        {error && !Object.keys(error.errors).length && <Banner>{error.message}</Banner>}
        {saved && <Banner variant="success">Profile updated.</Banner>}

        <Field label="Full name" htmlFor="name" error={error?.fieldError("name")}>
          <Input id="name" required value={name} onChange={(e) => setName(e.target.value)} />
        </Field>

        <Field label="Email" htmlFor="email">
          <Input id="email" value={user?.email ?? ""} disabled />
        </Field>

        <Field label="Timezone" htmlFor="timezone" error={error?.fieldError("timezone")}>
          <select
            id="timezone"
            value={timezone}
            onChange={(e) => setTimezone(e.target.value)}
            className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-foreground focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
          >
            {TIMEZONES.map((tz) => (
              <option key={tz} value={tz}>
                {tz}
              </option>
            ))}
          </select>
        </Field>

        <Button type="submit" loading={submitting}>
          Save changes
        </Button>
      </form>
    </Card>
  );
}

function AvatarCard() {
  const { user, refresh } = useAuth();
  const inputRef = useRef<HTMLInputElement>(null);
  const [error, setError] = useState<string | null>(null);
  const [uploading, setUploading] = useState(false);

  async function onFileChange(e: ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    if (!file) return;

    setError(null);
    setUploading(true);

    try {
      const formData = new FormData();
      formData.append("avatar", file);
      await api.post("/profile/avatar", formData);
      await refresh();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not upload avatar.");
    } finally {
      setUploading(false);
    }
  }

  return (
    <Card>
      <h2 className="mb-4 text-lg font-semibold text-foreground">Avatar</h2>
      {error && (
        <div className="mb-4">
          <Banner>{error}</Banner>
        </div>
      )}
      <div className="flex items-center gap-4">
        {user?.avatar_url ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={user.avatar_url} alt="" className="h-16 w-16 rounded-full object-cover" />
        ) : (
          <div className="flex h-16 w-16 items-center justify-center rounded-full bg-accent/20 text-lg font-semibold text-accent">
            {user?.name.slice(0, 2).toUpperCase()}
          </div>
        )}
        <div>
          <input
            ref={inputRef}
            type="file"
            accept="image/*"
            className="hidden"
            onChange={onFileChange}
          />
          <Button type="button" variant="secondary" loading={uploading} onClick={() => inputRef.current?.click()}>
            Upload new photo
          </Button>
        </div>
      </div>
    </Card>
  );
}

type ProfileSubscriptionInfo = { subscription: Subscription | null; billing_portal_available: boolean };

function PlanCard() {
  const [productSlug, setProductSlug] = useState("");
  const [subscription, setSubscription] = useState<Subscription | null>(null);
  const [portalAvailable, setPortalAvailable] = useState(false);
  const [switchingPlanId, setSwitchingPlanId] = useState<string | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const billingFlag = useBillingFlag("/profile");

  const loadSubscription = useCallback(async (slug: string) => {
    const res = await api.get<ProfileSubscriptionInfo>(`/profile/subscription?product=${slug}`);
    setSubscription(res.subscription);
    setPortalAvailable(Boolean(res.billing_portal_available));
  }, []);

  useEffect(() => {
    if (productSlug) loadSubscription(productSlug);
  }, [productSlug, loadSubscription]);

  async function portal() {
    setError(null);
    try {
      await openPortal("/profile/billing/portal");
    } catch (err) {
      if (err instanceof ApiError && err.code === "no_billing_account") {
        setPortalAvailable(false);
        return;
      }
      setError(err instanceof ApiError ? err : new ApiError(0, "Could not open billing."));
    }
  }

  async function switchPlan(plan: Plan) {
    setSwitchingPlanId(plan.id);
    setError(null);
    setNotice(null);

    try {
      const res = await changePlan("/profile/subscription", plan.id);
      setNotice(planChangeNotice(res.result, res.subscription, plan));
      await loadSubscription(productSlug);
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError(0, "Could not switch plan."));
    } finally {
      setSwitchingPlanId(null);
    }
  }

  async function resume() {
    if (subscription) await switchPlan(subscription.plan);
  }

  const issue = hasBillingIssue(subscription?.status);

  return (
    <Card>
      <div className="mb-3 flex items-center justify-between">
        <h2 className="text-lg font-semibold text-foreground">Plan</h2>
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
        {error && <BillingErrorBanner error={error} onPortal={portal} />}

        <BillingStatus
          subscription={subscription}
          portalAvailable={portalAvailable}
          canManage
          onPortal={portal}
          onResume={resume}
        />
      </div>

      <PlanPicker
        productSlug={productSlug}
        scope="personal"
        subscription={subscription}
        onSelect={switchPlan}
        switchingPlanId={switchingPlanId}
        disabledReason={issue ? "Update your payment method before changing plans." : undefined}
      />
    </Card>
  );
}

function ChangePasswordCard() {
  const [currentPassword, setCurrentPassword] = useState("");
  const [password, setPassword] = useState("");
  const [passwordConfirmation, setPasswordConfirmation] = useState("");
  const [error, setError] = useState<ApiError | null>(null);
  const [saved, setSaved] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setSaved(false);
    setSubmitting(true);

    try {
      await api.put("/profile", {
        current_password: currentPassword,
        password,
        password_confirmation: passwordConfirmation,
      });
      setCurrentPassword("");
      setPassword("");
      setPasswordConfirmation("");
      setSaved(true);
    } catch (err) {
      if (err instanceof ApiError) setError(err);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Card>
      <h2 className="mb-4 text-lg font-semibold text-foreground">Change password</h2>
      <form className="space-y-4" onSubmit={onSubmit}>
        {error && !Object.keys(error.errors).length && <Banner>{error.message}</Banner>}
        {saved && <Banner variant="success">Password updated.</Banner>}

        <Field label="Current password" htmlFor="current_password" error={error?.fieldError("current_password")}>
          <Input
            id="current_password"
            type="password"
            required
            value={currentPassword}
            onChange={(e) => setCurrentPassword(e.target.value)}
          />
        </Field>

        <Field label="New password" htmlFor="new_password" error={error?.fieldError("password")}>
          <Input
            id="new_password"
            type="password"
            required
            minLength={8}
            value={password}
            onChange={(e) => setPassword(e.target.value)}
          />
        </Field>

        <Field label="Confirm new password" htmlFor="new_password_confirmation">
          <Input
            id="new_password_confirmation"
            type="password"
            required
            minLength={8}
            value={passwordConfirmation}
            onChange={(e) => setPasswordConfirmation(e.target.value)}
          />
        </Field>

        <Button type="submit" loading={submitting}>
          Update password
        </Button>
      </form>
    </Card>
  );
}

function ProfileContent() {
  return (
    <div className="min-h-screen">
      <TopNav />
      <main className="mx-auto max-w-2xl space-y-6 px-4 py-10">
        <h1 className="text-xl font-semibold text-foreground">Account settings</h1>
        <AvatarCard />
        <Suspense fallback={null}>
          <PlanCard />
        </Suspense>
        <ProfileForm />
        <ChangePasswordCard />
      </main>
    </div>
  );
}

export default function ProfilePage() {
  return (
    <RequireAuth allowSuperAdmin>
      <ProfileContent />
    </RequireAuth>
  );
}
