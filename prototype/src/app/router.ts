import { useEffect, useState } from "react";
/** Plain-#name hash routing (the only form a published page passes through). */
export function useRoute(fallback = "home"): string {
  const read = () => (location.hash.replace(/^#/, "") || fallback);
  const [route, setRoute] = useState(read);
  useEffect(() => {
    const on = () => { setRoute(read()); window.scrollTo({ top: 0 }); };
    window.addEventListener("hashchange", on);
    return () => window.removeEventListener("hashchange", on);
  }, []);
  return route;
}
export const go = (name: string) => { location.hash = name; };
