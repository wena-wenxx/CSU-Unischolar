import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import api, { errMsg } from "../../services/api";
import { announcementImage, formatDate } from "../../lib/format";
import AnnouncementImage from "../../components/AnnouncementImage";
import { useToast } from "../../lib/toast";
import Modal from "../../components/Modal";
import { useConfirm } from "../../lib/confirm";
import PageHeader from "../../components/PageHeader";
import EmptyState from "../../components/EmptyState";
import Loading from "../../components/Loading";

const EMPTY = { title: "", body: "", expires_at: "", image_path: null };
const MAX_IMAGE = 2 * 1024 * 1024; // 2 MB

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

  // Written with .then() (not await) so React's lint rule can see that the
  // state is set later, when the server answers, not during the effect.
  const load = useCallback(
    () =>
      api
        .get("/announcements")
        .then((response) => setItems(response.data))
        .catch((err) => {
          toast.error(errMsg(err, "Unable to load announcements."));
          setItems([]);
        }),
    [toast]
  );

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
              <article className={expired ? "announcement with-image expired" : "announcement with-image"} key={item.id}>
                <AnnouncementImage item={item} className="thumb" />
                <div className="announcement-main">
                <div className="announcement-head">
                  <div>
                    <Link to={`/staff/announcements/${item.id}`}>
                      <strong>{item.title}</strong>
                    </Link>{" "}
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
                          image_path: item.image_path,
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
                </div>
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
  const [image, setImage] = useState(null); // new File
  const [previewUrl, setPreviewUrl] = useState(null);
  const [removeImage, setRemoveImage] = useState(false);

  const field = (key) => (event) => setForm({ ...form, [key]: event.target.value });

  // Free the preview picture's memory when it changes or the window closes.
  useEffect(() => () => previewUrl && URL.revokeObjectURL(previewUrl), [previewUrl]);

  function chooseImage(event) {
    const file = event.target.files?.[0] || null;
    if (!file) return;

    if (!/^image\/(jpeg|png|webp)$/.test(file.type)) {
      toast.error("Choose a JPG, PNG or WebP picture.");
      event.target.value = "";
      return;
    }
    if (file.size > MAX_IMAGE) {
      toast.error("The picture is larger than 2 MB. Make it smaller (800 × 450 is enough).");
      event.target.value = "";
      return;
    }

    setImage(file);
    setRemoveImage(false);
    setPreviewUrl(URL.createObjectURL(file));
  }

  async function save(event) {
    event.preventDefault();
    setSaving(true);

    // FormData so a picture can be sent; an edit is POST + _method=PUT.
    const data = new FormData();
    data.append("title", form.title.trim());
    data.append("body", form.body.trim());
    data.append("expires_at", form.expires_at || "");
    if (image) data.append("image", image);
    if (removeImage) data.append("remove_image", "1");
    if (form.id) data.append("_method", "PUT");

    try {
      if (form.id) {
        await api.post(`/announcements/${form.id}`, data, { headers: { "Content-Type": "multipart/form-data" } });
        toast.success("Announcement updated.");
      } else {
        await api.post("/announcements", data, { headers: { "Content-Type": "multipart/form-data" } });
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

        <div className="full-column announcement-picture">
          <label htmlFor="announcement-image">Picture (optional)</label>
          <div className="picture-row">
            {previewUrl ? (
              <img className="announcement-image thumb" src={previewUrl} alt="" />
            ) : (
              <AnnouncementImage item={removeImage ? {} : form} className="thumb" />
            )}
            <div>
              <input id="announcement-image" type="file" accept="image/jpeg,image/png,image/webp" onChange={chooseImage} />
              <p className="muted small">
                JPG, PNG or WebP, up to 2 MB. Best size: 800 × 450 (landscape). Without a picture, the CSU logo is shown.
              </p>
              {(image || (announcementImage(form) && !removeImage)) && (
                <button
                  type="button"
                  className="link-button small"
                  onClick={() => {
                    setImage(null);
                    setPreviewUrl(null);
                    setRemoveImage(Boolean(form.id && form.image_path));
                    document.getElementById("announcement-image").value = "";
                  }}
                >
                  Remove picture
                </button>
              )}
            </div>
          </div>
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
