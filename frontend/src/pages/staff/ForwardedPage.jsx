import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import api, { errMsg } from "../../services/api";
import { downloadCSV, formatDate, fullName } from "../../lib/format";
import { useToast } from "../../lib/toast";
import PageHeader from "../../components/PageHeader";
import EmptyState from "../../components/EmptyState";
import Loading from "../../components/Loading";

/*
  Forwarded to Agency (staff): every application OAS has sent to an agency
  (status "complete") and is still waiting for the agency's answer.
  Export a CSV per program to send to the agency. When the agency answers,
  record it in Approved Lists (or per application in Applications).
  The CSV is a plain list of the student details OAS keeps; it is NOT any
  agency's official template.
*/
const stamp = () => new Date().toLocaleDateString("en-CA", { timeZone: "Asia/Manila" });
const DAY = 24 * 60 * 60 * 1000;

export default function ForwardedPage() {
  const toast = useToast();

  const [rows, setRows] = useState(null);
  const [programId, setProgramId] = useState("all");
  const [query, setQuery] = useState("");

  // .then() (not await) so React's lint rule sees the state is set later.
  const load = useCallback(
    () =>
      api
        .get("/applications")
        .then(({ data }) => {
          const now = Date.now();
          setRows(
            data
              .filter((a) => a.status === "complete")
              .map((a) => ({
                ...a,
                waiting: a.forwarded_at ? Math.max(0, Math.floor((now - new Date(a.forwarded_at).getTime()) / DAY)) : null,
              }))
              .sort((a, b) => (b.waiting ?? -1) - (a.waiting ?? -1))
          );
        })
        .catch((err) => {
          toast.error(errMsg(err, "Unable to load forwarded applications."));
          setRows([]);
        }),
    [toast]
  );

  useEffect(() => {
    load();
  }, [load]);

  if (!rows) return <Loading />;

  const programs = [...new Map(rows.map((a) => [a.scholarship_id, a.scholarship])).values()].sort((a, b) =>
    String(a?.name).localeCompare(String(b?.name))
  );
  const words = query.toLowerCase().split(/\s+/).filter(Boolean);
  const shown = rows.filter((a) => {
    if (programId !== "all" && String(a.scholarship_id) !== programId) return false;
    const haystack = `${fullName(a.student)} ${a.student?.student_id || ""}`.toLowerCase();
    return words.every((word) => haystack.includes(word));
  });

  function exportCSV() {
    const program = programs.find((p) => String(p?.id) === programId);
    const code = program ? program.name.replace(/[^a-z0-9]+/gi, "-").toLowerCase().replace(/^-|-$/g, "") : "all-programs";
    const ok = downloadCSV(
      `forwarded-${code}-${stamp()}.csv`,
      shown.map((a) => ({
        "Student ID": a.student?.student_id,
        "Last name": a.student?.last_name,
        "First name": a.student?.first_name,
        "Middle name": a.student?.middle_name || "",
        Sex: a.student?.sex || "",
        Course: a.student?.course || "",
        "Year level": a.student?.year_level || "",
        College: a.student?.college || "",
        "Contact number": a.student?.contact_number || "",
        Email: a.student?.user?.email || "",
        Scholarship: a.scholarship?.name,
        Agency: a.scholarship?.provider || "",
        "Forwarded on": a.forwarded_at ? formatDate(a.forwarded_at) : "",
      }))
    );
    if (!ok) toast.info("Nothing to export.");
  }

  return (
    <div>
      <PageHeader
        title="Forwarded to Agency"
        subtitle="Complete applications OAS sent to the agencies, waiting for their decision"
        actions={
          <div className="button-row">
            <button className="button button-secondary" onClick={exportCSV} disabled={!shown.length}>
              Export CSV ({shown.length})
            </button>
            <Link className="button button-primary" to="/staff/agency-lists">
              Record an agency's answer
            </Link>
          </div>
        }
      />

      <section className="card">
        <div className="program-chips">
          <button
            className={programId === "all" ? "chip active" : "chip"}
            aria-pressed={programId === "all"}
            onClick={() => setProgramId("all")}
          >
            All programs <strong>{rows.length}</strong>
          </button>
          {programs.map((p) => {
            const count = rows.filter((a) => a.scholarship_id === p?.id).length;
            return (
              <button
                key={p?.id}
                className={programId === String(p?.id) ? "chip active" : "chip"}
                aria-pressed={programId === String(p?.id)}
                onClick={() => setProgramId(String(p?.id))}
              >
                {p?.name} <strong>{count}</strong>
              </button>
            );
          })}
        </div>

        <div className="inline-form table-search">
          <label htmlFor="fw-search" className="sr-only">
            Search
          </label>
          <input
            id="fw-search"
            type="search"
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            placeholder="Search name or Student ID"
          />
          <span className="muted">
            {shown.length} of {rows.length}, longest waiting first
          </span>
        </div>

        {shown.length === 0 ? (
          <EmptyState
            message="Nothing is waiting for an agency."
            action={
              <Link className="button button-secondary" to="/staff/auto-review">
                Open Auto-Review
              </Link>
            }
          />
        ) : (
          <div className="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>Student</th>
                  <th>Student ID</th>
                  <th>Course / year</th>
                  <th>Scholarship</th>
                  <th>Agency</th>
                  <th>Forwarded</th>
                  <th className="numeric">Days waiting</th>
                </tr>
              </thead>
              <tbody>
                {shown.map((a) => (
                  <tr key={a.id}>
                    <td>{fullName(a.student)}</td>
                    <td>{a.student?.student_id}</td>
                    <td>
                      {a.student?.course}
                      {a.student?.year_level ? ` · ${a.student.year_level}` : ""}
                    </td>
                    <td>{a.scholarship?.name}</td>
                    <td>{a.scholarship?.provider || "—"}</td>
                    <td>{a.forwarded_at ? formatDate(a.forwarded_at) : "—"}</td>
                    <td className="numeric">
                      {a.waiting === null ? "—" : (
                        <span className={a.waiting > 30 ? "text-warning" : ""}>{a.waiting}</span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        <p className="muted small">
          The CSV is a plain list of the student details OAS keeps, not an agency's official form. Over 30 days waiting
          is shown in orange so OAS can follow up.
        </p>
      </section>
    </div>
  );
}
