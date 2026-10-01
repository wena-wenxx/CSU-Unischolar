import { useState } from "react";
import { Navigate, useLocation, useNavigate } from "react-router-dom";
import { useAuth } from "../context/AuthContext";

export default function Login() {
    const { user, login } = useAuth();

    const navigate = useNavigate();
    const location = useLocation();

    const [email, setEmail] = useState("");
    const [password, setPassword] = useState("");

    const [error, setError] = useState("");
    const [submitting, setSubmitting] = useState(false);

    if (user) {
        return (
            <Navigate
                to={
                    user.role === "staff"
                        ? "/staff"
                        : "/student"
                }
                replace
            />
        );
    }

    const handleSubmit = async (event) => {
        event.preventDefault();

        setError("");
        setSubmitting(true);

        try {
            const loggedInUser = await login(
                email,
                password
            );

            const intended =
                location.state?.from?.pathname;

            if (intended) {
                navigate(intended);
            } else if (
                loggedInUser.role === "staff"
            ) {
                navigate("/staff");
            } else {
                navigate("/student");
            }
        } catch (err) {
            const message =
                err.response?.data?.message ||
                err.response?.data?.errors?.email?.[0] ||
                "Unable to log in. Please check your credentials.";

            setError(message);
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <div className="auth-page">
            <div className="auth-panel">
                <div className="auth-brand">
                    <div className="large-brand-mark">
                        CSU
                    </div>

                    <div>
                        <h1>CSU UniScholar</h1>
                        <p>
                            Unified Scholarship Management
                            System
                        </p>
                    </div>
                </div>

                <div className="auth-card">
                    <div className="auth-heading">
                        <span className="eyebrow">
                            CARAGA STATE UNIVERSITY
                        </span>

                        <h2>
                            Sign in to your account
                        </h2>

                        <p>
                            Students and authorized OAS
                            staff can access UniScholar
                            using their assigned account.
                        </p>
                    </div>

                    {error && (
                        <div className="alert alert-error">
                            {error}
                        </div>
                    )}

                    <form
                        onSubmit={handleSubmit}
                        className="form-stack"
                    >
                        <div className="form-group">
                            <label htmlFor="email">
                                CSU Email
                            </label>

                            <input
                                id="email"
                                type="email"
                                placeholder="name@carsu.edu.ph"
                                value={email}
                                onChange={(e) =>
                                    setEmail(
                                        e.target.value
                                    )
                                }
                                required
                            />
                        </div>

                        <div className="form-group">
                            <label htmlFor="password">
                                Password
                            </label>

                            <input
                                id="password"
                                type="password"
                                placeholder="Enter password"
                                value={password}
                                onChange={(e) =>
                                    setPassword(
                                        e.target.value
                                    )
                                }
                                required
                            />
                        </div>

                        <button
                            type="submit"
                            className="primary-button full-width"
                            disabled={submitting}
                        >
                            {submitting
                                ? "Signing in..."
                                : "Sign in"}
                        </button>
                    </form>

                    <div className="auth-note">
                        <strong>
                            Development authentication
                        </strong>

                        <p>
                            This prototype currently
                            authenticates against the
                            UniScholar Laravel database.
                            CSU SSO integration should be
                            connected only through an
                            authorized university
                            authentication service.
                        </p>
                    </div>
                </div>

                <p className="auth-footer">
                    CSU UniScholar · Capstone Prototype
                </p>
            </div>
        </div>
    );
}