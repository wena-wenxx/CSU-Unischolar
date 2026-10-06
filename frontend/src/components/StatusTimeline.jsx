import { formatDateTime, stepLabel } from "../lib/format";

/*
  Step-by-step history of one application, using its status logs.
  Steps already done are filled; the next expected steps are shown faded,
  so the student can see where they are and what comes next.
*/
const MAIN_PATH = ["draft", "submitted", "under_review", "complete", "approved", "enrollment_verified", "grantee_tagged"];

export default function StatusTimeline({ application }) {
  const logs = application.status_logs || [];
  const done = logs.map((log) => log.to_status);
  const finished = ["rejected"].includes(application.status) || done.includes("grantee_tagged");

  // Next steps that have not happened yet (not shown after a final "rejected").
  const upcoming = finished
    ? []
    : MAIN_PATH.filter((step) => !done.includes(step) && MAIN_PATH.indexOf(step) > MAIN_PATH.indexOf(lastMainStep(done)));

  return (
    <ol className="timeline">
      {logs.map((log, index) => (
        <li
          key={log.id}
          className={`timeline-step done${index === logs.length - 1 ? " current" : ""} tone-${tone(log.to_status)}`}
        >
          <span className="timeline-dot" aria-hidden="true" />
          <div>
            <strong>{stepLabel(log.to_status)}</strong>
            <span className="muted"> · {formatDateTime(log.created_at)}</span>
            {log.remarks && <p className="timeline-remarks">“{log.remarks}”</p>}
          </div>
        </li>
      ))}

      {upcoming.map((step) => (
        <li key={step} className="timeline-step upcoming">
          <span className="timeline-dot" aria-hidden="true" />
          <div>
            <span>{stepLabel(step)}</span>
          </div>
        </li>
      ))}
    </ol>
  );
}

function lastMainStep(done) {
  const onPath = done.filter((step) => MAIN_PATH.includes(step));
  return onPath.length ? onPath[onPath.length - 1] : "draft";
}

function tone(status) {
  if (["needs_action", "enrollment_not_verified"].includes(status)) return "warning";
  if (status === "rejected") return "danger";
  return "success";
}
