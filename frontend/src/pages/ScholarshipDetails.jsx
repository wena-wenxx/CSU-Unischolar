import { useEffect, useState } from "react";
import {
    Link,
    useNavigate,
    useParams,
} from "react-router-dom";

import api from "../services/api";
import Loading from "../components/Loading";

export default function ScholarshipDetails() {
    const { id } = useParams();
    const navigate = useNavigate();

    const [scholarship, setScholarship] =
        useState(null);

    const [loading, setLoading] = useState(true);
    const [applying, setApplying] = useState(false);
    const [error, setError] = useState("");
    const [success, setSuccess] = useState("");

    useEffect(() => {
        const loadScholarship = async () => {
            try {
                const response =
                    await api.get(
                        `/scholarships/${id}`
                    );

                setScholarship(response.data);
            } catch (err) {
                setError(
                    err.response?.data?.message ||
                        "Unable to load scholarship."
                );
            } finally {
                setLoading(false);
            }
        };

        loadScholarship();
    }, [id]);

    const apply = async () => {
        setApplying(true);
        setError("");
        setSuccess("");

        try {
            await api.post("/applications", {
                scholarship_id: scholarship.id,
            });

            setSuccess(
                "Application started successfully."
            );

            setTimeout(() => {
                navigate("/student/applications");
            }, 800);
        } catch (err) {
            setError(
                err.response?.data?.message ||
                    err.response?.data?.errors
                        ?.scholarship_id?.[0] ||
                    "Unable to start application."
            );
        } finally {
            setApplying(false);
        }
    };

    if (loading) {
        return (
            <Loading text="Loading scholarship..." />
        );
    }

    if (!scholarship) {
        return (
            <div className="empty-state">
                Scholarship not found.
            </div>
        );
    }

    return (
        <div>
            <Link
                to="/student/scholarships"
                className="back-link"
            >
                ← Back to scholarships
            </Link>

            <div className="detail-hero">
                <div>
                    <span className="eyebrow">
                        SCHOLARSHIP PROGRAM
                    </span>

                    <h2>
                        {scholarship.name}
                    </h2>

                    <p>
                        {scholarship.description}
                    </p>
                </div>

                <div className="amount-box">
                    <span>
                        Scholarship Amount
                    </span>

                    <strong>
                        {scholarship.amount
                            ? `₱${Number(
                                  scholarship.amount
                              ).toLocaleString()}`
                            : "Not specified"}
                    </strong>
                </div>
            </div>

            {error && (
                <div className="alert alert-error">
                    {error}
                </div>
            )}

            {success && (
                <div className="alert alert-success">
                    {success}
                </div>
            )}

            <div className="two-column">
                <div className="section-card">
                    <div className="section-heading">
                        <div>
                            <h3>
                                Requirements
                            </h3>

                            <p>
                                Prepare the following
                                documents before
                                submitting your
                                application.
                            </p>
                        </div>
                    </div>

                    {scholarship.requirements
                        ?.length ? (
                        <div className="requirement-list">
                            {scholarship.requirements.map(
                                (requirement) => (
                                    <div
                                        className="requirement-row"
                                        key={
                                            requirement.id
                                        }
                                    >
                                        <div className="requirement-check">
                                            ✓
                                        </div>

                                        <div>
                                            <strong>
                                                {
                                                    requirement.name
                                                }
                                            </strong>

                                            <p>
                                                {requirement.description ||
                                                    "Required document."}
                                            </p>
                                        </div>

                                        <span
                                            className={
                                                requirement.is_required
                                                    ? "required-label"
                                                    : "optional-label"
                                            }
                                        >
                                            {requirement.is_required
                                                ? "Required"
                                                : "Optional"}
                                        </span>
                                    </div>
                                )
                            )}
                        </div>
                    ) : (
                        <div className="empty-state">
                            No requirements have been
                            added yet.
                        </div>
                    )}
                </div>

                <div className="section-card application-action-card">
                    <h3>
                        Ready to apply?
                    </h3>

                    <p>
                        Starting an application will
                        create a draft application. You
                        can then upload the required
                        documents before submitting it.
                    </p>

                    <button
                        className="primary-button full-width"
                        onClick={apply}
                        disabled={applying}
                    >
                        {applying
                            ? "Starting..."
                            : "Start Application"}
                    </button>
                </div>
            </div>
        </div>
    );
}