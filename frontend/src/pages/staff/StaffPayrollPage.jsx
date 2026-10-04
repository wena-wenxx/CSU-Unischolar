import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import api, { errMsg } from "../../services/api";
import { formatMoney, fullName } from "../../lib/format";
import { useToast } from "../../components/Toast";
import Modal from "../../components/Modal";
import PageHeader from "../../components/PageHeader";
import StatusBadge from "../../components/StatusBadge";
import EmptyState from "../../components/EmptyState";
import Loading from "../../components/Loading";

const DEFAULT_PERIOD = "1st Semester AY 2026-2027";

const samePeriod = (a, b) =>
  String(a || "").trim().toLowerCase() === String(b || "").trim().toLowerCase();

export default function StaffPayrollPage() {
  const toast = useToast();

  const [records, setRecords] = useState([]);
  const [payroll, setPayroll] = useState([]);
  const [loading, setLoading] = useState(true);
  const [single, setSingle] = useState(null); // scholar record for the one-person form
  const [batchOpen, setBatchOpen] = useState(false);

  const load = useCallback(async () => {
    try {
      const [scholarsResponse, payrollResponse] = await Promise.all([
        api.get("/scholar-records"),
        api.get("/payroll"),
      ]);

      setRecords(scholarsResponse.data);
      setPayroll(payrollResponse.data);
    } catch (err) {
      toast.error(errMsg(err, "Unable to load payroll."));
    } finally {
      setLoading(false);
    }
  }, [toast]);

  useEffect(() => {
    load();
  }, [load]);

  async function setPayrollStatus(item, status) {
    try {
      await api.patch(`/payroll/${item.id}`, { status });
      toast.success(`Payroll entry marked ${status}.`);
      await load();
    } catch (err) {
      toast.error(errMsg(err, "Unable to update payroll record."));
    }
  }

  const activeRecords = records.filter((record) => record.status === "active");
  const eligible = activeRecords.filter((record) => record.currently_enrolled);
  const readyCount = payroll.filter((item) => item.status === "ready").length;

  if (loading) return <Loading />;

  return (
    <div>
      <PageHeader
        title="Payroll Preparation"
        subtitle="Prepare payroll entries for active, enrolled grantees"
        actions={
          <button
            className="button button-primary"
            onClick={() => setBatchOpen(true)}
            disabled={eligible.length === 0}
          >
            Prepare payroll for all enrolled scholars ({eligible.length})
          </button>
        }
      />

      <section className="card">
        <h2>Active Scholars</h2>

        {activeRecords.length === 0 ? (
          <EmptyState
            message="No active scholars yet."
            action={
              <Link className="button button-secondary" to="/staff/scholars">
                Go to Scholar Records
              </Link>
            }
          />
        ) : (
          <div className="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>Student</th>
                  <th>Scholarship</th>
                  <th>Program amount</th>
                  <th>Enrolled</th>
                  <th>ATM</th>
                  <th>Action</th>
                </tr>
              </thead>

              <tbody>
                {activeRecords.map((record) => (
                  <tr key={record.id}>
                    <td>{fullName(record.student)}</td>
                    <td>{record.scholarship?.name}</td>
                    <td>{formatMoney(record.scholarship?.amount)}</td>
                    <td>{record.currently_enrolled ? "Yes" : "No"}</td>
                    <td>{record.has_atm ? "Yes" : "No"}</td>
                    <td>
                      <button
                        className="button button-small button-primary"
                        onClick={() => setSingle(record)}
                        disabled={!record.currently_enrolled}
                        title={record.currently_enrolled ? "" : "Student must be currently enrolled"}
                      >
                        Add to Payroll
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      <section className="card">
        <div className="card-header">
          <h2>Payroll Records</h2>

          <span className="muted">{readyCount} ready</span>
        </div>

        <p className="muted">Draft → Ready (checked and ready to send) → Processed (released).</p>

        {payroll.length === 0 ? (
          <EmptyState message="No payroll records yet." />
        ) : (
          <div className="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>Student</th>
                  <th>Scholarship</th>
                  <th>Amount</th>
                  <th>Period</th>
                  <th>ATM</th>
                  <th>Status</th>
                  <th>Action</th>
                </tr>
              </thead>

              <tbody>
                {payroll.map((item) => (
                  <tr key={item.id}>
                    <td>{fullName(item.scholar_record?.student)}</td>
                    <td>{item.scholar_record?.scholarship?.name}</td>
                    <td>{formatMoney(item.amount)}</td>
                    <td>{item.period}</td>
                    <td>{item.bank_atm_status}</td>
                    <td>
                      <StatusBadge status={item.status} />
                    </td>
                    <td>
                      {item.status === "draft" && (
                        <button
                          className="button button-small button-primary"
                          onClick={() => setPayrollStatus(item, "ready")}
                        >
                          Mark Ready
                        </button>
                      )}

                      {item.status === "ready" && (
                        <div className="button-row">
                          <button
                            className="button button-small button-success"
                            onClick={() => setPayrollStatus(item, "processed")}
                          >
                            Mark Processed
                          </button>

                          <button
                            className="button button-small button-secondary"
                            onClick={() => setPayrollStatus(item, "draft")}
                          >
                            Back to Draft
                          </button>
                        </div>
                      )}

                      {item.status === "processed" && "—"}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {single && (
        <PayrollFormModal
          title="Add to Payroll"
          intro={
            <>
              <strong>{fullName(single.student)}</strong> · {single.scholarship?.name}
            </>
          }
          defaultAmount={single.scholarship?.amount ?? ""}
          submitLabel="Add to payroll"
          onClose={() => setSingle(null)}
          onSubmit={async ({ amount, period }) => {
            const already = payroll.some(
              (item) => item.scholar_record_id === single.id && samePeriod(item.period, period)
            );

            if (already) {
              toast.error(`${fullName(single.student)} already has a payroll entry for "${period}".`);
              return false;
            }

            await api.post(`/scholar-records/${single.id}/payroll`, {
              scholar_record_id: single.id,
              amount,
              period,
              bank_atm_status: single.has_atm ? "Yes" : "No",
            });

            toast.success(`Payroll entry added for ${fullName(single.student)}.`);
            setSingle(null);
            await load();
            return true;
          }}
        />
      )}

      {batchOpen && (
        <PayrollFormModal
          title="Prepare Payroll for All Enrolled Scholars"
          intro={
            <>
              Creates one <strong>draft</strong> payroll entry for each of the{" "}
              <strong>{eligible.length}</strong> active, currently enrolled scholars. Anyone who
              already has an entry for the same period is skipped.
            </>
          }
          defaultAmount=""
          submitLabel={`Prepare payroll for ${eligible.length} scholars`}
          onClose={() => setBatchOpen(false)}
          onSubmit={async ({ amount, period }) => {
            let created = 0;
            let skipped = 0;
            const failed = [];

            // One request per scholar, using the existing endpoint.
            for (const record of eligible) {
              const already = payroll.some(
                (item) => item.scholar_record_id === record.id && samePeriod(item.period, period)
              );

              if (already) {
                skipped += 1;
                continue;
              }

              try {
                await api.post(`/scholar-records/${record.id}/payroll`, {
                  scholar_record_id: record.id,
                  amount,
                  period,
                  bank_atm_status: record.has_atm ? "Yes" : "No",
                });
                created += 1;
              } catch (err) {
                failed.push(`${fullName(record.student)}: ${errMsg(err)}`);
              }
            }

            let summary = `Payroll prepared for ${created} scholar${created === 1 ? "" : "s"}.`;
            if (skipped) summary += ` ${skipped} skipped (already had "${period}").`;

            if (created > 0) toast.success(summary);
            else toast.info(summary);

            failed.forEach((message) => toast.error(message));

            setBatchOpen(false);
            await load();
            return true;
          }}
        />
      )}
    </div>
  );
}

/* ---------- Amount + period form, used for one scholar and for the batch ---------- */

function PayrollFormModal({ title, intro, defaultAmount, submitLabel, onClose, onSubmit }) {
  const toast = useToast();

  const [amount, setAmount] = useState(defaultAmount === null ? "" : String(defaultAmount));
  const [period, setPeriod] = useState(DEFAULT_PERIOD);
  const [saving, setSaving] = useState(false);

  async function save(event) {
    event.preventDefault();

    const value = Number(String(amount).replaceAll(",", ""));

    if (!String(amount).trim() || Number.isNaN(value) || value <= 0) {
      toast.error("Please type a valid amount, for example 5000.");
      return;
    }

    if (!period.trim()) {
      toast.error("Please type the payroll period.");
      return;
    }

    setSaving(true);

    try {
      const done = await onSubmit({ amount: value, period: period.trim() });
      if (!done) setSaving(false);
    } catch (err) {
      toast.error(errMsg(err, "Unable to create payroll record."));
      setSaving(false);
    }
  }

  return (
    <Modal title={title} onClose={saving ? undefined : onClose}>
      <form onSubmit={save}>
        <p>{intro}</p>

        <div className="form-grid">
          <div>
            <label htmlFor="payroll-amount">Amount per scholar (₱)</label>

            <input
              id="payroll-amount"
              type="number"
              min="1"
              step="0.01"
              inputMode="decimal"
              value={amount}
              onChange={(event) => setAmount(event.target.value)}
              placeholder="e.g. 5000"
              required
              autoFocus
            />
          </div>

          <div>
            <label htmlFor="payroll-period">Period</label>

            <input
              id="payroll-period"
              value={period}
              onChange={(event) => setPeriod(event.target.value)}
              required
            />
          </div>
        </div>

        <div className="modal-footer">
          <button type="button" className="button button-secondary" onClick={onClose} disabled={saving}>
            Cancel
          </button>

          <button className="button button-primary" disabled={saving}>
            {saving ? "Working..." : submitLabel}
          </button>
        </div>
      </form>
    </Modal>
  );
}
