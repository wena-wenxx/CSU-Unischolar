import { createContext, useContext } from "react";

/*
  Yes/No question in a modal (replaces window.confirm()).
  The provider is ConfirmProvider in components/Modal.jsx.
    const confirm = useConfirm();
    if (!(await confirm({ title: "Submit?", message: "...", confirmLabel: "Submit" }))) return;
*/
export const ConfirmContext = createContext(null);

export function useConfirm() {
  return useContext(ConfirmContext);
}
