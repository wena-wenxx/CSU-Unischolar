import { useCallback, useEffect, useState } from "react";
import api, { errMsg } from "../../services/api";
import { useToast } from "../../lib/toast";
import PageHeader from "../../components/PageHeader";
import Loading from "../../components/Loading";

/*
  System Settings (admin):
  - current school year and semester (the default payroll period),
  - OAS contact details shown to students (Contact OAS page and page footer).
  - signatories printed on the General Payroll (General Form No. 6-A).
*/
export default function SettingsPage() {
  const toast = useToast();
  const [form, setForm] = useState(null);
  const [meta, setMeta] = useState({ semesters: [], current_period: "", signatory_slots: {} });
  const [saving, setSaving] = useState(false);

  const fill = (values) =>
    Object.fromEntries(Object.entries(values).map(([key, value]) => [key, value ?? ""]));

  // .then() (not await) so React's lint rule sees the state is set later.
  const load = useCallback(
    () =>
      api
        .get("/admin/settings")
        .then(({ data }) => {
          setForm(fill(data.values));
          setMeta({ semesters: data.semesters, current_period: data.current_period, signatory_slots: data.signatory_slots || {} });
        })
        .catch((err) => toast.error(errMsg(err, "Unable to load the settings."))),
    [toast]
  );

  useEffect(() => {
    load();
  }, [load]);

  async function save(event) {
    event.preventDefault();

    if (Boolean(form.current_school_year) !== Boolean(form.current_semester)) {
      toast.error("Set both the school year and the semester, or leave both empty.");
      return;
    }

    setSaving(true);
    try {
      const { data } = await api.put("/admin/settings", form);
      toast.success(data.message);
      setForm(fill(data.values));
      setMeta((current) => ({ ...current, current_period: data.current_period }));
    } catch (err) {
      toast.error(errMsg(err, "Unable to save the settings."));
    } finally {
      setSaving(false);
    }
  }

  if (!form) return <Loading />;

  const field = (key) => (event) => setForm({ ...form, [key]: event.target.value });

  return (
    <div>
      <PageHeader title="System Settings" subtitle="Current term and the OAS contact details students see" />

      <form onSubmit={save}>
        <section className="card">
          <h2>Current term</h2>
          <p className="muted small">
            Used as the default period in Payroll and shown at the bottom of every page. Leave both empty to let the
            system work it out from today's date. Now: <strong>{meta.current_period}</strong>
          </p>

          <div className="form-grid">
            <div>
              <label htmlFor="set-year">School year</label>
              <input
                id="set-year"
                value={form.current_school_year}
                onChange={field("current_school_year")}
                placeholder="e.g. 2026-2027"
                pattern="\d{4}-\d{4}"
                title="Like 2026-2027"
              />
            </div>
            <div>
              <label htmlFor="set-semester">Semester</label>
              <select id="set-semester" value={form.current_semester} onChange={field("current_semester")}>
                <option value="">Work it out from the date</option>
                {meta.semesters.map((semester) => (
                  <option key={semester} value={semester}>
                    {semester}
                  </option>
                ))}
              </select>
            </div>
          </div>
          <p className="muted small">
            Application deadlines are set per program in Scholarships (Applications open / Deadline).
          </p>
        </section>

        <section className="card">
          <h2>OAS contact details</h2>
          <p className="muted small">
            Shown to students on Contact OAS and at the bottom of every page. Only enter details confirmed by the OAS;
            empty fields are simply not shown.
          </p>

          <div className="form-grid">
            <div>
              <label htmlFor="set-hours">Office hours</label>
              <input id="set-hours" value={form.oas_office_hours} onChange={field("oas_office_hours")} maxLength={255} />
            </div>
            <div>
              <label htmlFor="set-location">Location</label>
              <input id="set-location" value={form.oas_location} onChange={field("oas_location")} maxLength={255} />
            </div>
            <div>
              <label htmlFor="set-email">E-mail</label>
              <input id="set-email" type="email" value={form.oas_email} onChange={field("oas_email")} maxLength={255} />
            </div>
            <div>
              <label htmlFor="set-phone">Phone</label>
              <input id="set-phone" value={form.oas_phone} onChange={field("oas_phone")} maxLength={60} />
            </div>
            <div>
              <label htmlFor="set-head">Head of office</label>
              <input id="set-head" value={form.oas_head} onChange={field("oas_head")} maxLength={255} />
            </div>
            <div>
              <label htmlFor="set-facebook">Facebook page</label>
              <input id="set-facebook" type="url" value={form.oas_facebook} onChange={field("oas_facebook")} maxLength={255} />
            </div>
            <div className="full-column">
              <label htmlFor="set-about">About the OAS (shown on Contact OAS)</label>
              <textarea id="set-about" value={form.oas_about} onChange={field("oas_about")} maxLength={2000} rows={4} />
            </div>
          </div>
        </section>

        <section className="card">
          <h2>Payroll form signatories</h2>
          <p className="muted small">
            Printed at the bottom of the General Payroll (General Form No. 6-A). Type each official&apos;s name as it should
            appear; change them when the officials change. &quot;Prepared by&quot; is the staff member who makes the form.
          </p>

          <div className="signatory-grid">
            <span className="field-label">On the form</span>
            <span className="field-label">Name</span>
            <span className="field-label">Position</span>
            {Object.entries(meta.signatory_slots).map(([slot, place]) => (
              <SignatoryRow key={slot} slot={slot} place={place} form={form} field={field} />
            ))}
          </div>
        </section>

        <div className="button-row">
          <button className="button button-primary" disabled={saving}>
            {saving ? "Saving..." : "Save settings"}
          </button>
        </div>
      </form>
    </div>
  );
}

function SignatoryRow({ slot, place, form, field }) {
  const name = `payroll_${slot}_name`;
  const title = `payroll_${slot}_title`;
  return (
    <>
      <span className="signatory-place">{place}</span>
      <div>
        <label htmlFor={`sig-${slot}-name`} className="sr-only">
          {place}: name
        </label>
        <input
          id={`sig-${slot}-name`}
          value={form[name] ?? ""}
          onChange={field(name)}
          maxLength={120}
          placeholder="Full name, e.g. Juan A. Dela Cruz, PhD"
        />
      </div>
      <div>
        <label htmlFor={`sig-${slot}-title`} className="sr-only">
          {place}: position
        </label>
        <input id={`sig-${slot}-title`} value={form[title] ?? ""} onChange={field(title)} maxLength={120} />
      </div>
    </>
  );
}
