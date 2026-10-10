import { useCallback, useEffect, useRef, useState } from "react";
import { Link, useLocation } from "react-router-dom";
import api, { errMsg } from "../../services/api";
import { downloadCSV, formatDate, formatMoney, fullName } from "../../lib/format";
import { useToast } from "../../lib/toast";
import { useConfirm } from "../../lib/confirm";
import PageHeader from "../../components/PageHeader";
import StatusBadge from "../../components/StatusBadge";
import EmptyState from "../../components/EmptyState";
import Loading from "../../components/Loading";
import SheetTabs from "../../components/SheetTabs";
import { byLastName, lastFirst, programTabs } from "../../lib/programs";

/*
  Payroll (status tracking only; no money is moved by this system).
  1. Prepare: choose the program and the period, preview who is included
     (each scholar gets their own program's amount), then confirm.
  2. Payroll list: filter, select, mark Ready / Processed, export CSV,
     print or save as PDF.
  3. History: totals per period and program.
*/

const STATUS_FILTERS = [
  ["all", "All"],
  ["draft", "Draft"],
  ["ready", "Ready"],
  ["processed", "Processed"],
];

const ATM_FILTERS = {
  all: "Any ATM status",
  funded: "ATM · funded",
  waiting: "ATM · funds pending / none",
  none: "No ATM yet",
};

const stamp = () => new Date().toLocaleDateString("en-CA", { timeZone: "Asia/Manila" });
const same = (a, b) => String(a || "").trim().toLowerCase() === String(b || "").trim().toLowerCase();

function atmGroup(record) {
  if (!record) return "none";
  if (!record.has_atm) return "none";
  return record.atm_funds === "yes" ? "funded" : "waiting";
}

export default function StaffPayrollPage() {
  const toast = useToast();
  const confirm = useConfirm();
  const location = useLocation();
  const listRef = useRef(null);

  const [payroll, setPayroll] = useState([]);
  const [scholarships, setScholarships] = useState([]);
  const [periods, setPeriods] = useState({ current: "", periods: [] });
  const [history, setHistory] = useState([]);
  const [loading, setLoading] = useState(true);

  // List filters. /staff/payroll?status=ready opens the list already filtered.
  const [statusFilter, setStatusFilter] = useState(() => {
    const wanted = new URLSearchParams(location.search).get("status");
    return STATUS_FILTERS.some(([key]) => key === wanted) ? wanted : "all";
  });
  const [periodFilter, setPeriodFilter] = useState("all");
  const [programFilter, setProgramFilter] = useState("all");
  const [atmFilter, setAtmFilter] = useState("all");
  const [query, setQuery] = useState("");
  const [selected, setSelected] = useState([]);
  const [bulkBusy, setBulkBusy] = useState(false);

  // Written with .then() (not await) so React's lint rule can see that the
  // state is set later, when the server answers, not during the effect.
  const load = useCallback(
    () =>
      Promise.all([api.get("/payroll"), api.get("/scholarships"), api.get("/payroll/periods"), api.get("/payroll/history")])
        .then(([payrollResponse, scholarshipsResponse, periodsResponse, historyResponse]) => {
          setPayroll(payrollResponse.data);
          setScholarships(scholarshipsResponse.data);
          setPeriods(periodsResponse.data);
          setHistory(historyResponse.data);
        })
        .catch((err) => toast.error(errMsg(err, "Unable to load payroll.")))
        .finally(() => setLoading(false)),
    [toast]
  );

  useEffect(() => {
    load();
  }, [load]);

  const words = query.toLowerCase().split(/\s+/).filter(Boolean);

  const filtered = payroll.filter((item) => {
    const record = item.scholar_record;
    if (statusFilter !== "all" && item.status !== statusFilter) return false;
    if (periodFilter !== "all" && !same(item.period, periodFilter)) return false;
    if (atmFilter !== "all" && atmGroup(record) !== atmFilter) return false;
    const haystack = `${lastFirst(record?.student)} ${record?.student?.student_id || ""}`.toLowerCase();
    return words.every((word) => haystack.includes(word));
  });
  // One tab per program (like sheets in Excel); each list is A to Z.
  const tabs = programTabs(filtered, (item) => item.scholar_record?.scholarship);
  const activeProgram = tabs.some((tab) => tab.key === programFilter) ? programFilter : "all";
  const shown = filtered
    .filter((item) => activeProgram === "all" || String(item.scholar_record?.scholarship_id) === activeProgram)
    .sort((a, b) => byLastName(a.scholar_record?.student, b.scholar_record?.student));

  const shownIds = shown.map((item) => item.id);
  const selectedShown = selected.filter((id) => shownIds.includes(id));
  const allSelected = shown.length > 0 && selectedShown.length === shown.length;
  const total = shown.reduce((sum, item) => sum + Number(item.amount || 0), 0);
  const usedPeriods = [...new Set(payroll.map((item) => item.period))];

  function toggle(id) {
    setSelected((current) => (current.includes(id) ? current.filter((x) => x !== id) : [...current, id]));
  }

  async function bulk(status, ids) {
    const label = { ready: "Ready", processed: "Processed", draft: "Draft" }[status];

    if (status === "processed") {
      const ok = await confirm({
        title: `Mark ${ids.length} entries as Processed?`,
        message: "Processed means the stipend was released. Do this only after the payout is confirmed.",
        confirmLabel: "Mark processed",
      });
      if (!ok) return;
    }

    setBulkBusy(true);

    try {
      const { data } = await api.post("/payroll/bulk-status", { ids, status });
      if (data.changed) toast.success(data.message);
      else toast.info(data.message);
      setSelected([]);
      await load();
    } catch (err) {
      toast.error(errMsg(err, `Unable to mark entries ${label}.`));
    } finally {
      setBulkBusy(false);
    }
  }

  function exportCSV() {
    const ok = downloadCSV(
      `payroll-${periodFilter === "all" ? "all-periods" : periodFilter.replace(/[^a-z0-9]+/gi, "-").toLowerCase()}-${stamp()}.csv`,
      shown.map((item) => ({
        "Student ID": item.scholar_record?.student?.student_id,
        "Last name": item.scholar_record?.student?.last_name,
        "First name": item.scholar_record?.student?.first_name,
        "Middle name": item.scholar_record?.student?.middle_name,
        Course: item.scholar_record?.student?.course,
        "Year level": item.scholar_record?.student?.year_level,
        Scholarship: item.scholar_record?.scholarship?.name,
        Period: item.period,
        Amount: Number(item.amount || 0).toFixed(2),
        "ATM status": item.scholar_record?.atm_label || item.bank_atm_status,
        Status: item.status,
        "Prepared by": item.preparer?.name || "",
      }))
    );
    if (!ok) toast.info("Nothing to export in this list.");
  }

  function openFromHistory(row) {
    setStatusFilter("all");
    setPeriodFilter(row.period);
    setProgramFilter(String(row.scholarship_id));
    setAtmFilter("all");
    setQuery("");
    listRef.current?.scrollIntoView({ behavior: "smooth", block: "start" });
  }

  if (loading) return <Loading />;

  return (
    <div>
      <PageHeader
        title="Payroll"
        subtitle="Prepare stipend lists for active, enrolled grantees. Status tracking only: no money is moved by this system."
      />

      <PreparePanel
        scholarships={scholarships}
        periods={periods}
        onPrepared={async (period) => {
          await load();
          setStatusFilter("draft");
          setPeriodFilter(period);
          setProgramFilter("all");
          listRef.current?.scrollIntoView({ behavior: "smooth", block: "start" });
        }}
      />

      <section className="card sheet-card" ref={listRef} id="payroll-list">
        <div className="card-header">
          <h2>2. Payroll list</h2>
          <div className="button-row">
            <button className="button button-small button-secondary" onClick={exportCSV}>
              Export CSV
            </button>
            <button className="button button-small button-secondary" onClick={() => window.print()}>
              Print / Save as PDF
            </button>
          </div>
        </div>

        <p className="muted small">Draft → Ready (checked) → Processed (stipend released). Lists are A to Z by last name.</p>

        <SheetTabs tabs={tabs} value={activeProgram} onChange={setProgramFilter} />

        <div className="filter-row" role="group" aria-label="Filter payroll by status">
          {STATUS_FILTERS.map(([key, label]) => (
            <button
              key={key}
              className={statusFilter === key ? "button button-small button-primary" : "button button-small button-secondary"}
              aria-pressed={statusFilter === key}
              onClick={() => setStatusFilter(key)}
            >
              {label} ({key === "all" ? payroll.length : payroll.filter((item) => item.status === key).length})
            </button>
          ))}
        </div>

        <div className="inline-form table-search payroll-filters">
          <label htmlFor="payroll-search" className="sr-only">
            Search
          </label>
          <input
            id="payroll-search"
            type="search"
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            placeholder="Search name or student ID"
          />

          <label htmlFor="payroll-period-filter" className="sr-only">
            Period
          </label>
          <select id="payroll-period-filter" value={periodFilter} onChange={(event) => setPeriodFilter(event.target.value)}>
            <option value="all">All periods</option>
            {usedPeriods.map((period) => (
              <option key={period} value={period}>
                {period}
              </option>
            ))}
          </select>

          <label htmlFor="payroll-atm-filter" className="sr-only">
            ATM status
          </label>
          <select id="payroll-atm-filter" value={atmFilter} onChange={(event) => setAtmFilter(event.target.value)}>
            {Object.entries(ATM_FILTERS).map(([key, label]) => (
              <option key={key} value={key}>
                {label}
              </option>
            ))}
          </select>
        </div>

        <div className="bulk-bar print-hide">
          <span>
            <strong>{selectedShown.length}</strong> selected · {shown.length} shown · total{" "}
            <strong>{formatMoney(total)}</strong>
          </span>
          <div className="button-row">
            <button
              className="button button-small button-primary"
              disabled={bulkBusy || !selectedShown.length}
              onClick={() => bulk("ready", selectedShown)}
            >
              Mark Ready
            </button>
            <button
              className="button button-small button-success"
              disabled={bulkBusy || !selectedShown.length}
              onClick={() => bulk("processed", selectedShown)}
            >
              Mark Processed
            </button>
            <button
              className="button button-small button-secondary"
              disabled={bulkBusy || !selectedShown.length}
              onClick={() => bulk("draft", selectedShown)}
            >
              Back to Draft
            </button>
          </div>
        </div>

        {shown.length === 0 ? (
          <EmptyState message="No payroll entries in this list." />
        ) : (
          <div className="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th className="print-hide">
                    <label className="sr-only" htmlFor="payroll-select-all">
                      Select all shown
                    </label>
                    <input
                      id="payroll-select-all"
                      type="checkbox"
                      checked={allSelected}
                      onChange={() =>
                        setSelected((current) =>
                          allSelected ? current.filter((id) => !shownIds.includes(id)) : [...new Set([...current, ...shownIds])]
                        )
                      }
                    />
                  </th>
                  <th>Student</th>
                  <th>Student ID</th>
                  <th>Scholarship</th>
                  <th>Period</th>
                  <th className="numeric">Amount</th>
                  <th>ATM</th>
                  <th>Status</th>
                </tr>
              </thead>

              <tbody>
                {shown.map((item) => (
                  <tr key={item.id} className={selected.includes(item.id) ? "row-selected" : ""}>
                    <td className="print-hide">
                      <label className="sr-only" htmlFor={`payroll-${item.id}`}>
                        Select {fullName(item.scholar_record?.student)}
                      </label>
                      <input
                        id={`payroll-${item.id}`}
                        type="checkbox"
                        checked={selected.includes(item.id)}
                        onChange={() => toggle(item.id)}
                      />
                    </td>
                    <td>{lastFirst(item.scholar_record?.student)}</td>
                    <td>{item.scholar_record?.student?.student_id}</td>
                    <td title={item.scholar_record?.scholarship?.name}>
                      {item.scholar_record?.scholarship?.short_name || item.scholar_record?.scholarship?.name}
                    </td>
                    <td>{item.period}</td>
                    <td className="numeric">{formatMoney(item.amount)}</td>
                    <td>
                      <AtmBadge record={item.scholar_record} />
                    </td>
                    <td>
                      <StatusBadge status={item.status} />
                    </td>
                  </tr>
                ))}
              </tbody>

              <tfoot>
                <tr>
                  <td className="print-hide" />
                  <td colSpan={4}>
                    <strong>Total ({shown.length} scholars)</strong>
                  </td>
                  <td className="numeric">
                    <strong>{formatMoney(total)}</strong>
                  </td>
                  <td colSpan={2} />
                </tr>
              </tfoot>
            </table>
          </div>
        )}
      </section>

      <section className="card print-hide">
        <h2>3. Payroll history</h2>

        {history.length === 0 ? (
          <EmptyState message="No payroll has been prepared yet." />
        ) : (
          <div className="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>Period</th>
                  <th>Scholarship</th>
                  <th className="numeric">Scholars</th>
                  <th className="numeric">Total</th>
                  <th>Draft / Ready / Processed</th>
                  <th>Last change</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {history.map((row) => (
                  <tr key={`${row.period}-${row.scholarship_id}`}>
                    <td>{row.period}</td>
                    <td>{row.scholarship}</td>
                    <td className="numeric">{row.scholars}</td>
                    <td className="numeric">{formatMoney(row.total_amount)}</td>
                    <td>
                      {row.draft} / {row.ready} / {row.processed}
                      {row.processed === row.scholars && <span className="status status-success history-done">Done</span>}
                    </td>
                    <td>{formatDate(row.last_change)}</td>
                    <td>
                      <button className="button button-small button-secondary" onClick={() => openFromHistory(row)}>
                        View
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </div>
  );
}

function AtmBadge({ record }) {
  if (!record) return "—";
  const group = atmGroup(record);
  const tone = group === "funded" ? "success" : group === "waiting" ? "warning" : "neutral";
  return <span className={`status status-${tone}`}>{record.atm_label}</span>;
}

/* ---------- 1. Prepare payroll: choose, preview, confirm ---------- */

function PreparePanel({ scholarships, periods, onPrepared }) {
  const toast = useToast();

  const [programId, setProgramId] = useState("all");
  const [period, setPeriod] = useState(periods.current || periods.periods[0] || "");
  const [amount, setAmount] = useState("");
  const [preview, setPreview] = useState(null);
  const [showSkipped, setShowSkipped] = useState(false);
  const [busy, setBusy] = useState(false);

  const program = scholarships.find((s) => String(s.id) === programId);

  function chooseProgram(id) {
    setProgramId(id);
    const chosen = scholarships.find((s) => String(s.id) === id);
    setAmount(chosen?.amount ? String(Number(chosen.amount)) : "");
    setPreview(null);
  }

  function body(confirmNow) {
    const payload = { period, confirm: confirmNow };
    if (program) {
      payload.scholarship_id = program.id;
      if (amount !== "" && Number(amount) !== Number(program.amount)) payload.amount = Number(amount);
    }
    return payload;
  }

  async function runPreview(event) {
    event.preventDefault();

    if (program && amount !== "" && !(Number(amount) > 0)) {
      toast.error("Type a valid amount, for example 5000.");
      return;
    }

    setBusy(true);
    try {
      const { data } = await api.post("/payroll/prepare", body(false));
      setPreview(data);
      setShowSkipped(false);
      if (!data.summary.included) toast.info("Nobody can be added: see the reasons in the list.");
    } catch (err) {
      toast.error(errMsg(err, "Unable to preview the payroll."));
    } finally {
      setBusy(false);
    }
  }

  async function confirmPayroll() {
    setBusy(true);
    try {
      const { data } = await api.post("/payroll/prepare", body(true));
      toast.success(data.message);
      setPreview(null);
      await onPrepared(data.summary.period);
    } catch (err) {
      toast.error(errMsg(err, "Unable to prepare the payroll."));
    } finally {
      setBusy(false);
    }
  }

  const rows = preview ? preview.rows.filter((row) => showSkipped || row.include) : [];

  return (
    <section className="card print-hide">
      <h2>1. Prepare payroll</h2>
      <p className="muted small">
        Only active grantees who are currently enrolled are included. Each scholar gets their own program's amount.
        Anyone already in the payroll for the chosen period is skipped, so pressing this twice is safe.
      </p>

      <form className="form-grid" onSubmit={runPreview}>
        <div>
          <label htmlFor="prep-program">Scholarship</label>
          <select id="prep-program" value={programId} onChange={(event) => chooseProgram(event.target.value)}>
            <option value="all">All programs (batch)</option>
            {scholarships.map((s) => (
              <option key={s.id} value={String(s.id)}>
                {s.name} · {formatMoney(s.amount)}
              </option>
            ))}
          </select>
        </div>

        <div>
          <label htmlFor="prep-period">Period</label>
          <select
            id="prep-period"
            value={period}
            onChange={(event) => {
              setPeriod(event.target.value);
              setPreview(null);
            }}
          >
            {periods.periods.map((p) => (
              <option key={p} value={p}>
                {p}
                {p === periods.current ? " (current)" : ""}
              </option>
            ))}
          </select>
        </div>

        <div>
          {program ? <label htmlFor="prep-amount">Amount per scholar (₱)</label> : <span className="field-label">Amount per scholar (₱)</span>}
          {program ? (
            <input
              id="prep-amount"
              type="number"
              min="1"
              step="0.01"
              value={amount}
              onChange={(event) => {
                setAmount(event.target.value);
                setPreview(null);
              }}
              placeholder="Program amount"
            />
          ) : (
            <p className="muted small prep-auto">
              Filled in automatically from each scholar's program.
            </p>
          )}
        </div>

        <div className="full-column button-row">
          <button className="button button-primary" disabled={busy || !period}>
            {busy && !preview ? "Checking..." : "Preview payroll"}
          </button>
          {program && amount !== "" && Number(amount) !== Number(program.amount) && (
            <span className="small text-warning">
              Different from the program amount ({formatMoney(program.amount)}).
            </span>
          )}
        </div>
      </form>

      {preview && (
        <div className="prep-preview">
          <div className="prep-summary">
            <span>
              <strong>{preview.summary.included}</strong> included
            </span>
            <span>
              <strong>{preview.summary.skipped}</strong> skipped
            </span>
            <span>
              Total <strong>{formatMoney(preview.summary.total_amount)}</strong>
            </span>
            {preview.summary.with_warnings > 0 && (
              <span className="text-warning">⚠ {preview.summary.with_warnings} with ATM issues</span>
            )}
            <span className="muted">{preview.summary.period}</span>
          </div>

          <label className="choice small">
            <input type="checkbox" checked={showSkipped} onChange={(event) => setShowSkipped(event.target.checked)} />
            Show skipped scholars and why
          </label>

          {rows.length === 0 ? (
            <EmptyState
              message="Nobody to show."
              action={
                <Link className="button button-secondary" to="/staff/scholars">
                  Go to Scholar Records
                </Link>
              }
            />
          ) : (
            <div className="table-wrapper prep-table">
              <table>
                <thead>
                  <tr>
                    <th>Student</th>
                    <th>Student ID</th>
                    <th>Scholarship</th>
                    <th className="numeric">Amount</th>
                    <th>ATM</th>
                    <th>Result</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((row) => (
                    <tr key={row.scholar_record_id} className={row.include ? "" : "row-muted"}>
                      <td>{row.student}</td>
                      <td>{row.student_id}</td>
                      <td>{row.scholarship}</td>
                      <td className="numeric">{row.amount ? formatMoney(row.amount) : "—"}</td>
                      <td className={row.warning ? "text-warning" : ""}>{row.warning ? `⚠ ${row.atm}` : row.atm}</td>
                      <td>
                        {row.include ? (
                          <span className="status status-success">Included</span>
                        ) : (
                          <span className="status status-neutral">Skipped: {row.reason}</span>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          <div className="button-row">
            <button
              className="button button-primary"
              onClick={confirmPayroll}
              disabled={busy || !preview.summary.included}
            >
              {busy ? "Saving..." : `Confirm: create ${preview.summary.included} draft entries`}
            </button>
            <button className="button button-secondary" onClick={() => setPreview(null)} disabled={busy}>
              Cancel
            </button>
          </div>
          <p className="muted small">ATM issues do not block payroll; they are listed so OAS can follow up with the bank.</p>
        </div>
      )}
    </section>
  );
}
