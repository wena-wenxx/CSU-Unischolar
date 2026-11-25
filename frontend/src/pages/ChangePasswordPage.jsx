import { Navigate, useNavigate } from "react-router-dom";
import { homePathFor, useAuth } from "../lib/auth";
import { APP_NAME, LOGO, UNIVERSITY } from "../lib/brand";
import ChangePasswordForm from "../components/ChangePasswordForm";

export default function ChangePasswordPage() {
  const { user, logout } = useAuth();
  const navigate = useNavigate();

  if (!user) return <Navigate to="/login" replace />;

  const forced = user.must_change_password;

  return (
    <main className="password-page">
      <section className="card password-card">
        <img src={LOGO} alt={`${UNIVERSITY} logo`} className="password-logo" />
        <h1>{forced ? "Set your own password" : "Change password"}</h1>
        <p className="muted">
          {forced
            ? `Welcome to ${APP_NAME}, ${user.name}. You logged in with a temporary password. Choose your own password to continue.`
            : "Choose a new password for your account."}
        </p>

        <ChangePasswordForm onDone={(next) => navigate(homePathFor(next), { replace: true })} />

        <div className="button-row password-links">
          {forced ? (
            <button type="button" className="link-button" onClick={logout}>
              Sign out instead
            </button>
          ) : (
            <button type="button" className="link-button" onClick={() => navigate(-1)}>
              Cancel
            </button>
          )}
        </div>
      </section>
    </main>
  );
}
