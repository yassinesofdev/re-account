// Org slugs are subdomains: an org lives at `<slug>.<TENANT_DOMAIN>`.
// Baked in at build time (prod: re-es.xyz); local dev shows `acme.re.localsite`.
export const TENANT_DOMAIN = process.env.NEXT_PUBLIC_TENANT_DOMAIN || "re.localsite";

const SCHEME = /^localhost$|\.localhost$|\.localsite$|^127\./.test(TENANT_DOMAIN) ? "http" : "https";

/** `acme.re-es.xyz` */
export function orgHost(slug: string) {
  return `${slug}.${TENANT_DOMAIN}`;
}

/** `https://acme.re-es.xyz` (http for local dev domains) */
export function orgUrl(slug: string) {
  return `${SCHEME}://${orgHost(slug)}`;
}

/** Lowercase, DNS-safe label (max 63) as the user types; trailing hyphens kept while typing. */
export function toSlugInput(value: string) {
  return value
    .normalize("NFKD")
    .replace(/[\u0300-\u036f]/g, "") // é → e
    .toLowerCase()
    .replace(/[^a-z0-9-]+/g, "-")
    .replace(/-{2,}/g, "-")
    .replace(/^-+/, "")
    .slice(0, 63);
}

/** Final slug from a name: like toSlugInput, without leading/trailing hyphens. */
export function slugify(value: string) {
  return toSlugInput(value.trim()).replace(/-+$/, "");
}

export type SlugAvailability = {
  slug: string;
  available: boolean;
  reason: "taken" | "reserved" | "invalid" | null;
  suggestion: string | null;
};
