import { useCallback, useEffect, useRef, useState } from "react";
import { useLocation } from "react-router-dom";
import api, { errMsg } from "../../services/api";
import { CATEGORY_LABELS, availabilityInfo, categoryLabel, formatDate, formatMoney } from "../../lib/format";
import { useToast } from "../../lib/toast";
import Modal from "../../components/Modal";
import { useConfirm } from "../../lib/confirm";
import PageHeader from "../../components/PageHeader";
import EmptyState from "../../components/EmptyState";
import Loading from "../../components/Loading";

const EMPTY_FORM = {
  name: "",
  description: "",
  provider: "",
  category: "",
  amount: "",
  application_start: "",
  application_end: "",
  status: "active",
};

function CategorySelect({ id, value, onChange }) {
  return (
    <select id={id} value={value} onChange={onChange}>
      <option value="">Choose a type</option>
      {Object.entries(CATEGORY_LABELS).map(([key, label]) => (
        <option key={key} value={key}>
          {label}
        </option>
      ))}
    </select>
  );
}

export default function StaffScholarshipsPage() {
  const toast = useToast();

  const [scholarships, setScholarships] = useState([]);
  // ?manage=<id> opens that program (search box); ?new=1 jumps to the create form.
  const location = useLocation();
  const params = new URLSearchParams(location.search);
  const createRef = useRef(null);
  const wantsNew = params.get("new") === "1";
  const [managingId, setManagingId] = useState(() => Number(params.get("manage")) || null);
  const [loading, setLoading] = useState(true);
  const [form, setForm] = useState(EMPTY_FORM);
  const [saving, setSaving] = useState(false);

  // Written with .then() (not await) so React's lint rule can see that the
  // state is set later, when the server answers, not during the effect.
  const load = useCallback(
    () =>
      api
        .get("/scholarships")
        .then((response) => setScholarships(response.data))
        .catch((err) => toast.error(errMsg(err, "Unable to load scholarships.")))
        .finally(() => setLoading(false)),
    [toast]
  );

  useEffect(() => {
    load();
  }, [load]);

  useEffect(() => {
    if (wantsNew && !loading && createRef.current) {
      createRef.current.scrollIntoView({ behavior: "smooth", block: "start" });
      createRef.current.querySelector("input")?.focus();
    }
  }, [wantsNew, loading]);

  async function createScholarship(event) {
    event.preventDefault();
    setSaving(true);

    try {
      const response = await api.post("/scholarships", {
        ...form,
        category: form.category || null,
        amount: form.amount === "" ? null : Number(form.amount),
        application_start: form.application_start || null,
        application_end: form.application_end || null,
      });

      setForm(EMPTY_FORM);
      await load();
      toast.success("Scholarship created. Add its requirements now.");
      setManagingId(response.data.id);
    } catch (err) {
      toast.error(errMsg(err, "Unable to create scholarship."));
    } finally {
      setSaving(false);
    }
  }

  const field = (key) => (event) => setForm({ ...form, [key]: event.target.value });

  if (loading) return <Loading />;

  return (
    <div>
      <PageHeader title="Scholarship Programs" subtitle="Manage scholarship programs and their requirements" />

      <section className="card" id="create-scholarship" ref={createRef}>
        <h2>Create Scholarship</h2>

        <form className="form-grid" onSubmit={createScholarship}>
          <div>
            <label htmlFor="new-name">Name</label>
            <input id="new-name" value={form.name} onChange={field("name")} required />
          </div>

          <div>
            <label htmlFor="new-provider">Provider</label>
            <input id="new-provider" value={form.provider} onChange={field("provider")} />
          </div>

          <div>
            <label htmlFor="new-category">Type</label>
            <CategorySelect id="new-category" value={form.category} onChange={field("category")} />
          </div>

          <div>
            <label htmlFor="new-amount">Amount per semester (optional)</label>
            <input id="new-amount" type="number" min="0" step="0.01" value={form.amount} onChange={field("amount")} />
          </div>

          <div>
            <label htmlFor="new-start">Applications open</label>
            <input id="new-start" type="date" value={form.application_start} onChange={field("application_start")} />
          </div>

          <div>
            <label htmlFor="new-end">Deadline</label>
            <input id="new-end" type="date" value={form.application_end} onChange={field("application_end")} />
          </div>

          <div>
            <label htmlFor="new-status">Status</label>
            <select id="new-status" value={form.status} onChange={field("status")}>
              <option value="active">Active (students can apply between the dates)</option>
              <option value="inactive">Inactive (hidden from students)</option>
              <option value="closed">Closed</option>
            </select>
          </div>

          <div className="full-column">
            <label htmlFor="new-description">Description</label>
            <textarea id="new-description" value={form.description} onChange={field("description")} />
          </div>

          <div>
            <button className="button button-primary" disabled={saving}>
              {saving ? "Creating..." : "Create Scholarship"}
            </button>
          </div>
        </form>
      </section>

      <section className="card">
        <h2>Existing Scholarships</h2>

        {scholarships.length === 0 ? (
          <EmptyState message="No scholarship programs yet." />
        ) : (
          <div className="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Type</th>
                  <th>Provider</th>
                  <th>Amount</th>
                  <th>Deadline</th>
                  <th>Requirements</th>
                  <th>Open to students?</th>
                  <th>Action</th>
                </tr>
              </thead>

              <tbody>
                {scholarships.map((scholarship) => (
                  <tr key={scholarship.id}>
                    <td>{scholarship.name}</td>
                    <td>{categoryLabel(scholarship.category)}</td>
                    <td>{scholarship.provider}</td>
                    <td>{formatMoney(scholarship.amount)}</td>
                    <td>{formatDate(scholarship.application_end)}</td>
                    <td>{scholarship.requirements?.length || 0}</td>
                    <td>
                      <span className={`status status-${availabilityInfo(scholarship).tone}`}>
                        {availabilityInfo(scholarship).label}
                      </span>
                    </td>
                    <td>
                      <button
                        className="button button-small button-secondary"
                        onClick={() => setManagingId(scholarship.id)}
                      >
                        Manage
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {managingId && (
        <ManageScholarshipModal
          scholarshipId={managingId}
          onClose={() => {
            setManagingId(null);
            load();
          }}
          onDeleted={() => {
            setManagingId(null);
            load();
          }}
        />
      )}
    </div>
  );
}

function ManageScholarshipModal({ scholarshipId, onClose, onDeleted }) {
  const toast = useToast();
  const confirm = useConfirm();

  const [form, setForm] = useState(null);
  const [requirements, setRequirements] = useState([]);
  const [newRequirement, setNewRequirement] = useState("");
  const [saving, setSaving] = useState(false);
  const [applicationCount, setApplicationCount] = useState(0);

  const load = useCallback(
    () =>
      api
        .get(`/scholarships/${scholarshipId}`)
        .then(({ data }) => {
          setApplicationCount(data.applications_count || 0);

          setForm({
            name: data.name || "",
            provider: data.provider || "",
            category: data.category || "",
            description: data.description || "",
            amount: data.amount ?? "",
            application_start: data.application_start ? String(data.application_start).slice(0, 10) : "",
            application_end: data.application_end ? String(data.application_end).slice(0, 10) : "",
            status: data.status || "active",
          });
          setRequirements(data.requirements || []);
        })
        .catch((err) => toast.error(errMsg(err, "Unable to load scholarship."))),
    [scholarshipId, toast]
  );

  useEffect(() => {
    load();
  }, [load]);

  async function save(event) {
    event.preventDefault();
    setSaving(true);

    try {
      await api.put(`/scholarships/${scholarshipId}`, {
        ...form,
        category: form.category || null,
        amount: form.amount === "" ? null : Number(form.amount),
        application_start: form.application_start || null,
        application_end: form.application_end || null,
      });

      toast.success("Scholarship saved.");
    } catch (err) {
      toast.error(errMsg(err, "Unable to save scholarship."));
    } finally {
      setSaving(false);
    }
  }

  async function addRequirement(event) {
    event.preventDefault();

    if (!newRequirement.trim()) return;

    try {
      await api.post(`/scholarships/${scholarshipId}/requirements`, {
        name: newRequirement.trim(),
        is_required: true,
      });

      setNewRequirement("");
      await load();
      toast.success("Requirement added.");
    } catch (err) {
      toast.error(errMsg(err, "Unable to add requirement."));
    }
  }

  async function removeRequirement(requirement) {
    const ok = await confirm({
      title: "Remove requirement?",
      message: `"${requirement.name}" will no longer be required for this scholarship.`,
      confirmLabel: "Remove",
      tone: "danger",
    });

    if (!ok) return;

    try {
      await api.delete(`/requirements/${requirement.id}`);
      await load();
      toast.success("Requirement removed.");
    } catch (err) {
      toast.error(errMsg(err, "Unable to remove requirement."));
    }
  }

  async function deleteScholarship() {
    const ok = await confirm({
      title: "Delete this scholarship?",
      message: `"${form.name}" and its requirement list will be removed permanently.`,
      confirmLabel: "Delete",
      tone: "danger",
    });

    if (!ok) return;

    try {
      await api.delete(`/scholarships/${scholarshipId}`);
      toast.success("Scholarship deleted.");
      onDeleted();
    } catch (err) {
      toast.error(errMsg(err, "Unable to delete scholarship."));
    }
  }

  const field = (key) => (event) => setForm({ ...form, [key]: event.target.value });

  return (
    <Modal size="large" title="Manage Scholarship" onClose={onClose}>
      {!form ? (
        <Loading />
      ) : (
        <>
          <form className="form-grid" onSubmit={save}>
            <div className="full-column">
              <label htmlFor="edit-name">Name</label>
              <input id="edit-name" value={form.name} onChange={field("name")} required />
            </div>

            <div>
              <label htmlFor="edit-provider">Provider</label>
              <input id="edit-provider" value={form.provider} onChange={field("provider")} />
            </div>

            <div>
              <label htmlFor="edit-category">Type</label>
              <CategorySelect id="edit-category" value={form.category} onChange={field("category")} />
            </div>

            <div>
              <label htmlFor="edit-amount">Amount per semester</label>
              <input id="edit-amount" type="number" min="0" step="0.01" value={form.amount} onChange={field("amount")} />
            </div>

            <div>
              <label htmlFor="edit-start">Application start</label>
              <input id="edit-start" type="date" value={form.application_start} onChange={field("application_start")} />
            </div>

            <div>
              <label htmlFor="edit-end">Deadline</label>
              <input id="edit-end" type="date" value={form.application_end} onChange={field("application_end")} />
            </div>

            <div>
              <label htmlFor="edit-status">Status</label>
              <select id="edit-status" value={form.status} onChange={field("status")}>
                <option value="active">Active (students can apply between the dates)</option>
                <option value="inactive">Inactive (hidden from students)</option>
                <option value="closed">Closed (no new applications)</option>
              </select>
            </div>

            <div className="full-column">
              <label htmlFor="edit-description">Description</label>
              <textarea id="edit-description" value={form.description} onChange={field("description")} />
            </div>

            <div>
              <button className="button button-primary" disabled={saving}>
                {saving ? "Saving..." : "Save changes"}
              </button>
            </div>
          </form>

          <hr />

          <h3>Requirements</h3>

          {requirements.length === 0 ? (
            <EmptyState message="No requirements yet." />
          ) : (
            requirements.map((requirement) => (
              <div className="list-item" key={requirement.id}>
                <span>
                  {requirement.name}
                  {requirement.is_required && <span className="required">Required</span>}
                </span>

                <button
                  className="button button-small button-danger"
                  onClick={() => removeRequirement(requirement)}
                >
                  Remove
                </button>
              </div>
            ))
          )}

          <form className="inline-form" onSubmit={addRequirement}>
            <label htmlFor="new-requirement" className="sr-only">
              New requirement
            </label>

            <input
              id="new-requirement"
              value={newRequirement}
              onChange={(event) => setNewRequirement(event.target.value)}
              placeholder="e.g. Certificate of Registration (COR)"
            />

            <button className="button button-secondary">Add requirement</button>
          </form>

          <hr />

          <div className="danger-zone">
            <div>
              <strong>Delete scholarship</strong>
              <p className="muted small">
                {applicationCount > 0
                  ? `This program has ${applicationCount} application(s), so it cannot be deleted. Set its status to Closed instead; the records stay.`
                  : "Only programs without any applications can be deleted."}
              </p>
            </div>

            <button
              type="button"
              className="button button-small button-danger"
              onClick={deleteScholarship}
              disabled={applicationCount > 0}
            >
              Delete
            </button>
          </div>
        </>
      )}
    </Modal>
  );
}
