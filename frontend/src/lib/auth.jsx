import { createContext, useContext, useState } from "react";
import api from "../services/api";

/* Who is logged in. Any page can read it with useAuth(). */

const AuthContext = createContext(null);

function getStoredUser() {
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

export function AuthProvider({ children }) {
  const [user, setUser] = useState(getStoredUser());

  // Called with the /login response: { token, user }
  function login(data) {
    localStorage.setItem("token", data.token);
    localStorage.setItem("user", JSON.stringify(data.user));
    setUser(data.user);
  }

  async function logout() {
    // Tell the server to revoke the token BEFORE forgetting it locally.
    try {
      await api.post("/logout");
    } catch {
      // Already expired or server offline: still log out locally.
    }

    localStorage.removeItem("token");
    localStorage.removeItem("user");
    setUser(null);
  }

  return (
    <AuthContext.Provider value={{ user, login, logout }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  return useContext(AuthContext);
}
