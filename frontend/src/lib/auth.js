import { createContext, useContext } from "react";
import * as session from "./session";

export const AuthContext = createContext(null);

export function getStoredUser() {
  try {
    return JSON.parse(session.getItem("user"));
  } catch {
    return null;
  }
}

export function homePathFor(user) {
  if (!user) return "/login";
  if (user.must_change_password) return "/account/password";

  return { admin: "/admin/dashboard", staff: "/staff/dashboard" }[user.role] || "/student/dashboard";
}

export const ROLE_LABELS = { student: "Student", staff: "OAS Staff", admin: "System Admin" };

export function useAuth() {
  return useContext(AuthContext);
}
