import { Link } from "react-router-dom";
import { useEffect, useState } from "react";

import api from "../services/api";
import Loading from "../components/Loading";
import StatusBadge from "../components/StatusBadge";

export default function StudentDashboard() {
    const [applications, setApplications] = useState([]);
    const [scholarships, setScholarships] = useState([]);

    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");

    useEffect(() => {
        const loadDashboard = async () => {
            try {
                const [
                    applicationsResponse,
                    scholarshipsResponse,
                ] = await Promise.all([
                    api.get("/my-applications"),
                    api.get("/scholarships"),
                ]);

                setApplications(
                    applicationsResponse.data
                );

                setScholarships(
                    scholarshipsResponse.data
                );
            } catch (err) {
                setError(
                    err.response?.data?.message ||
                        "Unable to load dashboard."
                );
            } finally {
                setLoading(false);
            }
        };

        loadDashboard();
    }, []);

    if (loading) {
        return <Loading text="Loading dashboard..." />;
    }

    const needsAction =
        applications.filter(
            (application) =>
                application.status === "needs_action"
        ).length;

    const approved =
        applications.filter(
            (application) =>
                application.status === "approved"
        ).length;

    return (
        <div>
            <div className="page-header">
                <div>
                    <span className="eyebrow">
                        STUDENT DASHBOARD
                    </span>

                    <h2>
                        Welcome to CSU UniScholar
                    </h2>

                    <p>
                        Manage your scholarship
                        applications and documents in
                        one place.
                    </p>
                </div>

                <Link
                    to="/student/scholarships"
                    className="primary-button"
                >
                    Browse Scholarships
                </Link>
            </div>

            {error && (
                <div className="alert alert-error">
                    {error}
                </div>
            )}

            <div className="stats-grid">
                <div className="stat-card">
                    <span>My Applications</span>
                    <strong>
                        {applications.length}
                    </strong>
                </div>

                <div className="stat-card">
                    <span>Needs Action</span>
                    <strong>
                        {needsAction}
                    </strong>
                </div>

                <div className="stat-card">
                    <span>Approved</span>
                    <strong>
                        {approved}
                    </strong>
                </div>

                <div className="stat-card">
                    <span>Available Scholarships</span>
                    <strong>
                        {scholarships.length}
                    </strong>
                </div>
            </div>

            <div className="section-card">
                <div className="section-heading">
                    <div>
                        <h3>
                            Recent Applications
                        </h3>

                        <p>
                            Track the progress of your
                            scholarship applications.
                        </p>
                    </div>

                    <Link
                        to="/student/applications"
                        className="text-link"
                    >
                        View all
                    </Link>
                </div>

                {applications.length === 0 ? (
                    <div className="empty-state">
                        <h4>
                            No applications yet
                        </h4>

                        <p>
                            Browse available scholarships
                            and start your first
                            application.
                        </p>

                        <Link
                            to="/student/scholarships"
                            className="secondary-button"
                        >
                            Browse Scholarships
                        </Link>
                    </div>
                ) : (
                    <div className="application-list">
                        {applications
                            .slice(0, 5)
                            .map((application) => (
                                <div
                                    className="application-row"
                                    key={application.id}
                                >
                                    <div>
                                        <strong>
                                            {
                                                application
                                                    .scholarship
                                                    ?.name
                                            }
                                        </strong>

                                        <span>
                                            Application #
                                            {
                                                application.id
                                            }
                                        </span>
                                    </div>

                                    <StatusBadge
                                        status={
                                            application.status
                                        }
                                    />
                                </div>
                            ))}
                    </div>
                )}
            </div>
        </div>
    );
}