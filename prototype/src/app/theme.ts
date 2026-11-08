import { useEffect, useState } from "react";
export type Theme = "system" | "light" | "dark";
const KEY = "t2t-theme";
const load = (): Theme => { try { return (localStorage.getItem(KEY) as Theme) || "system"; } catch { return "system"; } };
export function useTheme() {
  const [theme, setTheme] = useState<Theme>(load);
  useEffect(() => {
    const el = document.documentElement;
    if (theme === "system") el.removeAttribute("data-theme"); else el.setAttribute("data-theme", theme);
    try { localStorage.setItem(KEY, theme); } catch { /* storage unavailable */ }
  }, [theme]);
  const next = () => setTheme(t => (t === "system" ? "light" : t === "light" ? "dark" : "system"));
  return { theme, next };
}
