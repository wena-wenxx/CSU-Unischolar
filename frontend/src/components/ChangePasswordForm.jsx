import { useState } from "react";
import api, { errMsg } from "../services/api";
import { useAuth } from "../lib/auth";
import { useToast } from "../lib/toast";

/*
  Change my password: current password, new password twice.
  The new one needs 8+ characters with at least one letter and one number.
  onDone runs after a successful change.
*/
export default function ChangePasswordForm({ onDone }) {
  const toast = useToast();
  const { updateUser } = useAuth();

  const [form, setForm] = useState({ current_password: "", password: "", password_confirmation: "" });
  const [show, setShow] = useState(false);
  const [saving, setSaving] = useState(false);

  const field = (key) => (event) => setForm({ ...form, [key]: event.target.value });
  const longEnough = form.password.length >= 8;
  const mixed = /[A-Za-z]/.test(form.password) && /[0-9]/.test(form.password);
  const same = form.password !== "" && form.password === form.password_confirmation;

  async function save(event) {
    event.preventDefault();
    setSaving(true);

    try {
      const { data } = await api.post("/change-password", form);
      updateUser(data.user);
      toast.success("Password changed.");
      setForm({ current_password: "", password: "", password_confirmation: "" });
      onDone?.(data.user);
    } catch (err) {
      toast.error(errMsg(err, "Unable to change the password."));
    } finally {
      setSaving(false);
    }
  }

  const type = show ? "text" : "password";

  return (
    <form className="password-form" onSubmit={save}>
      <label htmlFor="pw-current">Current (or temporary) password</label>
      <input id="pw-current" type={type} autoComplete="current-password" value={form.current_password} onChange={field("current_password")} required />

      <label htmlFor="pw-new">New password</label>
      <input id="pw-new" type={type} autoComplete="new-password" value={form.password} onChange={field("password")} required />

      <label htmlFor="pw-confirm">Type the new password again</label>
      <input id="pw-confirm" type={type} autoComplete="new-password" value={form.password_confirmation} onChange={field("password_confirmation")} required />

      <ul className="password-rules" aria-live="polite">
        <li className={longEnough ? "ok" : ""}>{longEnough ? "✓" : "○"} At least 8 characters</li>
        <li className={mixed ? "ok" : ""}>{mixed ? "✓" : "○"} At least one letter and one number</li>
        <li className={same ? "ok" : ""}>{same ? "✓" : "○"} Both new passwords match</li>
      </ul>

      <label className="choice small">
        <input type="checkbox" checked={show} onChange={(event) => setShow(event.target.checked)} />
        Show passwords
      </label>

      <button className="button button-primary" disabled={saving || !longEnough || !mixed || !same}>
        {saving ? "Saving..." : "Change password"}
      </button>
    </form>
  );
}
