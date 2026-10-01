import { useEffect, useState } from "react";
import { Link } from "react-router-dom";

import api from "../services/api";
import Loading from "../components/Loading";
import StatusBadge from "../components/StatusBadge";

export default function StaffDashboard() {
    const [applications, setApplications] =
        useState([]);

    const [scholarships, setScholarships] =
        useState([]);

    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");

    useEffect(() => {
        const load = async () => {
            try {
                const [
                    applicationsResponse,
                    scholarshipsResponse,
                ] = await Promise.all([
                    api.get("/applications"),
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
                        "Unable to load staff dashboard."
                );
            } finally {
                setLoading(false);
            }
        };

        load();
    }, []);

    if (loading) {
        return (
            <Loading text="Loading staff dashboard..." />
        );
    }

    const count = (status) =>
        applications.filter(
            (application) =>
                application.status === status
        ).length;

    return (
        <div>
            <div className="page-header">
                <div>
                    <span className="eyebrow">
                        OAS STAFF DASHBOARD
                    </span>

                    <h2>
                        Scholarship Operations
                    </h2>

                    <p>
                        Monitor applications and
                        scholarship programs.
                    </p>
                </div>

                <Link
                    to="/staff/applications"
                    className="primary-button"
                >
                    Review Applications
                </Link>
            </div>

            {error && (
                <div className="alert alert-error">
                    {error}
                </div>
            )}

            <div className="stats-grid">
                <div className="stat-card">
                    <span>
                        Total Applications
                    </span>

                    <strong>
                        {applications.length}
                    </strong>
                </div>

                <div className="stat-card">
                    <span>
                        Under Review
                    </span>

                    <strong>
                        {count("under_review")}
                    </strong>
                </div>

                <div className="stat-card">
                    <span>
                        Needs Action
                    </span>

                    <strong>
                        {count("needs_action")}
                    </strong>
                </div>

                <div className="stat-card">
                    <span>
                        Approved
                    </span>

                    <strong>
                        {count("approved")}
                    </strong>
                </div>
            </div>

            <div className="dashboard-grid">
                <div className="section-card">
                    <div className="section-heading">
                        <div>
                            <h3>
                                Recent Applications
                            </h3>

                            <p>
                                Latest submitted
                                applications.
                            </p>
                        </div>

                        <Link
                            to="/staff/applications"
                            className="text-link"
                        >
                            View all
                        </Link>
                    </div>

                    {applications
                        .slice(0, 8)
                        .map((application) => (
                            <div
                                className="application-row"
                                key={application.id}
                            >
                                <div>
                                    <strong>
                                        {
                                            application
                                                .student
                                                ?.first_name
                                        }{" "}
                                        {
                                            application
                                                .student
                                                ?.last_name
                                        }
                                    </strong>

                                    <span>
                                        {
                                            application
                                                .scholarship
                                                ?.name
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

                <div className="section-card">
                    <div className="section-heading">
                        <div>
                            <h3>
                                Scholarship Programs
                            </h3>

                            <p>
                                Current scholarship
                                records.
                            </p>
                        </div>

                        <Link
                            to="/staff/scholarships"
                            className="text-link"
                        >
                            Manage
                        </Link>
                    </div>

                    {scholarships.map(
                        (scholarship) => (
                            <div
                                className="application-row"
                                key={scholarship.id}
                            >
                                <div>
                                    <strong>
                                        {
                                            scholarship.name
                                        }
                                    </strong>

                                    <span>
                                        {
                                            scholarship
                                                .requirements
                                                ?.length || 0
                                        }{" "}
                                        requirements
                                    </span>
                                </div>

                                <span className="program-status">
                                    {
                                        scholarship.status
                                    }
                                </span>
                            </div>
                        )
                    )}
                </div>
            </div>
        </div>
    );
}