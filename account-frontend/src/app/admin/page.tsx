"use client";

import { useEffect, useState } from "react";
import { LinkButton, Card } from "@/components/ui";
import { api } from "@/lib/api";
import type { Organization, Paginated, Plan, Product, User } from "@/lib/types";

export default function AdminOverviewPage() {
  const [userCount, setUserCount] = useState<number | null>(null);
  const [orgCount, setOrgCount] = useState<number | null>(null);
  const [productCount, setProductCount] = useState<number | null>(null);
  const [planCount, setPlanCount] = useState<number | null>(null);

  useEffect(() => {
    api.get<Paginated<User>>("/admin/users?page=1").then((res) => setUserCount(res.total));
    api.get<Paginated<Organization>>("/admin/orgs?page=1").then((res) => setOrgCount(res.total));
    api.get<{ products: Product[] }>("/admin/products").then((res) => setProductCount(res.products.length));
    api.get<{ plans: Plan[] }>("/admin/plans").then((res) => setPlanCount(res.plans.length));
  }, []);

  const stats = [
    { label: "Users", value: userCount, href: "/admin/users" },
    { label: "Organizations", value: orgCount, href: "/admin/orgs" },
    { label: "Products", value: productCount, href: "/admin/products" },
    { label: "Plans", value: planCount, href: "/admin/products" },
  ];

  return (
    <div>
      <h1 className="mb-6 text-xl font-semibold text-foreground">Platform overview</h1>
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {stats.map((stat) => (
          <Card key={stat.label}>
            <p className="text-sm text-muted">{stat.label}</p>
            <p className="mt-1 text-3xl font-semibold text-foreground">{stat.value ?? "—"}</p>
            <LinkButton href={stat.href} variant="secondary" className="mt-4 w-full">
              Manage {stat.label.toLowerCase()}
            </LinkButton>
          </Card>
        ))}
      </div>
    </div>
  );
}
