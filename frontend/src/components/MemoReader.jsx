import { useState } from "react";
import api, { errMsg } from "../services/api";
import { useToast } from "../lib/toast";
import Modal from "./Modal";

/*
  "Read the memo with AI" (round 12): staff paste a scholarship memo or
  choose its file; the AI (a local language model through Ollama, or simple
  pattern reading when Ollama is off) suggests the eligibility rules, each
  with the sentence it came from. Staff tick the right ones; they go into
  the form. Nothing is saved until staff press Save on the form.
*/
const LABELS = {
  max_gwa: "Highest GWA allowed",
  max_family_income: "Highest annual family income",
  year_levels: "Year levels",
  colleges: "Colleges",
  one_scholarship_only: "No other scholarship",
  fields_of_study: "Who it is for (added to the description)",
  deadline: "Deadline",
  amount: "Amount per semester",
  required_documents: "Required documents",
};
const ORDER = Object.keys(LABELS);

function show(key, value) {
  if (Array.isArray(value)) return value.join(", ");
  if (key === "max_family_income" || key === "amount") return `₱${Number(value).toLocaleString("en-PH")}`;
  if (key === "max_gwa") return Number(value).toFixed(2);
  if (key === "one_scholarship_only") return value ? "Yes: must not hold another scholarship" : "No";
  return String(value);
}

export default function MemoReader({ form, onApply, onClose }) {
  const toast = useToast();
  const [text, setText] = useState("");
  const [file, setFile] = useState(null);
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState(null);
  const [picked, setPicked] = useState({});

  async function read() {
    setBusy(true);
    try {
      const body = new FormData();
      body.append("text", text);
      if (file) body.append("file", file);
      const { data } = await api.post("/staff/ai/suggest-rules", body);
      setResult(data);
      // Tick everything except the documents (they are ticked in Required documents) and values to check.
      setPicked(Object.fromEntries(Object.entries(data.suggestions).map(([k, v]) => [k, k !== "required_documents" && !v.check])));
      if (!Object.keys(data.suggestions).length) toast.info("No rules were found in this memo.");
    } catch (err) {
      toast.error(errMsg(err, "Unable to read the memo."));
    } finally {
      setBusy(false);
    }
  }

  function apply() {
    const s = result.suggestions;
    const patch = {};
    const on = (key) => picked[key] && s[key];
    if (on("max_gwa")) patch.max_gwa = String(s.max_gwa.value);
    if (on("max_family_income")) patch.max_family_income = String(s.max_family_income.value);
    if (on("year_levels")) patch.year_levels = s.year_levels.value;
    if (on("colleges")) patch.colleges = s.colleges.value;
    if (on("one_scholarship_only")) patch.one_scholarship_only = true;
    if (on("deadline") && "application_end" in form) patch.application_end = s.deadline.value;
    if (on("amount") && "amount" in form) patch.amount = String(s.amount.value);
    if (on("fields_of_study")) {
      const line = `For students in: ${s.fields_of_study.value.join(", ")}.`;
      if (!String(form.description || "").includes(line)) patch.description = `${line} ${form.description || ""}`.trim();
    }
    onApply(patch);
    toast.success("Put into the form. Check it, then press Save.");
    onClose();
  }

  const keys = result ? ORDER.filter((k) => result.suggestions[k]) : [];

  return (
    <Modal
      size="large"
      title="Read the program memo with AI"
      subtitle={<span className="muted">Suggestions only: you check every value, and nothing is saved until you press Save.</span>}
      onClose={onClose}
      footer={
        <div className="button-row">
          {result ? (
            <>
              <button type="button" className="button button-primary" disabled={!keys.some((k) => picked[k])} onClick={apply}>
                Put ticked values in the form
              </button>
              <button type="button" className="button button-secondary" onClick={() => setResult(null)}>
                Read another memo
              </button>
            </>
          ) : (
            <button type="button" className="button button-primary" disabled={busy || (!text.trim() && !file)} onClick={read}>
              {busy ? "Reading... (the local AI can take up to a minute)" : "Read with AI"}
            </button>
          )}
        </div>
      }
    >
      {!result ? (
        <div className="memo-form">
          <label htmlFor="memo-text">Paste the memo or announcement</label>
          <textarea
            id="memo-text"
            rows={8}
            maxLength={20000}
            value={text}
            onChange={(event) => setText(event.target.value)}
            placeholder="Paste the eligibility part of the memo here…"
          />
          <label htmlFor="memo-file">…or choose the memo file (PDF, Word .docx, TXT or a photo)</label>
          <input id="memo-file" type="file" accept=".pdf,.docx,.txt,.png,.jpg,.jpeg" onChange={(event) => setFile(event.target.files?.[0] || null)} />
        </div>
      ) : (
        <>
          <p className="muted small">
            Read by{" "}
            <strong>{result.method.startsWith("ollama:") ? `the local AI model (${result.method.slice(7)})` : "simple pattern reading"}</strong>
            . Tick the values that are right.
          </p>
          {result.warnings.map((w) => (
            <p key={w} className="alert alert-warning small">
              {w}
            </p>
          ))}
          {keys.length === 0 ? (
            <p className="muted">No rules were found. Type them in the form yourself.</p>
          ) : (
            <ul className="memo-list">
              {keys.map((key) => {
                const item = result.suggestions[key];
                return (
                  <li key={key} className={item.check ? "memo-check" : ""}>
                    <label className="choice">
                      <input
                        type="checkbox"
                        checked={Boolean(picked[key])}
                        disabled={key === "required_documents"}
                        onChange={() => setPicked({ ...picked, [key]: !picked[key] })}
                      />
                      <span>
                        <strong>{LABELS[key]}:</strong> {show(key, item.value)}
                      </span>
                    </label>
                    {item.quote ? (
                      <q className="memo-quote">{item.quote}</q>
                    ) : item.check ? (
                      <small className="text-warning">⚠ This sentence was not found in the memo. Check it before using it.</small>
                    ) : null}
                    {key === "required_documents" && <small className="muted">Tick these yourself under Required documents.</small>}
                  </li>
                );
              })}
            </ul>
          )}
        </>
      )}
    </Modal>
  );
}
