import { createContext, useContext } from "react";

/*
  Corner messages (replaces alert()). The provider is components/Toast.jsx.
    const toast = useToast();
    toast.success("Saved.");  toast.error("Something went wrong.");  toast.info("Heads up.");
*/
export const ToastContext = createContext(null);

export function useToast() {
  return useContext(ToastContext);
}
