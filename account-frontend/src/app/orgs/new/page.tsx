"use client";

import { useRouter } from "next/navigation";
import { useState, type FormEvent } from "react";
import { RequireAuth } from "@/components/RouteGuard";
import { TopNav } from "@/components/TopNav";
import { Banner, Button, Card, Field, Input } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth-context";
import { setActiveContext } from "@/lib/context-storage";
import type { Organization } from "@/lib/types";

function slugify(value: string) {
  return value
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, "-")
    .slice(0, 63) // the slug is a future subdomain: max 63 characters
    .replace(/(^-+|-+$)/g, "");
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

  function onNameChange(value: string) {
    setName(value);
    if (!slugEdited) setSlug(slugify(value));
  }

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);

    try {
      const { organization } = await api.post<{ organization: Organization }>("/orgs", {
        name,
        slug: slug || undefined,
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

            <Field label="Slug" htmlFor="slug" error={error?.fieldError("slug")}>
              <Input
                id="slug"
                required
                value={slug}
                onChange={(e) => {
                  setSlugEdited(true);
                  setSlug(slugify(e.target.value));
                }}
              />
            </Field>

            <Field label="Logo URL (optional)" htmlFor="logo_url" error={error?.fieldError("logo_url")}>
              <Input id="logo_url" type="url" value={logoUrl} onChange={(e) => setLogoUrl(e.target.value)} />
            </Field>

            <Button type="submit" className="w-full" loading={submitting}>
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
