import { useEffect, useState } from "react";

import api from "../services/api";
import Loading from "../components/Loading";
import StatusBadge from "../components/StatusBadge";

export default function StaffApplications() {
    const [applications, setApplications] =
        useState([]);

    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");

    const loadApplications = async () => {
        try {
            const response =
                await api.get("/applications");

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

    useEffect(() => {
        loadApplications();
    }, []);

    if (loading) {
        return (
            <Loading text="Loading applications..." />
        );
    }

    return (
        <div>
            <div className="page-header">
                <div>
                    <span className="eyebrow">
                        OAS APPLICATION REVIEW
                    </span>

                    <h2>
                        Scholarship Applications
                    </h2>

                    <p>
                        Review submitted student
                        applications and uploaded
                        documents.
                    </p>
                </div>
            </div>

            {error && (
                <div className="alert alert-error">
                    {error}
                </div>
            )}

            <div className="section-card">
                {applications.length === 0 ? (
                    <div className="empty-state">
                        <h3>
                            No applications found.
                        </h3>

                        <p>
                            Submitted applications will
                            appear here.
                        </p>
                    </div>
                ) : (
                    <div className="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>
                                        Student
                                    </th>

                                    <th>
                                        Student ID
                                    </th>

                                    <th>
                                        Scholarship
                                    </th>

                                    <th>
                                        Documents
                                    </th>

                                    <th>
                                        Status
                                    </th>

                                    <th>
                                        Submitted
                                    </th>
                                </tr>
                            </thead>

                            <tbody>
                                {applications.map(
                                    (application) => (
                                        <tr
                                            key={
                                                application.id
                                            }
                                        >
                                            <td>
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
                                            </td>

                                            <td>
                                                {
                                                    application
                                                        .student
                                                        ?.student_id
                                                }
                                            </td>

                                            <td>
                                                {
                                                    application
                                                        .scholarship
                                                        ?.name
                                                }
                                            </td>

                                            <td>
                                                {
                                                    application
                                                        .documents
                                                        ?.length ||
                                                    0
                                                }
                                            </td>

                                            <td>
                                                <StatusBadge
                                                    status={
                                                        application.status
                                                    }
                                                />
                                            </td>

                                            <td>
                                                {application.submitted_at
                                                    ? new Date(
                                                          application.submitted_at
                                                      ).toLocaleDateString()
                                                    : "Not submitted"}
                                            </td>
                                        </tr>
                                    )
                                )}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </div>
    );
}