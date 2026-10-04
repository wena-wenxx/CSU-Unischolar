import { Navigate, Outlet } from "react-router-dom";
import { homePathFor, useAuth } from "../lib/auth";

/*
  Guards a group of routes.
  - Not logged in      -> go to /login
  - Wrong role         -> go to your own dashboard
  - Otherwise          -> show the page
*/
export default function ProtectedRoute({ role }) {
  const { user } = useAuth();

  if (!user) {
    return <Navigate to="/login" replace />;
  }

  if (role && user.role !== role) {
    return <Navigate to={homePathFor(user)} replace />;
  }

  return <Outlet />;
}
