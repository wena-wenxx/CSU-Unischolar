import { useCallback, useEffect, useState } from "react";
import { ConfirmContext } from "../lib/confirm";

/*
  Modal window. Closes with the × button, the Escape key, or a click
  on the dark background.

    <Modal title="Tag as Grantee" onClose={close} footer={<buttons/>}>
      ...form fields...
    </Modal>
*/
export default function Modal({ title, subtitle, onClose, size = "normal", footer, children }) {
  useEffect(() => {
    function onKey(event) {
      if (event.key === "Escape") onClose?.();
    }

    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [onClose]);

  return (
    <div
      className="modal-backdrop"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) onClose?.();
      }}
    >
      <div
        className={size === "large" ? "modal modal-large" : "modal"}
        role="dialog"
        aria-modal="true"
        aria-label={typeof title === "string" ? title : undefined}
      >
        <div className="card-header">
          <div>
            <h2>{title}</h2>
            {subtitle}
          </div>

          {onClose && (
            <button className="close-button" onClick={onClose} aria-label="Close">
              ×
            </button>
          )}
        </div>

        {children}

        {footer && <div className="modal-footer">{footer}</div>}
      </div>
    </div>
  );
}

/*
  Yes/No question in a modal (replaces window.confirm()).

    const confirm = useConfirm();
    if (!(await confirm({ title: "Submit?", message: "...", confirmLabel: "Submit" }))) return;
*/

export function ConfirmProvider({ children }) {
  const [request, setRequest] = useState(null);

  const confirm = useCallback(
    (options) =>
      new Promise((resolve) => {
        setRequest({ ...options, resolve });
      }),
    []
  );

  function answer(value) {
    request.resolve(value);
    setRequest(null);
  }

  return (
    <ConfirmContext.Provider value={confirm}>
      {children}

      {request && (
        <Modal
          title={request.title || "Are you sure?"}
          onClose={() => answer(false)}
          footer={
            <>
              <button className="button button-secondary" onClick={() => answer(false)}>
                {request.cancelLabel || "Cancel"}
              </button>

              <button
                className={
                  request.tone === "danger" ? "button button-danger" : "button button-primary"
                }
                onClick={() => answer(true)}
                autoFocus
              >
                {request.confirmLabel || "Yes, continue"}
              </button>
            </>
          }
        >
          <p>{request.message}</p>
        </Modal>
      )}
    </ConfirmContext.Provider>
  );
}
