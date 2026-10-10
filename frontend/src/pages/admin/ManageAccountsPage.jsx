import { useEffect, useState } from "react";
import { useLocation } from "react-router-dom";
import api, { errMsg } from "../../services/api";
import { ROLE_LABELS, useAuth } from "../../lib/auth";
import { formatDateTime } from "../../lib/format";
import { useToast } from "../../lib/toast";
import { useConfirm } from "../../lib/confirm";
import Modal from "../../components/Modal";
import PageHeader from "../../components/PageHeader";
import EmptyState from "../../components/EmptyState";

/*
  Manage Staff (kind="office": staff and admins) and Manage Students (kind="student").
  Create accounts, edit details, reset passwords, deactivate / reactivate.
  Accounts are never deleted, so every record keeps its history.
*/
const YEAR_LEVELS = ["1st Year", "2nd Year", "3rd Year", "4th Year", "5th Year"];

const EMPTY = {
  office: { role: "staff", name: "", email: "", password: "" },
  student: {
    role: "student",
    first_name: "",
    middle_name: "",
    last_name: "",
    sex: "",
    student_id: "",
    email: "",
    course: "",
    year_level: "",
    college: "",
    contact_number: "",
    password: "",
  },
};

export default function ManageAccountsPage({ kind }) {
  const toast = useToast();
  const confirm = useConfirm();
  const { user: me } = useAuth();
  const location = useLocation();
  const params = new URLSearchParams(location.search);
  const isStudent = kind === "student";

  const [query, setQuery] = useState(() => params.get("q") || "");
  const [status, setStatus] = useState(() => (["active", "inactive"].includes(params.get("status")) ? params.get("status") : ""));
  const [role, setRole] = useState(isStudent ? "student" : "office");
  const [rows, setRows] = useState([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(false);
  const [loading, setLoading] = useState(false);
  const [editing, setEditing] = useState(() => (params.get("new") === "1" ? { ...EMPTY[kind] } : null));
  const [secret, setSecret] = useState(null); // { name, email, password } shown once

  // One page of accounts. Page 1 replaces the list; later pages are added ("Show more").
  function fetchPage(nextPage, filters = { query, status, role }) {
    return api
      .get("/admin/users", { params: { q: filters.query, status: filters.status || undefined, role: filters.role, page: nextPage } })
      .then(({ data }) => {
        setRows((current) => (nextPage === 1 ? data.data : [...current, ...data.data]));
        setTotal(data.total);
        setPage(data.page);
        setHasMore(data.has_more);
      })
      .catch((err) => toast.error(errMsg(err, "Unable to load accounts.")));
  }

  useEffect(() => {
    fetchPage(1);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function refilter(changes) {
    const next = { query, status, role, ...changes };
    if ("status" in changes) setStatus(changes.status);
    if ("role" in changes) setRole(changes.role);
    setLoading(true);
    await fetchPage(1, next);
    setLoading(false);
  }

  async function more() {
    setLoading(true);
    await fetchPage(page + 1);
    setLoading(false);
  }

  async function resetPassword(account) {
    const ok = await confirm({
      title: `Reset the password of ${account.name}?`,
      message: "A new temporary password is made. The user is signed out everywhere and must choose a new password at the next login.",
      confirmLabel: "Reset password",
    });
    if (!ok) return;

    try {
      const { data } = await api.post(`/admin/users/${account.id}/reset-password`);
      setSecret({ name: account.name, email: account.email, password: data.temporary_password, reset: true });
      await fetchPage(1);
    } catch (err) {
      toast.error(errMsg(err, "Unable to reset the password."));
    }
  }

  async function setActive(account, active) {
    if (!active) {
      const ok = await confirm({
        title: `Deactivate ${account.name}?`,
        message: "They are signed out and cannot log in. Their records and history stay. You can reactivate the account later.",
        confirmLabel: "Deactivate",
        tone: "danger",
      });
      if (!ok) return;
    }

    try {
      const { data } = await api.post(`/admin/users/${account.id}/active`, { active });
      toast.success(data.message);
      await fetchPage(1);
    } catch (err) {
      toast.error(errMsg(err, "Unable to change the account."));
    }
  }

  function edit(account) {
    if (isStudent) {
      const s = account.student || {};
      setEditing({
        id: account.id,
        role: "student",
        first_name: s.first_name || "",
        middle_name: s.middle_name || "",
        last_name: s.last_name || "",
        sex: s.sex || "",
        student_id: s.student_id || "",
        email: account.email,
        course: s.course || "",
        year_level: s.year_level || "",
        college: s.college || "",
        contact_number: s.contact_number || "",
      });
    } else {
      setEditing({ id: account.id, role: account.role, name: account.name, email: account.email });
    }
  }

  return (
    <div>
      <PageHeader
        title={isStudent ? "Manage Students" : "Manage Staff"}
        subtitle={
          isStudent
            ? "Student login accounts. Registrar details can be corrected here."
            : "OAS staff and System Admin accounts"
        }
        actions={
          <button className="button button-primary" onClick={() => setEditing({ ...EMPTY[kind] })}>
            New {isStudent ? "student" : "staff"} account
          </button>
        }
      />

      <section className="card">
        <form
          className="inline-form table-search"
          onSubmit={(event) => {
            event.preventDefault();
            refilter({});
          }}
        >
          <label htmlFor="account-search" className="sr-only">
            Search accounts
          </label>
          <input
            id="account-search"
            type="search"
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            placeholder={isStudent ? "Name, e-mail or Student ID" : "Name or e-mail"}
          />
          <button className="button button-primary" disabled={loading}>
            Search
          </button>

          {!isStudent && (
            <>
              <label htmlFor="account-role" className="sr-only">
                Role
              </label>
              <select id="account-role" value={role} onChange={(event) => refilter({ role: event.target.value })}>
                <option value="office">Staff and admins</option>
                <option value="staff">OAS Staff only</option>
                <option value="admin">System Admins only</option>
              </select>
            </>
          )}

          <label htmlFor="account-status" className="sr-only">
            Status
          </label>
          <select id="account-status" value={status} onChange={(event) => refilter({ status: event.target.value })}>
            <option value="">Active and deactivated</option>
            <option value="active">Active only</option>
            <option value="inactive">Deactivated only</option>
          </select>
        </form>

        <p className="muted small">
          Showing {rows.length} of {total} account{total === 1 ? "" : "s"}.
        </p>

        {rows.length === 0 ? (
          <EmptyState message="No accounts match." />
        ) : (
          <div className="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>Name</th>
                  {isStudent && <th>Student ID</th>}
                  <th>E-mail (login)</th>
                  {isStudent ? <th>Course / year</th> : <th>Role</th>}
                  <th>Status</th>
                  <th>Last login</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((account) => (
                  <tr key={account.id} className={account.is_active ? "" : "row-muted"}>
                    <td>
                      {isStudent && account.student ? (
                        <>
                          <strong>{account.student.last_name}</strong>, {account.student.first_name}
                        </>
                      ) : (
                        <strong>{account.name}</strong>
                      )}
                      {account.id === me.id && <span className="muted small"> (you)</span>}
                    </td>
                    {isStudent && <td>{account.student?.student_id || "—"}</td>}
                    <td>{account.email}</td>
                    {isStudent ? (
                      <td>
                        {account.student?.course || "—"}
                        {account.student?.year_level ? ` · ${account.student.year_level}` : ""}
                      </td>
                    ) : (
                      <td>
                        <span className={`status ${account.role === "admin" ? "status-warning" : "status-success"}`}>
                          {ROLE_LABELS[account.role]}
                        </span>
                      </td>
                    )}
                    <td>
                      {account.is_active ? (
                        <span className="status status-success">Active</span>
                      ) : (
                        <span className="status status-danger">Deactivated</span>
                      )}
                      {account.must_change_password && account.is_active && (
                        <small className="muted account-note">Temporary password</small>
                      )}
                    </td>
                    <td>{account.last_login_at ? formatDateTime(account.last_login_at) : "Never"}</td>
                    <td>
                      <div className="button-row">
                        <button className="button button-small button-secondary" onClick={() => edit(account)}>
                          Edit
                        </button>
                        <button className="button button-small button-secondary" onClick={() => resetPassword(account)}>
                          Reset password
                        </button>
                        {account.id !== me.id &&
                          (account.is_active ? (
                            <button className="button button-small button-danger" onClick={() => setActive(account, false)}>
                              Deactivate
                            </button>
                          ) : (
                            <button className="button button-small button-success" onClick={() => setActive(account, true)}>
                              Reactivate
                            </button>
                          ))}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {hasMore && (
          <div className="show-more">
            <button className="button button-secondary" onClick={more} disabled={loading}>
              {loading ? "Loading..." : `Show more (${total - rows.length} left)`}
            </button>
          </div>
        )}
      </section>

      {editing && (
        <AccountModal
          initial={editing}
          isStudent={isStudent}
          isSelf={editing.id === me.id}
          onClose={() => setEditing(null)}
          onSaved={async (result) => {
            setEditing(null);
            if (result.temporary_password) {
              setSecret({ name: result.user.name, email: result.user.email, password: result.temporary_password });
            } else {
              toast.success(result.message);
            }
            await fetchPage(1);
          }}
        />
      )}

      {secret && <PasswordModal secret={secret} onClose={() => setSecret(null)} />}
    </div>
  );
}

function AccountModal({ initial, isStudent, isSelf, onClose, onSaved }) {
  const toast = useToast();
  const [form, setForm] = useState(initial);
  const [saving, setSaving] = useState(false);
  const creating = !initial.id;

  const field = (key) => (event) => setForm({ ...form, [key]: event.target.value });

  async function save(event) {
    event.preventDefault();
    setSaving(true);

    const payload = Object.fromEntries(
      Object.entries(form)
        .filter(([key]) => key !== "id")
        .map(([key, value]) => [key, typeof value === "string" ? value.trim() || null : value])
    );
    if (!creating) {
      delete payload.password;
      if (isStudent) delete payload.role;
    }

    try {
      const { data } = creating
        ? await api.post("/admin/users", payload)
        : await api.patch(`/admin/users/${initial.id}`, payload);
      await onSaved(data);
    } catch (err) {
      toast.error(errMsg(err, "Unable to save the account."));
      setSaving(false);
    }
  }

  return (
    <Modal
      size="large"
      title={creating ? `New ${isStudent ? "student" : "staff"} account` : `Edit ${isStudent ? "student" : "staff"} account`}
      onClose={onClose}
    >
      <form className="form-grid" onSubmit={save}>
        {isStudent ? (
          <>
            <div>
              <label htmlFor="acc-first">First name</label>
              <input id="acc-first" value={form.first_name} onChange={field("first_name")} required maxLength={255} />
            </div>
            <div>
              <label htmlFor="acc-middle">Middle name (optional)</label>
              <input id="acc-middle" value={form.middle_name} onChange={field("middle_name")} maxLength={255} />
            </div>
            <div>
              <label htmlFor="acc-last">Last name</label>
              <input id="acc-last" value={form.last_name} onChange={field("last_name")} required maxLength={255} />
            </div>
            <div>
              <label htmlFor="acc-sex">Sex</label>
              <select id="acc-sex" value={form.sex} onChange={field("sex")}>
                <option value="">Not recorded</option>
                <option value="Female">Female</option>
                <option value="Male">Male</option>
              </select>
            </div>
            <div>
              <label htmlFor="acc-sid">Student ID</label>
              <input id="acc-sid" value={form.student_id} onChange={field("student_id")} required maxLength={50} placeholder="e.g. 221-00462" />
            </div>
            <div>
              <label htmlFor="acc-email">E-mail (login)</label>
              <input id="acc-email" type="email" value={form.email} onChange={field("email")} required maxLength={255} />
            </div>
            <div>
              <label htmlFor="acc-course">Course</label>
              <input id="acc-course" value={form.course} onChange={field("course")} maxLength={255} />
            </div>
            <div>
              <label htmlFor="acc-year">Year level</label>
              <select id="acc-year" value={form.year_level} onChange={field("year_level")}>
                <option value="">Not recorded</option>
                {YEAR_LEVELS.map((y) => (
                  <option key={y} value={y}>
                    {y}
                  </option>
                ))}
              </select>
            </div>
            <div>
              <label htmlFor="acc-college">College</label>
              <input id="acc-college" value={form.college} onChange={field("college")} maxLength={255} />
            </div>
            <div>
              <label htmlFor="acc-contact">Contact number (optional)</label>
              <input id="acc-contact" inputMode="numeric" value={form.contact_number} onChange={field("contact_number")} maxLength={11} placeholder="09XXXXXXXXX" />
            </div>
          </>
        ) : (
          <>
            <div>
              <label htmlFor="acc-name">Full name</label>
              <input id="acc-name" value={form.name} onChange={field("name")} required maxLength={255} />
            </div>
            <div>
              <label htmlFor="acc-email">E-mail (login)</label>
              <input id="acc-email" type="email" value={form.email} onChange={field("email")} required maxLength={255} />
            </div>
            <div>
              <label htmlFor="acc-role">Role</label>
              <select id="acc-role" value={form.role} onChange={field("role")} disabled={isSelf}>
                <option value="staff">OAS Staff (processes applications)</option>
                <option value="admin">System Admin (accounts and settings)</option>
              </select>
              {isSelf && <p className="muted small">You cannot change your own role.</p>}
            </div>
          </>
        )}

        {creating && (
          <div>
            <label htmlFor="acc-password">Temporary password (optional)</label>
            <input
              id="acc-password"
              value={form.password}
              onChange={field("password")}
              minLength={8}
              maxLength={100}
              placeholder="Leave empty to make one"
              autoComplete="new-password"
            />
          </div>
        )}

        <p className="full-column muted small">
          {creating
            ? "The user must change the temporary password the first time they log in."
            : "To change the password, close this and press Reset password."}
        </p>

        <div className="full-column button-row">
          <button type="button" className="button button-secondary" onClick={onClose}>
            Cancel
          </button>
          <button className="button button-primary" disabled={saving}>
            {saving ? "Saving..." : creating ? "Create account" : "Save changes"}
          </button>
        </div>
      </form>
    </Modal>
  );
}

// The temporary password is shown only once.
function PasswordModal({ secret, onClose }) {
  const toast = useToast();

  function copy() {
    navigator.clipboard
      ?.writeText(secret.password)
      .then(() => toast.success("Password copied."))
      .catch(() => toast.info("Select the password and copy it."));
  }

  return (
    <Modal title={secret.reset ? "Password reset" : "Account created"} onClose={onClose}>
      <p>
        <strong>{secret.name}</strong> · {secret.email}
      </p>
      <p>Temporary password (shown only now):</p>
      <p className="temp-password">
        <code>{secret.password}</code>
        <button type="button" className="button button-small button-secondary" onClick={copy}>
          Copy
        </button>
      </p>
      <p className="muted small">
        Give it to the user privately (not in a group chat). They must choose their own password when they log in.
      </p>
      <div className="modal-footer">
        <button className="button button-primary" onClick={onClose}>
          Done
        </button>
      </div>
    </Modal>
  );
}
