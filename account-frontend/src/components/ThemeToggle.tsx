"use client";

import { useThemePref, type ThemePref } from "@/lib/theme";
import { Button } from "./ui";

const ORDER: ThemePref[] = ["system", "light", "dark"];
const LABELS: Record<ThemePref, string> = { system: "System", light: "Light", dark: "Dark" };

function ThemeIcon({ pref }: { pref: ThemePref }) {
  const common = {
    width: 16,
    height: 16,
    viewBox: "0 0 24 24",
    fill: "none",
    stroke: "currentColor",
    strokeWidth: 1.75,
    strokeLinecap: "round" as const,
    strokeLinejoin: "round" as const,
    "aria-hidden": true,
  };

  if (pref === "light") {
    return (
      <svg {...common}>
        <circle cx="12" cy="12" r="4" />
        <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41" />
      </svg>
    );
  }

  if (pref === "dark") {
    return (
      <svg {...common}>
        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" />
      </svg>
    );
  }

  return (
    <svg {...common}>
      <rect x="2" y="3" width="20" height="14" rx="2" />
      <path d="M8 21h8M12 17v4" />
    </svg>
  );
}

/** Cycling icon button: System → Light → Dark. */
export function ThemeToggle({ className = "" }: { className?: string }) {
  const [pref, setPref] = useThemePref();
  const next = ORDER[(ORDER.indexOf(pref) + 1) % ORDER.length];
  const label = `Theme: ${LABELS[pref]}. Switch to ${LABELS[next]}`;

  return (
    <Button
      type="button"
      variant="ghost"
      size="icon"
      onClick={() => setPref(next)}
      aria-label={label}
      title={label}
      data-theme-pref={pref}
      className={`hover:bg-surface-hover ${className}`}
    >
      <ThemeIcon pref={pref} />
    </Button>
  );
}
