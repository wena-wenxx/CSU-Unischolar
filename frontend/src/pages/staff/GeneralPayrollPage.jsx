import { useEffect, useState } from "react";
import { Link, useLocation } from "react-router-dom";
import api, { errMsg } from "../../services/api";
import { downloadPayrollWord, peso, readFormAddress } from "../../lib/payrollForm";
import { useToast } from "../../lib/toast";
import Loading from "../../components/Loading";

/*
  General Payroll (General Form No. 6-A, revised July 1, 1995): the OAS's
  official payroll sheet, filled in from ScholarGuide. Opens on its own page
  (no menu) so it prints like the paper form: long bond (8.5 x 13 in),
  landscape. Print / Save as PDF, or download the same form as Word.
*/
export default function GeneralPayrollPage() {
  const toast = useToast();
  const location = useLocation();
  const [body] = useState(() => readFormAddress(location.search));
  const [form, setForm] = useState(null);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  // .then() (not await) so React's lint rule sees the state is set later.
  useEffect(() => {
    api
      .post("/payroll/form", body)
      .then(({ data }) => setForm(data))
      .catch((err) => setError(errMsg(err, "Unable to make the payroll form.")));
  }, [body]);

  async function word() {
    setBusy(true);
    try {
      const name = await downloadPayrollWord(body);
      toast.success(`Downloaded ${name}`);
    } catch (err) {
      toast.error(errMsg(err, "Unable to make the Word file."));
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="gp-screen">
      <div className="gp-toolbar print-hide">
        <Link className="button button-secondary" to="/staff/payroll">
          ← Payroll
        </Link>
        <button className="button button-primary" onClick={() => window.print()} disabled={!form}>
          Print / Save as PDF
        </button>
        <button className="button button-secondary" onClick={word} disabled={!form || busy}>
          {busy ? "Making..." : "Download Word (.docx)"}
        </button>
        <span className="muted small">
          In the print window: paper <strong>Legal / Long (8.5 × 13 in)</strong>, <strong>Landscape</strong>, and turn off
          <em> Headers and footers</em>. Choose “Save as PDF” to keep a PDF.
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
          <tr>
            <th>No.</th>
            <th>ID NO.</th>
            <th>NAME</th>
            <th>Position</th>
            <th>
              {form.first_sem_label}
              <small>{peso(form.rate)}</small>
            </th>
            <th>
              {form.second_sem_label}
              <small>{peso(form.rate)}</small>
            </th>
            <th />
            <th />
            <th>TOTAL</th>
            <th />
            <th className="gp-small-head">Less: Miscellaneous</th>
            <th className="gp-small-head">CHED Grants</th>
            <th />
            <th />
            <th>TOTAL DEDUCTIONS</th>
            <th>Net Amount Received</th>
            <th>Initial of Witness to Payment</th>
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

      <div className="gp-signs">
        <div>
          <p className="gp-cert">(1.) I certify on my oath above Payroll is correct and that the services have been duly rendered as stated.</p>
          <p className="gp-label">Prepared By:</p>
          <Person person={s.prepared} />
        </div>
        <div>
          <p className="gp-cert">&nbsp;</p>
          <p className="gp-label">Noted by:</p>
          <Person person={s.coordinator} />
        </div>
        <div>
          <p className="gp-cert">&nbsp;</p>
          <p className="gp-label">&nbsp;</p>
          <Person person={s.director} />
        </div>
        <div>
          <p className="gp-cert">
            (3.) I certify on my oath that I have paid to each employee whose name appears on the roll the amount set
            opposite his name, he having presented his Residence Certificate.
          </p>
          <p className="gp-label">As to correctness of enrollment data:</p>
          <Person person={s.registrar} />
        </div>
        <div>
          <p className="gp-cert">(2.) Approved from appropriate for</p>
          <p className="gp-label">Funds Available:</p>
          <Person person={s.accountant} />
        </div>
        <div>
          <p className="gp-cert">&nbsp;</p>
          <p className="gp-label">&nbsp;</p>
          <Person person={s.president} />
        </div>
        <div>
          <p className="gp-cert">&nbsp;</p>
          <p className="gp-label">&nbsp;</p>
          <Person person={s.vp} />
        </div>
        <div>
          <p className="gp-cert">
            (4.) I certify on my oath that I have witnessed payment to each person whose name appears hereon, of the amount
            set opposite his name my initials.
          </p>
          <p className="gp-label">&nbsp;</p>
          <Person person={s.cashier} />
          <div className="gp-witness-lines">
            <span />
            <span />
          </div>
        </div>
      </div>

      <p className="gp-foot">
        Prepared in ScholarGuide · {form.program} · {form.period} · sheet {sheet.sheet_no}
      </p>
    </section>
  );
}
