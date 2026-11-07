"use client";

import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { Suspense, useState, type FormEvent } from "react";
import { AuthShell } from "@/components/AuthShell";
import { Banner, Button, Field, Input } from "@/components/ui";
import { api, ApiError } from "@/lib/api";

function ResetPasswordForm() {
  const router = useRouter();
  const params = useSearchParams();
  const token = params.get("token") ?? "";
  const [email, setEmail] = useState(params.get("email") ?? "");
  const [password, setPassword] = useState("");
  const [passwordConfirmation, setPasswordConfirmation] = useState("");
  const [error, setError] = useState<ApiError | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);

    try {
      await api.post("/auth/reset-password", {
        email,
        token,
        password,
        password_confirmation: passwordConfirmation,
      });
      router.replace("/login");
    } catch (err) {
      if (err instanceof ApiError) setError(err);
    } finally {
      setSubmitting(false);
    }
  }

  if (!token) {
    return (
      <AuthShell title="Reset your password">
        <Banner>This password reset link is missing its token. Please request a new one.</Banner>
        <p className="mt-6 text-center text-sm text-muted">
          <Link href="/forgot-password" className="text-foreground hover:underline">
            Request a new link
          </Link>
        </p>
      </AuthShell>
    );
  }

  return (
    <AuthShell title="Choose a new password">
      <form className="space-y-4" onSubmit={onSubmit}>
        {error?.fieldError("token") && <Banner>{error.fieldError("token")}</Banner>}
        {error && !Object.keys(error.errors).length && <Banner>{error.message}</Banner>}

        <Field label="Email" htmlFor="email">
          <Input
            id="email"
            type="email"
            required
            value={email}
            onChange={(e) => setEmail(e.target.value)}
          />
        </Field>

        <Field label="New password" htmlFor="password" error={error?.fieldError("password")}>
          <Input
            id="password"
            type="password"
            autoComplete="new-password"
            required
            minLength={8}
            value={password}
            onChange={(e) => setPassword(e.target.value)}
          />
        </Field>

        <Field label="Confirm new password" htmlFor="password_confirmation">
          <Input
            id="password_confirmation"
            type="password"
            autoComplete="new-password"
            required
            minLength={8}
            value={passwordConfirmation}
            onChange={(e) => setPasswordConfirmation(e.target.value)}
          />
        </Field>

        <Button type="submit" className="w-full" loading={submitting}>
          Reset password
        </Button>
      </form>
    </AuthShell>
  );
}

export default function ResetPasswordPage() {
  return (
    <Suspense fallback={null}>
      <ResetPasswordForm />
    </Suspense>
  );
}
