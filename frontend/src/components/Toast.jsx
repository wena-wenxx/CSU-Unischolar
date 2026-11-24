import { useCallback, useRef, useState } from "react";
import { ToastContext } from "../lib/toast";
import Icon from "./Icon";

/*
  Small notification messages in the corner (replaces alert()).
  Pages use them with useToast() from lib/toast.js.
*/

const DURATION_MS = 4500;

export function ToastProvider({ children }) {
  const [toasts, setToasts] = useState([]);
  const nextId = useRef(1);

  // A closing toast slides out first (180 ms), then it is removed.
  const dismiss = useCallback((id) => {
    setToasts((list) => list.map((toast) => (toast.id === id ? { ...toast, leaving: true } : toast)));
    setTimeout(() => setToasts((list) => list.filter((toast) => toast.id !== id)), 180);
  }, []);

  const show = useCallback(
    (type, message) => {
      const id = nextId.current++;

      setToasts((list) => [...list, { id, type, message }]);
      setTimeout(() => dismiss(id), DURATION_MS);
    },
    [dismiss]
  );

  const [api] = useState(() => ({
    success: (message) => show("success", message),
    error: (message) => show("error", message),
    info: (message) => show("info", message),
  }));

  return (
    <ToastContext.Provider value={api}>
      {children}

      {/* role="status" + aria-live: screen readers announce new messages */}
      <div className="toast-stack" role="status" aria-live="polite">
        {toasts.map((toast) => (
          <div key={toast.id} className={`toast toast-${toast.type}${toast.leaving ? " leaving" : ""}`}>
            <Icon name={toast.type === "success" ? "check" : toast.type === "error" ? "alert" : "info"} className="toast-icon" />
            <span>{toast.message}</span>

            <button
              className="toast-close"
              onClick={() => dismiss(toast.id)}
              aria-label="Dismiss message"
            >
              ×
            </button>
          </div>
        ))}
      </div>
    </ToastContext.Provider>
  );
}
