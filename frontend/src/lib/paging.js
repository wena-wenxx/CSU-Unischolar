import { useState } from "react";

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
