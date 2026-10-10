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

// Where each role starts. Admin = system administrator.
export function homePathFor(user) {
  if (!user) return "/login";
  if (user.must_change_password) return "/account/password";

  return { admin: "/admin/dashboard", staff: "/staff/dashboard" }[user.role] || "/student/dashboard";
}

// Shown in the sidebar and on profile pages.
export const ROLE_LABELS = { student: "Student", staff: "OAS Staff", admin: "System Admin" };

export function useAuth() {
  return useContext(AuthContext);
}
