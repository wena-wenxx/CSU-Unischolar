import { useEffect, useState } from "react";

import api from "../services/api";
import Loading from "../components/Loading";
import StatusBadge from "../components/StatusBadge";

export default function MyApplications() {
    const [applications, setApplications] =
        useState([]);

    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");

    useEffect(() => {
        loadApplications();
    }, []);

    const loadApplications = async () => {
        try {
            const response =
                await api.get("/my-applications");

            setApplications(response.data);
        } catch (err) {
            setError(
                err.response?.data?.message ||
                    "Unable to load applications."
            );
        } finally {
            setLoading(false);
        }
    };

    const uploadDocument = async (
        applicationId,
        requirementId,
        file
    ) => {
        if (!file) return;

        const formData = new FormData();

        formData.append("file", file);
        formData.append(
            "scholarship_requirement_id",
            requirementId
        );

        formData.append(
            "document_type",
            file.name
        );

        try {
            await api.post(
                `/applications/${applicationId}/documents`,
                formData,
                {
                    headers: {
                        "Content-Type":
                            "multipart/form-data",
                    },
                }
            );

            await loadApplications();
        } catch (err) {
            setError(
                err.response?.data?.message ||
                    "Unable to upload document."
            );
        }
    };

    if (loading) {
        return (
            <Loading text="Loading your applications..." />
        );
    }

    return (
        <div>
            <div className="page-header">
                <div>
                    <span className="eyebrow">
                        MY APPLICATIONS
                    </span>

                    <h2>
                        Scholarship Applications
                    </h2>

                    <p>
                        Upload documents and monitor
                        your application status.
                    </p>
                </div>
            </div>

            {error && (
                <div className="alert alert-error">
                    {error}
                </div>
            )}

            {applications.length === 0 ? (
                <div className="empty-state section-card">
                    <h3>
                        You have no applications.
                    </h3>

                    <p>
                        Start by browsing available
                        scholarships.
                    </p>
                </div>
            ) : (
                <div className="application-page-list">
                    {applications.map((application) => {
                        const requirements =
                            application.scholarship
                                ?.requirements || [];

                        const documents =
                            application.documents || [];

                        return (
                            <div
                                className="section-card"
                                key={application.id}
                            >
                                <div className="application-header">
                                    <div>
                                        <span className="eyebrow">
                                            APPLICATION #
                                            {
                                                application.id
                                            }
                                        </span>

                                        <h3>
                                            {
                                                application
                                                    .scholarship
                                                    ?.name
                                            }
                                        </h3>
                                    </div>

                                    <StatusBadge
                                        status={
                                            application.status
                                        }
                                    />
                                </div>

                                {application.remarks && (
                                    <div className="remarks-box">
                                        <strong>
                                            Staff Remarks
                                        </strong>

                                        <p>
                                            {
                                                application.remarks
                                            }
                                        </p>
                                    </div>
                                )}

                                <div className="documents-section">
                                    <h4>
                                        Required Documents
                                    </h4>

                                    {requirements.length ===
                                    0 ? (
                                        <p className="muted">
                                            No requirements
                                            listed.
                                        </p>
                                    ) : (
                                        requirements.map(
                                            (
                                                requirement
                                            ) => {
                                                const document =
                                                    documents.find(
                                                        (item) =>
                                                            item.scholarship_requirement_id ===
                                                            requirement.id
                                                    );

                                                return (
                                                    <div
                                                        className="document-row"
                                                        key={
                                                            requirement.id
                                                        }
                                                    >
                                                        <div>
                                                            <strong>
                                                                {
                                                                    requirement.name
                                                                }
                                                            </strong>

                                                            <span>
                                                                {document
                                                                    ? document.original_filename
                                                                    : "No document uploaded"}
                                                            </span>
                                                        </div>

                                                        {application.status !==
                                                            "approved" &&
                                                            application.status !==
                                                                "rejected" && (
                                                                <label className="upload-button">
                                                                    {document
                                                                        ? "Replace"
                                                                        : "Upload"}

                                                                    <input
                                                                        type="file"
                                                                        accept=".pdf,.jpg,.jpeg,.png"
                                                                        hidden
                                                                        onChange={(
                                                                            event
                                                                        ) =>
                                                                            uploadDocument(
                                                                                application.id,
                                                                                requirement.id,
                                                                                event
                                                                                    .target
                                                                                    .files?.[0]
                                                                            )
                                                                        }
                                                                    />
                                                                </label>
                                                            )}
                                                    </div>
                                                );
                                            }
                                        )
                                    )}
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}
        </div>
    );
}