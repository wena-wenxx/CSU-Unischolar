import { createContext, useCallback, useContext, useRef, useState } from "react";

/*
  Small notification messages in the corner (replaces alert()).

  Usage in any page:
    const toast = useToast();
    toast.success("Saved.");
    toast.error("Something went wrong.");
    toast.info("Heads up.");
*/

const ToastContext = createContext(null);

const DURATION_MS = 4500;

export function ToastProvider({ children }) {
  const [toasts, setToasts] = useState([]);
  const nextId = useRef(1);

  const dismiss = useCallback((id) => {
    setToasts((list) => list.filter((toast) => toast.id !== id));
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
          <div key={toast.id} className={`toast toast-${toast.type}`}>
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

export function useToast() {
  return useContext(ToastContext);
}
