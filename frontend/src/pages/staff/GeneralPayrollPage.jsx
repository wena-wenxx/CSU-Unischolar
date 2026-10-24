import { useEffect, useState } from "react";
import { Link, useLocation } from "react-router-dom";
import api, { errMsg } from "../../services/api";
import { downloadPayrollFile, peso, readFormAddress } from "../../lib/payrollForm";
import { useToast } from "../../lib/toast";
import Loading from "../../components/Loading";

/*
  General Payroll (General Form No. 6-A, revised July 1, 1995): the OAS's
  official payroll sheet, filled in from ScholarGuide. Opens on its own page
  (no menu) so it prints like the paper form: long bond (8.5 x 13 in),
  landscape. Download it as PDF or Word, or print it from here.
*/
export default function GeneralPayrollPage() {
  const toast = useToast();
  const location = useLocation();
  const [body] = useState(() => readFormAddress(location.search));
  const [form, setForm] = useState(null);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState("");

  // .then() (not await) so React's lint rule sees the state is set later.
  useEffect(() => {
    api
      .post("/payroll/form", body)
      .then(({ data }) => setForm(data))
      .catch((err) => setError(errMsg(err, "Unable to make the payroll form.")));
  }, [body]);

  async function download(kind) {
    setBusy(kind);
    try {
      const name = await downloadPayrollFile(kind, body);
      toast.success(`Downloaded ${name}`);
    } catch (err) {
      toast.error(errMsg(err, `Unable to make the ${kind === "pdf" ? "PDF" : "Word"} file.`));
    } finally {
      setBusy("");
    }
  }

  return (
    <div className="gp-screen">
      <div className="gp-toolbar print-hide">
        <Link className="button button-secondary" to="/staff/payroll">
          ← Payroll
        </Link>
        <button className="button button-primary" onClick={() => download("pdf")} disabled={!form || Boolean(busy)}>
          {busy === "pdf" ? "Making..." : "Download PDF"}
        </button>
        <button className="button button-secondary" onClick={() => download("docx")} disabled={!form || Boolean(busy)}>
          {busy === "docx" ? "Making..." : "Download Word (.docx)"}
        </button>
        <button className="button button-secondary" onClick={() => window.print()} disabled={!form}>
          Print
        </button>
        <span className="muted small">
          Print: paper <strong>Legal / Long (8.5 × 13 in)</strong>, <strong>Landscape</strong>, <em>Headers and footers</em> off.
        </span>
      </div>

      {error && <p className="alert alert-danger print-hide">{error}</p>}
      {!form && !error && <Loading />}

      {form?.sheets.map((sheet) => <Sheet key={sheet.sheet_no} form={form} sheet={sheet} />)}
    </div>
  );
}

function Person({ person }) {
  return (
    <div className="gp-person">
      <strong>{person?.name ? person.name.toUpperCase() : " "}</strong>
      <span>{person?.title}</span>
    </div>
  );
}

function Sheet({ form, sheet }) {
  const s = form.signatories;
  const dash = <span className="gp-red">-</span>;
  const firstUsed = sheet.rows.some((r) => r.first_sem !== null);
  const secondUsed = sheet.rows.some((r) => r.second_sem !== null);

  return (
    <section className="gp-sheet">
      <header className="gp-head">
        <div className="gp-head-left">
          <p className="gp-formno">
            General Form No.(6 A)
            <br />
            (Revised July 1,1995)
          </p>
          <h1>GENERAL PAYROLL</h1>
          <p className="gp-ack">
            We hereby acknowledge to have received of the sum herein specified opposite our respective names being full
            compensation for our services for the period
          </p>
        </div>
        <div className="gp-head-right">
          <p>Sheet No: {sheet.sheet_no}</p>
          <p>
            Journal Voucher: <span className="gp-voucher">{form.voucher || " "}</span>
          </p>
          <p className="gp-banner">
            Release of <span className="gp-batch">{form.batch}</span> balance for the sum herein specified
          </p>
          <p className="gp-purpose">{form.purpose}</p>
          {sheet.atm_label && <p className="gp-atm">{sheet.atm_label}</p>}
        </div>
      </header>

      <table className="gp-table">
        <colgroup>
          {[2.8, 6.4, 16, 4.2, 6.1, 6.1, 2.2, 2.2, 6.4, 2.2, 5.8, 5.6, 2.2, 2.2, 6.4, 7.2, 15.8].map((width, index) => (
            <col key={index} style={{ width: `${width}%` }} />
          ))}
        </colgroup>
        <thead>
          {/* Two header rows, like the OAS form: "Less: Miscellaneous / CHED Grants" sits over two empty sub-columns. */}
          <tr>
            <th rowSpan={2}>No.</th>
            <th rowSpan={2}>ID NO.</th>
            <th rowSpan={2}>NAME</th>
            <th rowSpan={2}>Position</th>
            <th rowSpan={2}>
              {form.first_sem_label}
              <small>{peso(form.rate)}</small>
            </th>
            <th rowSpan={2}>
              {form.second_sem_label}
              <small>{peso(form.rate)}</small>
            </th>
            <th rowSpan={2} />
            <th rowSpan={2} />
            <th rowSpan={2}>TOTAL</th>
            <th rowSpan={2} />
            <th colSpan={2} className="gp-small-head">
              Less: Miscellaneous
              <br />
              CHED Grants
            </th>
            <th rowSpan={2} />
            <th rowSpan={2} />
            <th rowSpan={2}>TOTAL DEDUCTIONS</th>
            <th rowSpan={2}>Net Amount Received</th>
            <th rowSpan={2}>Initial of Witness to Payment</th>
          </tr>
          <tr className="gp-subhead">
            <th />
            <th />
          </tr>
        </thead>
        <tbody>
          {sheet.rows.map((row) => (
            <tr key={row.no}>
              <td className="gp-num">{row.no}</td>
              <td>{row.student_id}</td>
              <td>{row.name}</td>
              <td />
              <td className="gp-num">{peso(row.first_sem)}</td>
              <td className="gp-num">{peso(row.second_sem)}</td>
              <td />
              <td />
              <td className="gp-num">{peso(row.total)}</td>
              <td />
              <td />
              <td />
              <td />
              <td />
              <td />
              <td className="gp-num">{peso(row.net)}</td>
              <td className="gp-witness">{row.no}</td>
            </tr>
          ))}
          <tr className="gp-total">
            <td />
            <td />
            <td className="gp-nothing">{sheet.is_last ? "***NOTHING FOLLOWS***" : "continued on the next sheet"}</td>
            <td>
              <strong>TOTAL</strong>
            </td>
            <td className="gp-num gp-red">{firstUsed ? peso(sheet.total) : dash}</td>
            <td className="gp-num gp-red">{secondUsed ? peso(sheet.total) : dash}</td>
            <td className="gp-center">{dash}</td>
            <td className="gp-center">{dash}</td>
            <td className="gp-num gp-red">{peso(sheet.total)}</td>
            <td />
            <td className="gp-center">{dash}</td>
            <td className="gp-center">{dash}</td>
            <td />
            <td />
            <td className="gp-center">{dash}</td>
            <td className="gp-num gp-red">{peso(sheet.total)}</td>
            <td />
          </tr>
          {sheet.grand_total !== null && (
            <tr className="gp-total">
              <td colSpan={8} className="gp-num">
                <strong>GRAND TOTAL ({sheet.atm_label || "all"}, all sheets)</strong>
              </td>
              <td className="gp-num gp-red">{peso(sheet.grand_total)}</td>
              <td colSpan={6} />
              <td className="gp-num gp-red">{peso(sheet.grand_total)}</td>
              <td />
            </tr>
          )}
        </tbody>
      </table>

      {/* Signatures in the OAS layout: 5 across, a line, then 3 + the witness lines. */}
      <table className="gp-signs">
        <tbody>
          <tr className="gp-cert-row">
            <td colSpan={2}>(1.) I certify on my oath above Payroll is correct and that the services have been duly rendered as stated.</td>
            <td />
            <td colSpan={2}>
              (3.) I certify on my oath that I have paid to each employee whose name appears on the roll the amount set
              opposite his name, he having presented his Residence Certificate.
            </td>
          </tr>
          <tr className="gp-label-row">
            <td>Prepared By:</td>
            <td>Noted by:</td>
            <td />
            <td>As to correctness of enrollment data:</td>
            <td />
          </tr>
          <tr className="gp-people gp-rule">
            <td>
              <Person person={s.prepared} />
            </td>
            <td>
              <Person person={s.coordinator} />
            </td>
            <td>
              <Person person={s.director} />
            </td>
            <td>
              <Person person={s.registrar} />
            </td>
            <td>
              <Person person={s.vp} />
            </td>
          </tr>
          <tr className="gp-cert-row">
            <td colSpan={3}>(2.) Approved from appropriate for</td>
            <td colSpan={2}>
              (4.) I certify on my oath that I have witnessed payment to each person whose name appears hereon, of the
              amount set opposite his name my initials.
            </td>
          </tr>
          <tr className="gp-label-row">
            <td colSpan={3}>Funds Available:</td>
            <td colSpan={2} />
          </tr>
          <tr className="gp-people">
            <td>
              <Person person={s.accountant} />
            </td>
            <td>
              <Person person={s.president} />
            </td>
            <td />
            <td>
              <Person person={s.cashier} />
            </td>
            <td>
              <div className="gp-witness-lines">
                <span />
                <span />
              </div>
            </td>
          </tr>
        </tbody>
      </table>

      <p className="gp-foot">
        Prepared in ScholarGuide · {form.program} · {form.period} · sheet {sheet.sheet_no}
      </p>
    </section>
  );
}
