"use client";

import { useEffect } from "react";
import { useProducts } from "@/lib/useProducts";

/**
 * Resolves which product's plan/seat context a page is looking at. Defaults
 * to the sole product silently (today there's only one) — only renders a
 * visible picker once a second product exists.
 */
export function ProductSelector({ value, onChange }: { value: string; onChange: (slug: string) => void }) {
  const { products } = useProducts();

  useEffect(() => {
    if (!value && products.length > 0) onChange(products[0].slug);
  }, [products, value, onChange]);

  if (products.length <= 1) return null;

  return (
    <select
      value={value}
      onChange={(e) => onChange(e.target.value)}
      className="rounded-lg border border-border bg-surface px-2.5 py-1.5 text-sm text-foreground focus:border-accent focus:outline-none"
    >
      {products.map((product) => (
        <option key={product.id} value={product.slug}>
          {product.name}
        </option>
      ))}
    </select>
  );
}
