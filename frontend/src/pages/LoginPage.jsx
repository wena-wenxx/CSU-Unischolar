import { useEffect, useState } from "react";
import { Navigate, useNavigate } from "react-router-dom";

import api, { errMsg } from "../services/api";
import { homePathFor, useAuth } from "../lib/auth";
import {
  APP_NAME,
  CAMPUS_PHOTO,
  CARD_FOOTER,
  LOGO,
  MOTTO,
  OFFICE,
  TAGLINE,
  UNIVERSITY,
} from "../lib/brand";

/*
  Login: full-screen CSU Main Campus photo under a dark green layer,
  with a centred white sign-in card. "Forgot password?" shows how to get
  a new password from the OAS (with its contact details).
*/
export default function LoginPage() {
  const { user, login } = useAuth();
  const navigate = useNavigate();

  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [forgot, setForgot] = useState(false);
  const [contact, setContact] = useState(null);

  // OAS contact details for "Forgot your password?" (public, no login needed).
  useEffect(() => {
    api
      .get("/office-contact")
      .then(({ data }) => setContact(data))
      .catch(() => setContact(null));
  }, []);

  // Already logged in? Go straight to the dashboard.
  if (user) {
    return <Navigate to={homePathFor(user)} replace />;
  }

  async function handleLogin(event) {
    event.preventDefault();
    setError("");
    setLoading(true);

    try {
      const response = await api.post("/login", { email, password });

      login(response.data);
      navigate(homePathFor(response.data.user), { replace: true });
    } catch (err) {
      setError(errMsg(err, "Login failed. Please check your email and password."));
      setLoading(false);
    }
  }

  return (
    <main className="login-page" style={{ backgroundImage: `url(${CAMPUS_PHOTO})` }}>
      <div className="login-wrap">
        <p className="login-tagline">{TAGLINE}</p>

        <section className="login-card" aria-labelledby="login-title">
          <img src={LOGO} alt={`${UNIVERSITY} logo`} className="login-logo" />

          <h1 id="login-title" className="login-title">
            {APP_NAME}
          </h1>

          <p className="login-subtitle">
            {UNIVERSITY} — {OFFICE}
          </p>

          <p className="login-motto">{MOTTO}</p>

          {error && (
            <div className="login-error" role="alert">
              <span className="login-error-icon" aria-hidden="true">
                !
              </span>
              <span>{error}</span>
            </div>
          )}

          <form className="login-form" onSubmit={handleLogin}>
            <label htmlFor="email">Email address</label>

            <div className="login-input-wrapper">
              <span className="login-input-icon" aria-hidden="true">
                @
              </span>

              <input
                id="email"
                type="email"
                placeholder="Enter your email address"
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                autoComplete="username"
                required
              />
            </div>

            <div className="login-label-row">
              <label htmlFor="password">Password</label>
              <button type="button" className="login-forgot" onClick={() => setForgot(!forgot)} aria-expanded={forgot}>
                Forgot password?
              </button>
            </div>

            <div className="login-input-wrapper">
              <span className="login-input-icon" aria-hidden="true">
                •
              </span>

              <input
                id="password"
                type={showPassword ? "text" : "password"}
                placeholder="Enter your password"
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                autoComplete="current-password"
                required
              />

              <button
                type="button"
                className="password-toggle"
                onClick={() => setShowPassword(!showPassword)}
                aria-label={showPassword ? "Hide password" : "Show password"}
              >
                {showPassword ? "Hide" : "Show"}
              </button>
            </div>

            {forgot && (
              <div className="login-help" role="note">
                <strong>Forgot your password?</strong>
                <p>
                  The Office of Admission and Scholarship resets passwords. Contact them with your Student ID; you get a
                  temporary password that you change after signing in.
                </p>
                {contact && (contact.oas_email || contact.oas_phone) && (
                  <p className="login-help-contact">
                    {contact.oas_email && <a href={`mailto:${contact.oas_email}`}>{contact.oas_email}</a>}
                    {contact.oas_email && contact.oas_phone && " · "}
                    {contact.oas_phone}
                  </p>
                )}
              </div>
            )}

            <button type="submit" className="login-submit" disabled={loading}>
              {loading ? (
                <>
                  <span className="login-spinner" aria-hidden="true"></span>
                  Signing in...
                </>
              ) : (
                "Sign In"
              )}
            </button>
          </form>


          <p className="login-card-footer">{CARD_FOOTER}</p>
        </section>

      </div>
    </main>
  );
}
