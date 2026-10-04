import { useState } from "react";
import { Navigate, useNavigate } from "react-router-dom";
import api, { errMsg } from "../services/api";
import { homePathFor, useAuth } from "../lib/auth";
import {
  APP_NAME,
  CAMPUS_PHOTO,
  LOGO,
  MOTTO,
  PHOTO_CREDIT,
  SYSTEM_FOOTER,
  TAGLINE,
  UNIVERSITY,
} from "../lib/brand";

export default function LoginPage() {
  const { user, login } = useAuth();
  const navigate = useNavigate();

  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

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
    <div className="login-page">
      {/* Left: CSU main campus photo under a CSU Green overlay (hidden on phones) */}
      <section
        className="login-hero"
        style={{ backgroundImage: `url(${CAMPUS_PHOTO})` }}
        aria-label={`${UNIVERSITY} main campus`}
      >
        <div className="login-hero-content">
          <img src={LOGO} alt="" className="login-hero-logo" />

          <p className="login-hero-university">{UNIVERSITY}</p>

          <p className="login-hero-tagline">{TAGLINE}</p>

          <p className="login-hero-motto">{MOTTO}</p>
        </div>

        <small className="login-hero-credit">{PHOTO_CREDIT}</small>
      </section>

      {/* Right: sign-in card on a CSU Green gradient */}
      <section className="login-panel">
        <div className="login-card">
          <img src={LOGO} alt={`${UNIVERSITY} logo`} className="login-logo" />

          <h1 className="login-title">{APP_NAME}</h1>

          <p className="login-university">{UNIVERSITY}</p>

          <p className="login-motto">{MOTTO}</p>

          {error && (
            <div className="alert alert-danger" role="alert">
              {error}
            </div>
          )}

          <form onSubmit={handleLogin}>
            <label htmlFor="email">Email</label>

            <input
              id="email"
              type="email"
              placeholder="Enter your email"
              value={email}
              onChange={(event) => setEmail(event.target.value)}
              autoComplete="username"
              required
            />

            <label htmlFor="password">Password</label>

            <input
              id="password"
              type="password"
              placeholder="Enter your password"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              autoComplete="current-password"
              required
            />

            <button className="button button-primary full-width" disabled={loading}>
              {loading ? "Signing in..." : "Sign In"}
            </button>
          </form>

          <div className="demo-box">
            <strong>Demo accounts (fictional, for testing only)</strong>

            <p>Staff: oas.staff@carsu.edu.ph / Staff@12345</p>
            <p>Student: student1@carsu.edu.ph / Student@12345</p>

            <small>These are not real CSU accounts and are not connected to CSU SSO.</small>
          </div>

          <p className="login-footer">{SYSTEM_FOOTER}</p>
        </div>
      </section>
    </div>
  );
}
