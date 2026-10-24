import { useRef, useState } from "react";
import api, { errMsg } from "../services/api";
import { useAuth } from "../lib/auth";
import { useToast } from "../lib/toast";
import Avatar from "./Avatar";

/* Upload, change or remove your own profile photo (shown in the sidebar). */
const TYPES = ["image/jpeg", "image/png", "image/webp"];

export default function ProfilePhoto({ name }) {
  const { user, updateUser } = useAuth();
  const toast = useToast();
  const input = useRef(null);
  const [busy, setBusy] = useState(false);

  async function upload(event) {
    const file = event.target.files?.[0];
    event.target.value = "";
    if (!file) return;
    if (!TYPES.includes(file.type)) return toast.error("Use a JPG, PNG or WebP picture.");
    if (file.size > 2 * 1024 * 1024) return toast.error("The photo must be 2 MB or smaller.");

    setBusy(true);
    try {
      const form = new FormData();
      form.append("photo", file);
      const { data } = await api.post("/profile/photo", form);
      updateUser({ ...user, ...data.user });
      toast.success(data.message);
    } catch (err) {
      toast.error(errMsg(err, "Unable to save the photo."));
    } finally {
      setBusy(false);
    }
  }

  async function remove() {
    setBusy(true);
    try {
      const { data } = await api.delete("/profile/photo");
      updateUser({ ...user, ...data.user });
      toast.success(data.message);
    } catch (err) {
      toast.error(errMsg(err, "Unable to remove the photo."));
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="profile-photo">
      <Avatar user={user} name={name} className="avatar-xl" />
      <div className="profile-photo-actions">
        <input ref={input} id="photo-file" type="file" accept="image/jpeg,image/png,image/webp" onChange={upload} hidden />
        <button type="button" className="button button-small button-secondary" disabled={busy} onClick={() => input.current?.click()}>
          {busy ? "Saving..." : user.photo_path ? "Change photo" : "Upload photo"}
        </button>
        {user.photo_path && (
          <button type="button" className="link-button small" disabled={busy} onClick={remove}>
            Remove
          </button>
        )}
        <small className="muted">A clear photo of your face. JPG, PNG or WebP, up to 2 MB.</small>
      </div>
    </div>
  );
}
