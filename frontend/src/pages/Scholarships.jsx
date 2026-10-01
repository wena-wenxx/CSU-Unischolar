import { useEffect, useState } from "react";
import { Link } from "react-router-dom";

import api from "../services/api";
import Loading from "../components/Loading";

export default function Scholarships() {
    const [scholarships, setScholarships] =
        useState([]);

    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");

    useEffect(() => {
        const loadScholarships = async () => {
            try {
                const response =
                    await api.get("/scholarships");

                setScholarships(response.data);
            } catch (err) {
                setError(
                    err.response?.data?.message ||
                        "Unable to load scholarships."
                );
            } finally {
                setLoading(false);
            }
        };

        loadScholarships();
    }, []);

    if (loading) {
        return (
            <Loading text="Loading scholarships..." />
        );
    }

    return (
        <div>
            <div className="page-header">
                <div>
                    <span className="eyebrow">
                        SCHOLARSHIPS
                    </span>

                    <h2>
                        Available Scholarships
                    </h2>

                    <p>
                        Review scholarship programs and
                        their required documents.
                    </p>
                </div>
            </div>

            {error && (
                <div className="alert alert-error">
                    {error}
                </div>
            )}

            {scholarships.length === 0 ? (
                <div className="empty-state section-card">
                    <h3>
                        No scholarships available
                    </h3>

                    <p>
                        There are currently no active
                        scholarship programs.
                    </p>
                </div>
            ) : (
                <div className="scholarship-grid">
                    {scholarships.map((scholarship) => (
                        <div
                            className="scholarship-card"
                            key={scholarship.id}
                        >
                            <div className="scholarship-icon">
                                🎓
                            </div>

                            <span className="program-status">
                                {scholarship.status}
                            </span>

                            <h3>
                                {scholarship.name}
                            </h3>

                            <p>
                                {scholarship.description ||
                                    "Scholarship program available to eligible CSU students."}
                            </p>

                            {scholarship.provider && (
                                <div className="info-line">
                                    <span>
                                        Provider
                                    </span>

                                    <strong>
                                        {
                                            scholarship.provider
                                        }
                                    </strong>
                                </div>
                            )}

                            {scholarship.amount && (
                                <div className="info-line">
                                    <span>
                                        Amount
                                    </span>

                                    <strong>
                                        ₱
                                        {Number(
                                            scholarship.amount
                                        ).toLocaleString()}
                                    </strong>
                                </div>
                            )}

                            <Link
                                to={`/student/scholarships/${scholarship.id}`}
                                className="secondary-button full-width"
                            >
                                View Scholarship
                            </Link>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}