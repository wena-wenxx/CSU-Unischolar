import { useEffect, useState } from "react";

import api from "../services/api";
import Loading from "../components/Loading";

const emptyForm = {
    name: "",
    description: "",
    provider: "",
    application_start: "",
    application_end: "",
    amount: "",
    status: "active",
};

export default function StaffScholarships() {
    const [scholarships, setScholarships] =
        useState([]);

    const [form, setForm] = useState(emptyForm);

    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState("");
    const [success, setSuccess] = useState("");

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

    useEffect(() => {
        loadScholarships();
    }, []);

    const handleChange = (event) => {
        const { name, value } = event.target;

        setForm((current) => ({
            ...current,
            [name]: value,
        }));
    };

    const createScholarship = async (event) => {
        event.preventDefault();

        setSaving(true);
        setError("");
        setSuccess("");

        try {
            await api.post("/scholarships", {
                ...form,
                amount: form.amount
                    ? Number(form.amount)
                    : null,
            });

            setForm(emptyForm);

            setSuccess(
                "Scholarship created successfully."
            );

            await loadScholarships();
        } catch (err) {
            setError(
                err.response?.data?.message ||
                    "Unable to create scholarship."
            );
        } finally {
            setSaving(false);
        }
    };

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
                        STAFF MANAGEMENT
                    </span>

                    <h2>
                        Scholarship Programs
                    </h2>

                    <p>
                        Create and manage scholarship
                        programs.
                    </p>
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
                                Add Scholarship
                            </h3>

                            <p>
                                Create a new scholarship
                                program.
                            </p>
                        </div>
                    </div>

                    <form
                        className="form-stack"
                        onSubmit={createScholarship}
                    >
                        <div className="form-group">
                            <label>
                                Scholarship Name
                            </label>

                            <input
                                name="name"
                                value={form.name}
                                onChange={handleChange}
                                required
                            />
                        </div>

                        <div className="form-group">
                            <label>
                                Provider
                            </label>

                            <input
                                name="provider"
                                value={form.provider}
                                onChange={handleChange}
                            />
                        </div>

                        <div className="form-group">
                            <label>
                                Description
                            </label>

                            <textarea
                                name="description"
                                value={form.description}
                                onChange={handleChange}
                                rows="4"
                            />
                        </div>

                        <div className="form-grid-2">
                            <div className="form-group">
                                <label>
                                    Amount
                                </label>

                                <input
                                    type="number"
                                    min="0"
                                    name="amount"
                                    value={form.amount}
                                    onChange={handleChange}
                                />
                            </div>

                            <div className="form-group">
                                <label>
                                    Status
                                </label>

                                <select
                                    name="status"
                                    value={form.status}
                                    onChange={handleChange}
                                >
                                    <option value="active">
                                        Active
                                    </option>

                                    <option value="inactive">
                                        Inactive
                                    </option>

                                    <option value="closed">
                                        Closed
                                    </option>
                                </select>
                            </div>
                        </div>

                        <div className="form-grid-2">
                            <div className="form-group">
                                <label>
                                    Application Start
                                </label>

                                <input
                                    type="date"
                                    name="application_start"
                                    value={
                                        form.application_start
                                    }
                                    onChange={handleChange}
                                />
                            </div>

                            <div className="form-group">
                                <label>
                                    Application End
                                </label>

                                <input
                                    type="date"
                                    name="application_end"
                                    value={
                                        form.application_end
                                    }
                                    onChange={handleChange}
                                />
                            </div>
                        </div>

                        <button
                            className="primary-button"
                            disabled={saving}
                        >
                            {saving
                                ? "Creating..."
                                : "Create Scholarship"}
                        </button>
                    </form>
                </div>

                <div className="section-card">
                    <div className="section-heading">
                        <div>
                            <h3>
                                Existing Programs
                            </h3>

                            <p>
                                Scholarship programs
                                currently stored in
                                UniScholar.
                            </p>
                        </div>
                    </div>

                    <div className="application-list">
                        {scholarships.map(
                            (scholarship) => (
                                <div
                                    className="application-row"
                                    key={
                                        scholarship.id
                                    }
                                >
                                    <div>
                                        <strong>
                                            {
                                                scholarship.name
                                            }
                                        </strong>

                                        <span>
                                            {
                                                scholarship.provider
                                            }
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
        </div>
    );
}