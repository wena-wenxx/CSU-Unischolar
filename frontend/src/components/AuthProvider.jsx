import { useState } from "react";
import api from "../services/api";
import { AuthContext, getStoredUser } from "../lib/auth";
import * as session from "../lib/session";

/* Keeps the logged-in user (read it anywhere with useAuth() from lib/auth). */
export default function AuthProvider({ children }) {
  const [user, setUser] = useState(getStoredUser);

  // Called with the /login response: { token, user }
  function login(data) {
    session.setItem("token", data.token);
    session.setItem("user", JSON.stringify(data.user));
    setUser(data.user);
  }

  // After a password change: the server sends the updated user.
  function updateUser(next) {
    session.setItem("user", JSON.stringify(next));
    setUser(next);
  }

  async function logout() {
    // Tell the server to revoke the token BEFORE forgetting it locally.
    try {
      await api.post("/logout");
    } catch {
      // Already expired or server offline: still log out locally.
    }

    session.removeItem("token");
    session.removeItem("user");
    setUser(null);
  }

  return <AuthContext.Provider value={{ user, login, logout, updateUser }}>{children}</AuthContext.Provider>;
}
