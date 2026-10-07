import { createContext, useContext } from "react";

/*
  Who is logged in. Any page can read it with useAuth().
  The provider component is in components/AuthProvider.jsx.
*/

export const AuthContext = createContext(null);

export function getStoredUser() {
  try {
    return JSON.parse(localStorage.getItem("user"));
  } catch {
    return null;
  }
}

export function homePathFor(user) {
  if (!user) return "/login";

  return user.role === "staff" ? "/staff/dashboard" : "/student/dashboard";
}

export function useAuth() {
  return useContext(AuthContext);
}
