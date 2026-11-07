"use client";

import Link from "next/link";
import { useCallback, useEffect, useState, type FormEvent } from "react";
import { StripeSummaryBadge } from "@/components/StripeSync";
import { Banner, Button, Card, Field, Input } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import type { Product, ProductRole } from "@/lib/types";

function slugify(value: string) {
  return value
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/(^-|-$)/g, "");
}

type FormState = {
  name: string;
  slug: string;
  description: string;
  redirect_url: string;
  roles: ProductRole[];
  super_admin_only: boolean;
};

const emptyForm: FormState = { name: "", slug: "", description: "", redirect_url: "", roles: [], super_admin_only: false };

function roleKey(value: string) {
  return value
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9_-]+/g, "_")
    .replace(/^[^a-z]+/, "");
}

/** Edit the product's role list: key + display name, exactly one default. */
function RolesEditor({
  roles,
  error,
  onChange,
}: {
  roles: ProductRole[];
  error?: string;
  onChange: (roles: ProductRole[]) => void;
}) {
  function update(i: number, patch: Partial<ProductRole>) {
    onChange(roles.map((r, j) => (j === i ? { ...r, ...patch } : patch.default ? { ...r, default: false } : r)));
  }

  function remove(i: number) {
    const next = roles.filter((_, j) => j !== i);
    if (next.length && !next.some((r) => r.default)) next[0] = { ...next[0], default: true };
    onChange(next);
  }

  return (
    <fieldset>
      <legend className="mb-1.5 text-sm font-medium text-foreground">Roles</legend>
      <p className="mb-2 text-xs text-muted">
        Members get one role per product. New assignments use the default; org owners and admins get{" "}
        <code>manager</code> when it exists. Removing a role moves its members to the default.
      </p>
      <div className="space-y-2">
        {roles.map((role, i) => (
          <div key={i} className="flex items-center gap-2">
            <Input
              aria-label={`Role ${i + 1} name`}
              placeholder="Name"
              required
              value={role.name}
              onChange={(e) => update(i, { name: e.target.value })}
            />
            <Input
              aria-label={`Role ${i + 1} key`}
              placeholder="key"
              required
              className="font-mono"
              value={role.key}
              onChange={(e) => update(i, { key: roleKey(e.target.value) })}
            />
            <label className="flex shrink-0 items-center gap-1.5 text-xs text-muted">
              <input
                type="radio"
                name="default-role"
                checked={role.default}
                onChange={() => update(i, { default: true })}
                className="accent-foreground"
              />
              Default
            </label>
            <button type="button" onClick={() => remove(i)} className="shrink-0 text-xs text-danger hover:underline">
              Remove
            </button>
          </div>
        ))}
        {roles.length === 0 && <p className="text-xs text-muted">No roles: assignments carry no role.</p>}
      </div>
      {error && <p className="mt-1 text-xs text-danger">{error}</p>}
      <button
        type="button"
        onClick={() => onChange([...roles, { key: "", name: "", default: roles.length === 0 }])}
        className="mt-2 text-xs text-muted hover:text-foreground"
      >
        + Add role
      </button>
    </fieldset>
  );
}

function rolesError(error: ApiError | null) {
  if (!error) return undefined;
  const key = Object.keys(error.errors).find((k) => k.startsWith("roles"));
  return key ? error.errors[key]?.[0] : undefined;
}

function ProductForm({
  initial,
  submitLabel,
  onCancel,
  onSubmit,
}: {
  initial: FormState;
  submitLabel: string;
  onCancel: () => void;
  onSubmit: (form: FormState) => Promise<void>;
}) {
  const [form, setForm] = useState(initial);
  const [slugEdited, setSlugEdited] = useState(Boolean(initial.slug));
  const [error, setError] = useState<ApiError | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);

    try {
      await onSubmit(form);
    } catch (err) {
      if (err instanceof ApiError) setError(err);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Card>
      <form className="space-y-4" onSubmit={handleSubmit}>
        {error && !Object.keys(error.errors).length && <Banner>{error.message}</Banner>}

        <Field label="Product name" htmlFor="p-name" error={error?.fieldError("name")}>
          <Input
            id="p-name"
            required
            value={form.name}
            onChange={(e) => {
              const name = e.target.value;
              setForm((f) => ({ ...f, name, slug: slugEdited ? f.slug : slugify(name) }));
            }}
          />
        </Field>

        <Field label="Slug" htmlFor="p-slug" error={error?.fieldError("slug")}>
          <Input
            id="p-slug"
            required
            value={form.slug}
            onChange={(e) => {
              setSlugEdited(true);
              setForm((f) => ({ ...f, slug: slugify(e.target.value) }));
            }}
          />
        </Field>

        <Field label="Redirect URL" htmlFor="p-redirect" error={error?.fieldError("redirect_url")}>
          <Input
            id="p-redirect"
            type="url"
            required
            placeholder="https://app.example.com/auth/callback"
            value={form.redirect_url}
            onChange={(e) => setForm((f) => ({ ...f, redirect_url: e.target.value }))}
          />
        </Field>

        <Field label="Description (optional)" htmlFor="p-description">
          <Input
            id="p-description"
            value={form.description}
            onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))}
          />
        </Field>

        <RolesEditor roles={form.roles} error={rolesError(error)} onChange={(roles) => setForm((f) => ({ ...f, roles }))} />

        <label className="flex items-center gap-2 text-sm text-foreground">
          <input
            type="checkbox"
            checked={form.super_admin_only}
            onChange={(e) => setForm((f) => ({ ...f, super_admin_only: e.target.checked }))}
            className="h-4 w-4 rounded border-border accent-foreground"
          />
          Super admins only <span className="text-xs text-muted">(no plans or seats; others are refused at sign-in)</span>
        </label>

        <div className="flex gap-2">
          <Button type="submit" loading={submitting}>
            {submitLabel}
          </Button>
          <Button type="button" variant="ghost" onClick={onCancel}>
            Cancel
          </Button>
        </div>
      </form>
    </Card>
  );
}

export default function AdminProductsPage() {
  const [products, setProducts] = useState<Product[]>([]);
  const [creating, setCreating] = useState(false);
  const [editing, setEditing] = useState<Product | null>(null);
  const [newSecret, setNewSecret] = useState<{ slug: string; secret: string } | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    const { products } = await api.get<{ products: Product[] }>("/admin/products");
    setProducts(products);
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  async function createProduct(form: FormState) {
    const { product, secret_key } = await api.post<{ product: Product; secret_key: string }>("/admin/products", {
      name: form.name,
      slug: form.slug,
      redirect_url: form.redirect_url,
      description: form.description || undefined,
      roles: form.roles,
      super_admin_only: form.super_admin_only,
    });
    setCreating(false);
    setNewSecret({ slug: product.slug, secret: secret_key });
    await load();
  }

  async function updateProduct(product: Product, form: FormState) {
    await api.put(`/admin/products/${product.id}`, {
      name: form.name,
      slug: form.slug,
      redirect_url: form.redirect_url,
      description: form.description || null,
      roles: form.roles,
      super_admin_only: form.super_admin_only,
    });
    setEditing(null);
    await load();
  }

  async function toggleActive(product: Product) {
    try {
      await api.put(`/admin/products/${product.id}`, { is_active: !product.is_active });
      await load();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not update product.");
    }
  }

  async function deleteProduct(product: Product) {
    if (!confirm(`Delete "${product.name}"? Any product currently using this integration will stop working.`)) return;
    try {
      await api.delete(`/admin/products/${product.id}`);
      await load();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not delete product.");
    }
  }

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <h1 className="text-xl font-semibold text-foreground">Products</h1>
        {!creating && !editing && <Button onClick={() => setCreating(true)}>New product</Button>}
      </div>

      {error && (
        <div className="mb-4">
          <Banner>{error}</Banner>
        </div>
      )}

      {newSecret && (
        <div className="mb-4">
          <Banner variant="success">
            <p className="mb-1 font-medium">
              Secret key for &ldquo;{newSecret.slug}&rdquo; — copy it now, it won&apos;t be shown again:
            </p>
            <code className="block break-all rounded bg-black/20 px-2 py-1 text-xs">{newSecret.secret}</code>
            <button className="mt-2 text-xs underline" onClick={() => setNewSecret(null)}>
              Dismiss
            </button>
          </Banner>
        </div>
      )}

      {creating && (
        <div className="mb-6">
          <ProductForm initial={emptyForm} submitLabel="Create product" onCancel={() => setCreating(false)} onSubmit={createProduct} />
        </div>
      )}

      {editing && (
        <div className="mb-6">
          <ProductForm
            initial={{
              name: editing.name,
              slug: editing.slug,
              description: editing.description ?? "",
              redirect_url: editing.redirect_url,
              roles: editing.roles ?? [],
              super_admin_only: editing.super_admin_only,
            }}
            submitLabel="Save changes"
            onCancel={() => setEditing(null)}
            onSubmit={(form) => updateProduct(editing, form)}
          />
        </div>
      )}

      <Card className="p-0 overflow-hidden">
        <table className="w-full text-sm">
          <thead className="border-b border-border text-left text-muted">
            <tr>
              <th className="px-4 py-2 font-medium">Name</th>
              <th className="px-4 py-2 font-medium">Slug</th>
              <th className="px-4 py-2 font-medium">Redirect URL</th>
              <th className="px-4 py-2 font-medium">Roles</th>
              <th className="px-4 py-2 font-medium">Stripe</th>
              <th className="px-4 py-2 font-medium">Status</th>
              <th className="px-4 py-2" />
            </tr>
          </thead>
          <tbody>
            {products.map((product) => (
              <tr key={product.id} className="border-b border-border last:border-0">
                <td className="px-4 py-3 text-foreground">{product.name}</td>
                <td className="px-4 py-3 text-muted">{product.slug}</td>
                <td className="max-w-xs truncate px-4 py-3 text-muted">{product.redirect_url}</td>
                <td className="px-4 py-3 text-muted">
                  {product.super_admin_only ? (
                    <span className="rounded-full border border-border px-2 py-0.5 text-xs">Super admins only</span>
                  ) : product.roles?.length ? (
                    product.roles.map((r) => (r.default ? `${r.name} (default)` : r.name)).join(", ")
                  ) : (
                    "—"
                  )}
                </td>
                <td className="px-4 py-3">
                  <Link href={`/admin/products/${product.id}/plans`}>
                    <StripeSummaryBadge summary={product.stripe_sync_summary} />
                  </Link>
                </td>
                <td className="px-4 py-3">
                  <button
                    onClick={() => toggleActive(product)}
                    className={`rounded-full px-2 py-0.5 text-xs ${
                      product.is_active
                        ? "bg-emerald-500/10 text-emerald-400"
                        : "bg-muted/20 text-muted"
                    }`}
                  >
                    {product.is_active ? "Active" : "Inactive"}
                  </button>
                </td>
                <td className="px-4 py-3 text-right">
                  <div className="flex justify-end gap-3">
                    <Link href={`/admin/products/${product.id}/plans`} className="text-xs text-muted hover:text-foreground">
                      Plans
                    </Link>
                    <button onClick={() => setEditing(product)} className="text-xs text-muted hover:text-foreground">
                      Edit
                    </button>
                    <button onClick={() => deleteProduct(product)} className="text-xs text-danger hover:underline">
                      Delete
                    </button>
                  </div>
                </td>
              </tr>
            ))}
            {products.length === 0 && (
              <tr>
                <td colSpan={7} className="px-4 py-6 text-center text-muted">
                  No products registered yet.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </Card>
    </div>
  );
}
