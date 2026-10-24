import { useState } from "react";

/*
  Long lists show 25 rows first, then "Show 25 more" (round 12: a list of
  300 applications made the page 24,000 px tall). resetKey: when it changes
  (another tab, filter or search), the list starts at 25 again.
*/
export function usePaged(list, resetKey, size = 25) {
  const [state, setState] = useState({ key: resetKey, count: size });
  const count = state.key === resetKey ? state.count : size;

  return {
    visible: list.slice(0, count),
    total: list.length,
    remaining: Math.max(0, list.length - count),
    showMore: () => setState({ key: resetKey, count: count + size }),
    showAll: () => setState({ key: resetKey, count: list.length }),
    size,
  };
}
