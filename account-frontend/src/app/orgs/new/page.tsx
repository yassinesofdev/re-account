"use client";

import { useRouter } from "next/navigation";
import { useEffect, useState, type FormEvent } from "react";
import { RequireAuth } from "@/components/RouteGuard";
import { TopNav } from "@/components/TopNav";
import { Banner, Button, Card, Field, Input } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth-context";
import { setActiveContext } from "@/lib/context-storage";
import { TENANT_DOMAIN, orgUrl, slugify, toSlugInput, type SlugAvailability } from "@/lib/tenant";
import type { Organization } from "@/lib/types";

type Check = { state: "idle" } | { state: "checking" } | { state: "done"; result: SlugAvailability } | { state: "error" };

/** Debounced GET /orgs/slug-availability; stale answers are dropped. */
function useSlugAvailability(slug: string): Check {
  const [check, setCheck] = useState<Check>({ state: "idle" });

  useEffect(() => {
    if (!slug) {
      setCheck({ state: "idle" });
      return;
    }
    let cancelled = false;
    setCheck({ state: "checking" });
    const timer = setTimeout(async () => {
      try {
        const result = await api.get<SlugAvailability>(`/orgs/slug-availability?slug=${encodeURIComponent(slug)}`);
        if (!cancelled) setCheck({ state: "done", result });
      } catch {
        if (!cancelled) setCheck({ state: "error" });
      }
    }, 350);
    return () => {
      cancelled = true;
      clearTimeout(timer);
    };
  }, [slug]);

  return check;
}

const REASONS: Record<NonNullable<SlugAvailability["reason"]>, string> = {
  taken: "is already taken",
  reserved: "is reserved",
  invalid: "isn't valid: use letters, numbers and hyphens, starting and ending with a letter or number",
};

function AvailabilityNote({ check, onUse }: { check: Check; onUse: (slug: string) => void }) {
  if (check.state === "idle") return null;
  if (check.state === "checking") return <p className="mt-1.5 text-xs text-muted">Checking availability…</p>;
  if (check.state === "error") return <p className="mt-1.5 text-xs text-muted">Couldn&apos;t check availability right now.</p>;

  const { result } = check;
  if (result.available) {
    return <p className="mt-1.5 text-xs text-emerald-500">✓ {result.slug} is available</p>;
  }

  return (
    <p className="mt-1.5 text-xs text-danger">
      ✕ {result.reason === "invalid" ? "This address" : `"${result.slug}"`} {REASONS[result.reason ?? "invalid"]}.
      {result.suggestion && (
        <>
          {" "}
          <button type="button" className="font-medium text-accent hover:underline" onClick={() => onUse(result.suggestion!)}>
            Use {result.suggestion}
          </button>
        </>
      )}
    </p>
  );
}

function NewOrgContent() {
  const router = useRouter();
  const { refreshOrganizations } = useAuth();

  const [name, setName] = useState("");
  const [slug, setSlug] = useState("");
  const [slugEdited, setSlugEdited] = useState(false);
  const [logoUrl, setLogoUrl] = useState("");
  const [error, setError] = useState<ApiError | null>(null);
  const [submitting, setSubmitting] = useState(false);

  // Trailing hyphens are allowed while typing, dropped on check/submit.
  const cleanSlug = slug.replace(/-+$/, "");
  const check = useSlugAvailability(cleanSlug);
  const unavailable = check.state === "done" && !check.result.available;

  function onNameChange(value: string) {
    setName(value);
    if (!slugEdited) setSlug(slugify(value));
  }

  function applySuggestion(value: string) {
    setSlugEdited(true);
    setSlug(value);
  }

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);

    try {
      const { organization } = await api.post<{ organization: Organization }>("/orgs", {
        name,
        slug: cleanSlug || undefined,
        logo_url: logoUrl || undefined,
      });
      await refreshOrganizations();
      setActiveContext({ type: "org", orgId: organization.id, slug: organization.slug, name: organization.name });
      router.push(`/orgs/${organization.slug}`);
    } catch (err) {
      if (err instanceof ApiError) setError(err);
    } finally {
      setSubmitting(false);
    }
  }

  const slugError = error?.fieldError("slug");

  return (
    <div className="min-h-screen">
      <TopNav />
      <main className="mx-auto max-w-lg px-4 py-10">
        <h1 className="mb-6 text-xl font-semibold text-foreground">Create an organization</h1>
        <Card>
          <form className="space-y-4" onSubmit={onSubmit}>
            {error && !Object.keys(error.errors).length && <Banner>{error.message}</Banner>}

            <Field label="Organization name" htmlFor="name" error={error?.fieldError("name")}>
              <Input id="name" required value={name} onChange={(e) => onNameChange(e.target.value)} />
            </Field>

            <Field label="Your address" htmlFor="slug" error={slugError}>
              <div className="flex items-stretch overflow-hidden rounded-lg border border-border bg-surface focus-within:border-accent focus-within:ring-1 focus-within:ring-accent">
                <input
                  id="slug"
                  required
                  value={slug}
                  maxLength={63}
                  autoCapitalize="none"
                  autoCorrect="off"
                  spellCheck={false}
                  placeholder="acme"
                  aria-describedby="slug-preview"
                  className="min-w-0 flex-1 bg-transparent px-3 py-2 text-right text-sm text-foreground placeholder:text-muted focus:outline-none"
                  onChange={(e) => {
                    setSlugEdited(true);
                    setSlug(toSlugInput(e.target.value));
                  }}
                />
                <span className="flex items-center border-l border-border bg-surface-hover px-3 text-sm text-muted">
                  .{TENANT_DOMAIN}
                </span>
              </div>
              {!slugError && <AvailabilityNote check={check} onUse={applySuggestion} />}
              <p id="slug-preview" className="mt-1.5 truncate text-xs text-muted">
                {cleanSlug ? (
                  <>
                    Your organization&apos;s address: <span className="font-medium text-foreground">{orgUrl(cleanSlug)}</span>
                  </>
                ) : (
                  "Lowercase letters, numbers and hyphens."
                )}
              </p>
            </Field>

            <Field label="Logo URL (optional)" htmlFor="logo_url" error={error?.fieldError("logo_url")}>
              <Input id="logo_url" type="url" value={logoUrl} onChange={(e) => setLogoUrl(e.target.value)} />
            </Field>

            <Button type="submit" className="w-full" loading={submitting} disabled={unavailable || !cleanSlug}>
              Create organization
            </Button>
          </form>
        </Card>
      </main>
    </div>
  );
}

export default function NewOrgPage() {
  return (
    <RequireAuth>
      <NewOrgContent />
    </RequireAuth>
  );
}
