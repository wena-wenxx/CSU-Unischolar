import { Navigate, Outlet, useLocation } from "react-router-dom";
import { homePathFor, useAuth } from "../lib/auth";

/*
  Guards a group of routes.
  - Not logged in                      -> go to /login
  - Must change a temporary password   -> go to /account/password first
  - Wrong role                         -> go to your own dashboard
  - Otherwise                          -> show the page
  role may be one role ("staff") or a list (["staff", "admin"]).
*/
export default function ProtectedRoute({ role }) {
  const { user } = useAuth();
  const location = useLocation();

  if (!user) {
    return <Navigate to="/login" replace />;
  }

  if (user.must_change_password && location.pathname !== "/account/password") {
    return <Navigate to="/account/password" replace />;
  }

  const allowed = Array.isArray(role) ? role : role ? [role] : null;

  if (allowed && !allowed.includes(user.role)) {
    return <Navigate to={homePathFor(user)} replace />;
  }

  return <Outlet />;
}
