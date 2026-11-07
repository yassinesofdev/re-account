"use client";

import { useRouter, useSearchParams } from "next/navigation";
import { Suspense, useEffect, useState } from "react";
import { RequireAuth } from "@/components/RouteGuard";
import { TopNav } from "@/components/TopNav";
import { Card, LinkButton } from "@/components/ui";
import { api } from "@/lib/api";
import type { CheckoutSessionInfo } from "@/lib/types";

const POLL_INTERVAL_MS = 2000;
const MAX_TRIES = 15;

/** Page the checkout started from (`/profile` or the org settings page). */
function contextPath(context: CheckoutSessionInfo["context"] | undefined): string | null {
  if (!context) return null;
  if (context.type === "organization") return context.org_slug ? `/orgs/${context.org_slug}/settings` : null;
  return "/profile";
}

function ReturnContent() {
  const params = useSearchParams();
  const router = useRouter();
  const sessionId = params.get("session_id");
  const [stuck, setStuck] = useState(!sessionId);
  const [fallbackHref, setFallbackHref] = useState("/dashboard");

  useEffect(() => {
    if (!sessionId) return;

    let cancelled = false;
    let timer: ReturnType<typeof setTimeout> | undefined;
    let tries = 0;

    async function check() {
      tries += 1;
      try {
        const info = await api.get<CheckoutSessionInfo>(`/billing/checkout-sessions/${encodeURIComponent(sessionId!)}`);
        if (cancelled) return;

        const target = contextPath(info.context);
        if (target) setFallbackHref(target);

        if (info.status === "expired") {
          router.replace(`${target ?? "/profile"}?billing=canceled`);
          return;
        }

        const sub = info.subscription;
        if (info.status === "complete" && sub && sub.is_paid && sub.status !== "incomplete") {
          router.replace(`${target ?? "/profile"}?billing=success`);
          return;
        }
      } catch {
        // 404 / 5xx: retrying won't help for a foreign session, but a transient Stripe error might clear.
        if (cancelled) return;
      }

      if (tries >= MAX_TRIES) {
        setStuck(true);
        return;
      }
      timer = setTimeout(check, POLL_INTERVAL_MS);
    }

    check();

    return () => {
      cancelled = true;
      if (timer) clearTimeout(timer);
    };
  }, [sessionId, router]);

  return (
    <div className="min-h-screen">
      <TopNav />
      <main className="mx-auto max-w-md px-4 py-16">
        <Card className="space-y-4 text-center">
          {stuck ? (
            <>
              <h1 className="text-lg font-semibold text-foreground">Payment pending</h1>
              <p className="text-sm text-muted">We&apos;re still confirming your payment. It can take a minute.</p>
              <LinkButton href={fallbackHref}>Continue</LinkButton>
            </>
          ) : (
            <>
              <h1 className="text-lg font-semibold text-foreground">Confirming your payment…</h1>
              <p className="text-sm text-muted">This only takes a few seconds. Please don&apos;t close this page.</p>
            </>
          )}
        </Card>
      </main>
    </div>
  );
}

export default function BillingReturnPage() {
  return (
    <RequireAuth allowSuperAdmin>
      <Suspense fallback={null}>
        <ReturnContent />
      </Suspense>
    </RequireAuth>
  );
}
