import { useState } from "react";
import api from "../services/api";
import { AuthContext, getStoredUser } from "../lib/auth";
import * as session from "../lib/session";

export default function AuthProvider({ children }) {
  const [user, setUser] = useState(getStoredUser);

  function login(data) {
    session.setItem("token", data.token);
    session.setItem("user", JSON.stringify(data.user));
    setUser(data.user);
  }

  function updateUser(next) {
    session.setItem("user", JSON.stringify(next));
    setUser(next);
  }

  async function logout() {
    try {
      await api.post("/logout");
    } catch {
    }

    session.removeItem("token");
    session.removeItem("user");
    setUser(null);
  }

  return <AuthContext.Provider value={{ user, login, logout, updateUser }}>{children}</AuthContext.Provider>;
}
