import { useCallback, useEffect, useState } from "react";
import api, { errMsg } from "../../services/api";
import { formatDate } from "../../lib/format";
import { useToast } from "../../components/Toast";
import Modal, { useConfirm } from "../../components/Modal";
import PageHeader from "../../components/PageHeader";
import EmptyState from "../../components/EmptyState";
import Loading from "../../components/Loading";

const EMPTY = { title: "", body: "", expires_at: "" };

const today = () => new Date().toLocaleDateString("en-CA", { timeZone: "Asia/Manila" });

/*
  OAS announcements: new openings, deadline reminders, payout schedules.
  Students see the latest three (not yet expired) on their dashboard.
*/
export default function StaffAnnouncementsPage() {
  const toast = useToast();
  const confirm = useConfirm();

  const [items, setItems] = useState(null);
  const [editing, setEditing] = useState(null); // null = closed, {} = new, {id,...} = edit

  const load = useCallback(async () => {
    try {
      const response = await api.get("/announcements");
      setItems(response.data);
    } catch (err) {
      toast.error(errMsg(err, "Unable to load announcements."));
      setItems([]);
    }
  }, [toast]);

  useEffect(() => {
    load();
  }, [load]);

  async function remove(item) {
    const ok = await confirm({
      title: "Delete announcement?",
      message: `"${item.title}" will be removed for everyone.`,
      confirmLabel: "Delete",
      tone: "danger",
    });

    if (!ok) return;

    try {
      await api.delete(`/announcements/${item.id}`);
      toast.success("Announcement deleted.");
      load();
    } catch (err) {
      toast.error(errMsg(err, "Unable to delete the announcement."));
    }
  }

  if (!items) return <Loading />;

  return (
    <div>
      <PageHeader
        title="Announcements"
        subtitle="Students see the latest three on their dashboard until the expiry date"
        actions={
          <button className="button button-primary" onClick={() => setEditing({ ...EMPTY })}>
            New announcement
          </button>
        }
      />

      <section className="card">
        {items.length === 0 ? (
          <EmptyState message="No announcements yet." />
        ) : (
          items.map((item) => {
            const expired = item.expires_at && String(item.expires_at).slice(0, 10) < today();

            return (
              <article className={expired ? "announcement expired" : "announcement"} key={item.id}>
                <div className="announcement-head">
                  <div>
                    <strong>{item.title}</strong>{" "}
                    {expired ? (
                      <span className="status status-neutral">Expired · hidden from students</span>
                    ) : (
                      <span className="status status-success">Shown to students</span>
                    )}
                    <small className="muted announcement-meta">
                      Posted {formatDate(item.posted_at)} by {item.author?.name || "OAS"}
                      {item.expires_at ? ` · shown until ${formatDate(item.expires_at)}` : " · no expiry"}
                    </small>
                  </div>

                  <div className="button-row">
                    <button
                      className="button button-small button-secondary"
                      onClick={() =>
                        setEditing({
                          id: item.id,
                          title: item.title,
                          body: item.body,
                          expires_at: item.expires_at ? String(item.expires_at).slice(0, 10) : "",
                        })
                      }
                    >
                      Edit
                    </button>
                    <button className="button button-small button-danger" onClick={() => remove(item)}>
                      Delete
                    </button>
                  </div>
                </div>

                <p>{item.body}</p>
              </article>
            );
          })
        )}
      </section>

      {editing && (
        <AnnouncementModal
          initial={editing}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null);
            load();
          }}
        />
      )}
    </div>
  );
}

function AnnouncementModal({ initial, onClose, onSaved }) {
  const toast = useToast();
  const [form, setForm] = useState(initial);
  const [saving, setSaving] = useState(false);

  const field = (key) => (event) => setForm({ ...form, [key]: event.target.value });

  async function save(event) {
    event.preventDefault();
    setSaving(true);

    const payload = { title: form.title.trim(), body: form.body.trim(), expires_at: form.expires_at || null };

    try {
      if (form.id) {
        await api.put(`/announcements/${form.id}`, payload);
        toast.success("Announcement updated.");
      } else {
        await api.post("/announcements", payload);
        toast.success("Announcement posted. Students can see it now.");
      }
      onSaved();
    } catch (err) {
      toast.error(errMsg(err, "Unable to save the announcement."));
      setSaving(false);
    }
  }

  return (
    <Modal title={form.id ? "Edit announcement" : "New announcement"} onClose={onClose}>
      <form className="form-grid" onSubmit={save}>
        <div className="full-column">
          <label htmlFor="announcement-title">Title</label>
          <input id="announcement-title" value={form.title} onChange={field("title")} maxLength={150} required />
        </div>

        <div className="full-column">
          <label htmlFor="announcement-body">Message</label>
          <textarea id="announcement-body" value={form.body} onChange={field("body")} maxLength={5000} required rows={6} />
        </div>

        <div>
          <label htmlFor="announcement-expires">Show until (optional)</label>
          <input id="announcement-expires" type="date" value={form.expires_at} onChange={field("expires_at")} />
        </div>

        <div className="full-column button-row">
          <button type="button" className="button button-secondary" onClick={onClose}>
            Cancel
          </button>
          <button className="button button-primary" disabled={saving}>
            {saving ? "Saving..." : form.id ? "Save changes" : "Post announcement"}
          </button>
        </div>
      </form>
    </Modal>
  );
}
