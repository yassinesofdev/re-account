"use client";

import { useEffect, useState } from "react";
import { api } from "./api";
import type { Product } from "./types";

export function useProducts() {
  const [products, setProducts] = useState<Product[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api.get<{ products: Product[] }>("/products").then((res) => {
      setProducts(res.products);
      setLoading(false);
    });
  }, []);

  return { products, loading };
}
