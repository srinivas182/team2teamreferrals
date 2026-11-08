import tokens from "./src/data/tokens.json" with { type: "json" };
// Theme generated from design/tokens.json so design and code never drift.
const scale = (g) => Object.fromEntries(Object.entries(g).map(([k, v]) => [k, v.$value]));
const v = (name) => `rgb(var(--${name}) / <alpha-value>)`;
export default {
  content: ["./index.html", "./src/**/*.{ts,tsx}"],
  darkMode: ["variant", ['@media (prefers-color-scheme: dark) { &:not([data-theme="light"] *) }', '&:is([data-theme="dark"] *)']],
  theme: {
    extend: {
      colors: {
        cobalt: scale(tokens.color.cobalt), orange: scale(tokens.color.orange), navy: scale(tokens.color.navy),
        bg: v("bg"), surface: v("surface"), raised: v("raised"), line: v("line"), ink: v("ink"), muted: v("muted"), subtle: v("subtle"),
        primary: v("primary"), "primary-hover": v("primary-hover"), "on-primary": v("on-primary"),
        accent: v("accent"), "on-accent": v("on-accent"),
        hot: "#D7263D", warm: "#B45309", future: "#6D28D9",
        success: "#15803D", warning: "#A16207", danger: "#D7263D", info: "#1D6FD8",
      },
      fontFamily: { display: ["Inter", "system-ui", "sans-serif"], sans: ["Inter", "system-ui", "-apple-system", "Segoe UI", "sans-serif"] },
      borderRadius: { sm: "6px", md: "10px", lg: "14px", xl: "20px" },
      boxShadow: { sm: "0 1px 2px rgba(14,27,61,0.06)", md: "0 4px 12px rgba(14,27,61,0.08), 0 1px 2px rgba(14,27,61,0.04)", lg: "0 16px 40px rgba(14,27,61,0.14)" },
      maxWidth: { content: "1200px" },
      transitionTimingFunction: { standard: "cubic-bezier(0.2,0,0,1)" },
    },
  },
};
