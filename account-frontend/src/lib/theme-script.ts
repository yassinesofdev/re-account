// Shared by the root layout (server) and lib/theme.ts (client), so it holds no hooks.
export const THEME_KEY = "reaccount:theme";

// Runs in <head> before first paint: applies a stored Light/Dark choice.
// No stored value = "System" (no data-theme; globals.css follows the OS).
export const THEME_INIT_SCRIPT = `(function(){try{var t=localStorage.getItem(${JSON.stringify(THEME_KEY)});if(t==="light"||t==="dark")document.documentElement.setAttribute("data-theme",t)}catch(e){}})()`;
