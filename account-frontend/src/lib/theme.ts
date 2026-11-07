"use client";

import { useEffect, useState } from "react";
import { THEME_KEY } from "./theme-script";

// Manual theme choice. "system" = no data-theme attribute, so globals.css
// follows prefers-color-scheme. app/layout.tsx applies the stored value
// before first paint (THEME_INIT_SCRIPT in ./theme-script).
export type ThemePref = "system" | "light" | "dark";

const CHANGE_EVENT = "reaccount:theme-change";

export function getThemePref(): ThemePref {
  try {
    const t = localStorage.getItem(THEME_KEY);
    return t === "light" || t === "dark" ? t : "system";
  } catch {
    return "system";
  }
}

export function setThemePref(pref: ThemePref) {
  try {
    if (pref === "system") localStorage.removeItem(THEME_KEY);
    else localStorage.setItem(THEME_KEY, pref);
  } catch {
    // Storage blocked: the choice still applies for this page view.
  }

  if (pref === "system") document.documentElement.removeAttribute("data-theme");
  else document.documentElement.setAttribute("data-theme", pref);

  window.dispatchEvent(new Event(CHANGE_EVENT));
}

export function useThemePref(): [ThemePref, (pref: ThemePref) => void] {
  const [pref, setPref] = useState<ThemePref>("system");

  useEffect(() => {
    const sync = () => setPref(getThemePref());
    sync();
    window.addEventListener(CHANGE_EVENT, sync);
    window.addEventListener("storage", sync);
    return () => {
      window.removeEventListener(CHANGE_EVENT, sync);
      window.removeEventListener("storage", sync);
    };
  }, []);

  return [pref, setThemePref];
}
