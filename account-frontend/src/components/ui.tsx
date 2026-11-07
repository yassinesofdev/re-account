"use client";

import Link from "next/link";
import type {
  AnchorHTMLAttributes,
  ButtonHTMLAttributes,
  HTMLAttributes,
  InputHTMLAttributes,
  LabelHTMLAttributes,
  ReactNode,
} from "react";

export function Button({
  className = "",
  variant = "primary",
  size = "md",
  loading = false,
  children,
  ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: "primary" | "secondary" | "danger" | "ghost";
  size?: "md" | "icon";
  loading?: boolean;
}) {
  const sizes = { md: "px-4 py-2", icon: "h-8 w-8" };
  const base = `inline-flex items-center justify-center gap-2 rounded-lg ${sizes[size]} text-sm font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed`;
  const variants: Record<string, string> = {
    primary: "bg-accent text-white hover:bg-accent-hover",
    secondary: "bg-surface border border-border text-foreground hover:bg-surface-hover",
    danger: "bg-danger/10 border border-danger/30 text-danger hover:bg-danger/20",
    ghost: "text-muted hover:text-foreground",
  };

  return (
    <button className={`${base} ${variants[variant]} ${className}`} disabled={loading || props.disabled} {...props}>
      {loading ? "..." : children}
    </button>
  );
}

export function LinkButton({
  className = "",
  variant = "secondary",
  children,
  ...props
}: AnchorHTMLAttributes<HTMLAnchorElement> & { href: string; variant?: "primary" | "secondary" | "danger" }) {
  const base = "inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-medium transition-colors";
  const variants: Record<string, string> = {
    primary: "bg-accent text-white hover:bg-accent-hover",
    secondary: "bg-surface border border-border text-foreground hover:bg-surface-hover",
    danger: "bg-danger/10 border border-danger/30 text-danger hover:bg-danger/20",
  };

  return (
    <Link className={`${base} ${variants[variant]} ${className}`} {...props}>
      {children}
    </Link>
  );
}

export function Input({ className = "", ...props }: InputHTMLAttributes<HTMLInputElement>) {
  return (
    <input
      className={`w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-foreground placeholder:text-muted focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent ${className}`}
      {...props}
    />
  );
}

export function Label({ className = "", ...props }: LabelHTMLAttributes<HTMLLabelElement>) {
  return <label className={`mb-1.5 block text-sm font-medium text-foreground ${className}`} {...props} />;
}

export function Field({
  label,
  htmlFor,
  error,
  children,
}: {
  label: string;
  htmlFor: string;
  error?: string;
  children: ReactNode;
}) {
  return (
    <div>
      <Label htmlFor={htmlFor}>{label}</Label>
      {children}
      {error && <p className="mt-1.5 text-xs text-danger">{error}</p>}
    </div>
  );
}

export function Card({
  className = "",
  children,
  ...props
}: { className?: string; children: ReactNode } & HTMLAttributes<HTMLDivElement>) {
  return (
    <div className={`rounded-2xl border border-border bg-surface p-6 ${className}`} {...props}>
      {children}
    </div>
  );
}

export function Banner({
  variant = "danger",
  children,
}: {
  variant?: "danger" | "success" | "info";
  children: ReactNode;
}) {
  const styles = {
    danger: "border-danger/30 bg-danger-bg text-danger",
    success: "border-emerald-500/30 bg-emerald-500/10 text-emerald-400",
    info: "border-border bg-surface-hover text-foreground",
  }[variant];

  return <div className={`rounded-lg border px-3 py-2 text-sm ${styles}`}>{children}</div>;
}
