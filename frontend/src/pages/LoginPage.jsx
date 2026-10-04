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
  const [showPassword, setShowPassword] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

  if (user) {
    return <Navigate to={homePathFor(user)} replace />;
  }

  async function handleLogin(event) {
    event.preventDefault();

    setError("");
    setLoading(true);

    try {
      const response = await api.post("/login", {
        email,
        password,
      });

      login(response.data);

      navigate(homePathFor(response.data.user), {
        replace: true,
      });
    } catch (err) {
      setError(
        errMsg(err, "Login failed. Please check your email and password."),
      );

      setLoading(false);
    }
  }

  return (
    <main className="login-page">
      {/* LEFT SIDE */}
      <section
        className="login-hero"
        style={{
          backgroundImage: `url(${CAMPUS_PHOTO})`,
        }}
        aria-label={`${UNIVERSITY} main campus`}
      >
        <div className="login-hero-overlay"></div>

        <div className="login-hero-content">
          <div className="login-brand">
            <img
              src={LOGO}
              alt={`${UNIVERSITY} logo`}
              className="login-hero-logo"
            />

            <div>
              <span className="login-brand-small">CARAGA STATE UNIVERSITY</span>

              <span className="login-brand-line"></span>

              <span className="login-brand-system">UniScholar</span>
            </div>
          </div>

          <div className="login-hero-main">
            <p className="login-hero-eyebrow">
              OFFICE OF ADMISSION AND SCHOLARSHIP
            </p>

            <h1>Unified Scholarship Management System</h1>

            <p className="login-hero-description">
              A centralized platform for managing scholarship applications,
              student records, document validation, and scholarship monitoring
              at Caraga State University.
            </p>

            <div className="login-hero-divider"></div>

            <p className="login-hero-tagline">{TAGLINE}</p>

            <p className="login-hero-motto">{MOTTO}</p>
          </div>

          <small className="login-hero-credit">{PHOTO_CREDIT}</small>
        </div>
      </section>

      {/* RIGHT SIDE */}
      <section className="login-panel">
        <div className="login-form-container">
          <div className="login-form-brand">
            <img src={LOGO} alt={`${UNIVERSITY} logo`} className="login-logo" />

            <div>
              <span className="login-form-brand-title">{APP_NAME}</span>

              <span className="login-form-brand-subtitle">{UNIVERSITY}</span>
            </div>
          </div>

          <div className="login-heading">
            <p className="login-eyebrow">SECURE PORTAL</p>

            <h1>Welcome back!</h1>

            <p>Sign in to continue to your UniScholar account.</p>
          </div>

          {error && (
            <div className="login-error" role="alert">
              <span className="login-error-icon">!</span>

              <span>{error}</span>
            </div>
          )}

          <form className="login-form" onSubmit={handleLogin}>
            <div className="login-field">
              <label htmlFor="email">Email address</label>

              <div className="login-input-wrapper">
                <span className="login-input-icon">@</span>

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
            </div>

            <div className="login-field">
              <label htmlFor="password">Password</label>

              <div className="login-input-wrapper">
                <span className="login-input-icon">•</span>

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
            </div>

            <button type="submit" className="login-submit" disabled={loading}>
              {loading ? (
                <>
                  <span className="login-spinner"></span>
                  Signing in...
                </>
              ) : (
                <>
                  Sign In
                  <span className="login-submit-arrow">→</span>
                </>
              )}
            </button>
          </form>

          <div className="login-security-note">
            <span className="login-security-icon">✓</span>

            <div>
              <strong>Authorized users only</strong>

              <p>
                This portal is intended for CSU students and authorized OAS
                staff.
              </p>
            </div>
          </div>

          <details className="login-demo">
            <summary>Demo accounts for testing</summary>

            <div className="login-demo-content">
              <div className="demo-account">
                <strong>OAS Staff</strong>

                <span>oas.staff@carsu.edu.ph</span>

                <small>Password: Staff@12345</small>
              </div>

              <div className="demo-account">
                <strong>Student</strong>

                <span>student1@carsu.edu.ph</span>

                <small>Password: Student@12345</small>
              </div>

              <p className="demo-warning">
                These are fictional accounts for local testing only and are not
                connected to CSU SSO.
              </p>
            </div>
          </details>

          <footer className="login-footer">
            <span>{SYSTEM_FOOTER}</span>

            <span className="login-footer-dot">•</span>

            <span>CSU UniScholar</span>
          </footer>
        </div>
      </section>
    </main>
  );
}
