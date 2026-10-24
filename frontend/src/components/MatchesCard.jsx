import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import api from "../services/api";
import { formatDate } from "../lib/format";

/*
  Student dashboard: "Scholarships that fit you" (round 12).
  Programs whose rules the student meets (or that only need their GWA /
  income), best first, with what is left to do. The AI field fit (round
  12) puts programs for the student's field first and adds agency-direct
  programs for their field ("Also for your field"). No percentage: it could be
  read as a chance of approval. Programs the student fails, has applied
  for, or that OAS marked wrong are not listed. Hidden when empty.
*/
const STATUS = {
  ready: ["Ready to apply", "success"],
  almost: [null, "warning"], // "2 things left"
  need_info: ["Needs your profile info", "warning"],
};
const SHOWN = 3;

// One line per kind of task, so the same words are not repeated for every document.
function checklist(todo) {
  const names = (kind) => todo.filter((item) => item.kind === kind).map((item) => item.doc);
  const lines = [];
  if (names("upload").length) lines.push({ text: `Upload: ${names("upload").join(", ")}`, wait: false });
  todo.filter((item) => item.kind === "renew").forEach((item) => lines.push({ text: item.text, wait: false }));
  if (names("check").length) {
    lines.push({ text: `Press Check with AI in My Documents for: ${names("check").join(", ")}`, wait: false });
  }
  todo.filter((item) => item.kind === "wait").forEach((item) => lines.push({ text: item.text, wait: true }));
  return lines;
}

function MatchRow({ match }) {
  const todo = match.todo.filter((item) => item.kind !== "profile");
  const lines = checklist(todo);
  const [label, tone] = STATUS[match.status];
  const left = match.to_do_count;

  let action = null;
  if (match.agency_direct) {
    action = <span className="muted small">{match.closed_reason}</span>;
  } else if (match.availability !== "open") {
    action = <span className="muted small">{match.closed_reason}</span>;
  } else if (match.status === "ready") {
    action = (
      <Link className="button button-small button-primary" to={`/student/scholarships/${match.scholarship_id}`}>
        Apply
      </Link>
    );
  } else if (todo.some((item) => item.kind !== "wait")) {
    action = (
      <Link className="button button-small button-secondary" to="/student/documents">
        My Documents
      </Link>
    );
  }

  return (
    <li className="match-row">
      <div className="match-main">
        <Link to={`/student/scholarships/${match.scholarship_id}`}>
          <strong>{match.name}</strong>
        </Link>
        <span className={`status status-${tone}`}>{label || `${left} thing${left === 1 ? "" : "s"} left`}</span>
        <span className="muted small">{match.deadline ? `Deadline: ${formatDate(match.deadline)}` : "No deadline set"}</span>
        {match.fit?.level === "high" && <span className="fit-tag">Your field: {match.fit.fields.join(", ")}</span>}
      </div>

      {lines.length > 0 && (
        <ul className="match-todo">
          {lines.map((line) => (
            <li key={line.text} className={line.wait ? "muted" : ""}>
              {line.wait ? "⏳ " : "☐ "}
              {line.text}
            </li>
          ))}
        </ul>
      )}

      {action && <div className="match-action">{action}</div>}
    </li>
  );
}

export default function MatchesCard() {
  const [data, setData] = useState(null);
  const [showAll, setShowAll] = useState(false);

  useEffect(() => {
    api
      .get("/student/matches")
      .then(({ data: result }) => setData(result))
      .catch(() => setData(null));
  }, []);

  const matches = data?.matches || [];
  const alsoFits = data?.also_fits || [];
  if (!matches.length && !alsoFits.length) return null;

  // Profile to-dos (GWA, income, ...) are the same for every program: show them once.
  const profileTodo = [
    ...new Set(matches.flatMap((match) => match.todo.filter((item) => item.kind === "profile").map((item) => item.text))),
  ];
  const shown = showAll ? matches : matches.slice(0, SHOWN);

  return (
    <section className="card matches-card">
      <div className="card-header">
        <h2>Scholarships that fit you</h2>
        {matches.length > SHOWN && (
          <button className="link-button" onClick={() => setShowAll(!showAll)}>
            {showAll ? "Show fewer" : `Show all (${matches.length})`}
          </button>
        )}
      </div>
      {matches.length > 0 && (
        <>
      <p className="muted small">
        You meet the rules of these programs based on your profile and My Documents. This is a suggestion, not an
        approval: OAS checks your documents, and the agency decides.
      </p>

      {profileTodo.length > 0 && (
        <div className="match-first">
          <strong>First:</strong> {profileTodo.join(" · ")}.{" "}
          <Link to="/student/profile">Open My Profile →</Link>
        </div>
      )}

      <ul className="match-list">
        {shown.map((match) => (
          <MatchRow key={match.scholarship_id} match={match} />
        ))}
      </ul>
        </>
      )}

      {alsoFits.length > 0 && (
        <div className="match-also">
          <strong>Also for your field</strong>{" "}
          <span className="muted small">(you apply at the agency, not here; read each program's other conditions)</span>
          <ul>
            {alsoFits.map((program) => (
              <li key={program.scholarship_id}>
                <Link to={`/student/scholarships/${program.scholarship_id}`}>{program.name}</Link>
                <span className="muted small"> · {program.fields.join(", ")}</span>
              </li>
            ))}
          </ul>
        </div>
      )}

      {!data.has_interests && (
        <p className="muted small match-hint">
          Tip: add your field and goals on <Link to="/student/profile">My Profile</Link> so the AI finds programs for
          your field.
        </p>
      )}
    </section>
  );
}
