"use client";

import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { Suspense, useEffect, useState } from "react";
import { AuthShell } from "@/components/AuthShell";
import { Banner, LinkButton } from "@/components/ui";
import { api, ApiError } from "@/lib/api";

function VerifyEmailContent() {
  const params = useSearchParams();
  const token = params.get("token");
  const [status, setStatus] = useState<"pending" | "success" | "error">("pending");
  const [message, setMessage] = useState<string>("");

  useEffect(() => {
    if (!token) {
      setStatus("error");
      setMessage("This verification link is missing its token.");
      return;
    }

    api
      .post(`/auth/verify-email/${token}`, undefined, { skipAuthRedirect: true })
      .then(() => setStatus("success"))
      .catch((err) => {
        setStatus("error");
        setMessage(err instanceof ApiError ? err.message : "This verification link is invalid or has expired.");
      });
  }, [token]);

  return (
    <AuthShell title="Verify your email">
      {status === "pending" && <p className="text-center text-sm text-muted">Verifying your email…</p>}
      {status === "success" && (
        <div className="space-y-4">
          <Banner variant="success">Your email has been verified.</Banner>
          <LinkButton href="/dashboard" variant="primary" className="w-full">
            Go to dashboard
          </LinkButton>
        </div>
      )}
      {status === "error" && (
        <div className="space-y-4">
          <Banner>{message}</Banner>
          <p className="text-center text-sm text-muted">
            <Link href="/dashboard" className="text-foreground hover:underline">
              Back to dashboard
            </Link>
          </p>
        </div>
      )}
    </AuthShell>
  );
}

export default function VerifyEmailPage() {
  return (
    <Suspense fallback={null}>
      <VerifyEmailContent />
    </Suspense>
  );
}
