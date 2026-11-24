import { useCallback, useEffect, useRef, useState } from "react";
import { Link, useLocation } from "react-router-dom";
import api from "../services/api";
import { stepLabel, timeAgo } from "../lib/format";
import { useAuth } from "../lib/auth";
import { useToast } from "../lib/toast";

// Staff: how often the bell asks for new student actions ("what to do next"). Small request.
const ALERT_SECONDS = 10;

/*
  Students see application updates and OAS replies. Staff see open messages
  from students. The staff inbox is shared, so an open message is the
  notification until it is answered or closed.
*/
export default function NotificationBell() {
  const { user } = useAuth();
  const location = useLocation();
  const isStaff = user.role === "staff";
  const [data, setData] = useState({
    unread: 0,
    items: [],
    unread_replies: 0,
    invitations: [],
    opportunities: [],
    counts: {},
    messages: [],
  });
  const [open, setOpen] = useState(false);
  const boxRef = useRef(null);
  const toast = useToast();
  const [alerts, setAlerts] = useState({ alerts: [], unseen: 0 });
  const known = useRef(null); // alert keys already shown, so only new ones pop up

  // Staff: a student did something that needs the OAS -> it appears here within seconds,
  // with the next step and a button to the right page (and a short pop-up the first time).
  const loadAlerts = useCallback(() => {
    if (!isStaff || document.visibilityState !== "visible") return;
    api
      .get("/staff/alerts")
      .then(({ data: fresh }) => {
        const keys = new Set(fresh.alerts.map((a) => a.key));
        if (known.current) {
          fresh.alerts
            .filter((a) => a.new && !known.current.has(a.key))
            .slice(0, 3)
            .forEach((a) => toast.info(`${a.who}: ${a.title}. Next: ${a.next}`));
        }
        known.current = keys;
        setAlerts(fresh);
      })
      .catch(() => {});
  }, [isStaff, toast]);

  useEffect(() => {
    if (!isStaff) return undefined;
    loadAlerts();
    const timer = window.setInterval(loadAlerts, ALERT_SECONDS * 1000);
    window.addEventListener("focus", loadAlerts);
    return () => {
      window.clearInterval(timer);
      window.removeEventListener("focus", loadAlerts);
    };
  }, [isStaff, loadAlerts]);

  const load = useCallback(() => {
    const request = isStaff ? api.get("/staff/messages", { params: { status: "open" } }) : api.get("/student/notifications");

    request
      .then((response) => setData(response.data))
      .catch(() => {});
  }, [isStaff]);

  useEffect(() => {
    load();
    if (!isStaff) return undefined;

    const refresh = window.setInterval(load, 30000);
    return () => window.clearInterval(refresh);
  }, [isStaff, load, location.pathname]);

  // Close when clicking anywhere else.
  useEffect(() => {
    if (!open) return undefined;

    function onClick(event) {
      if (boxRef.current && !boxRef.current.contains(event.target)) setOpen(false);
    }

    document.addEventListener("mousedown", onClick);
    return () => document.removeEventListener("mousedown", onClick);
  }, [open]);

  function toggle() {
    const opening = !open;
    setOpen(opening);

    if (isStaff && opening && alerts.unseen > 0) {
      api
        .post("/staff/alerts/seen")
        .then(() => setAlerts((current) => ({ ...current, unseen: 0 })))
        .catch(() => {});
    }

    if (!isStaff && opening && data.unread > 0) {
      api
        .post("/student/notifications/read")
        .then(() => setData((current) => ({ ...current, unread: 0 })))
        .catch(() => {});
    }
  }

  const count = isStaff ? alerts.unseen || data.counts?.open || 0 : data.unread + (data.unread_replies || 0);

  return (
    <div className="bell" ref={boxRef}>
      <button
        type="button"
        className="bell-button"
        onClick={toggle}
        aria-expanded={open}
        aria-label={count ? `Notifications, ${count} new` : "Notifications"}
      >
        <svg className="bell-icon" viewBox="0 0 24 24" aria-hidden="true">
          <path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9" />
          <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" />
        </svg>
        {count > 0 && (
          <span className="bell-count">{count}</span>
        )}
      </button>

      {open && (
        <div className="bell-panel" role="dialog" aria-label="Notifications">
          {isStaff ? (
            <>
              <strong className="bell-title">What to do next</strong>
              {alerts.alerts.length === 0 ? (
                <p className="muted">Nothing waiting from students.</p>
              ) : (
                <ul>
                  {alerts.alerts.slice(0, 8).map((item) => (
                    <li key={item.key} className={item.new ? "unread" : ""}>
                      <Link to={item.link} onClick={() => setOpen(false)}>
                        <strong>{item.who}</strong>
                        <span>{item.title}</span>
                        <span className="bell-next">Next: {item.next}</span>
                        <small className="muted">{timeAgo(item.at)}</small>
                      </Link>
                      <span className="button-row">
                        <Link className="button button-small button-primary" to={item.link} onClick={() => setOpen(false)}>
                          {item.action}
                        </Link>
                      </span>
                    </li>
                  ))}
                </ul>
              )}

              {/* One list only: student messages are part of "What to do next" above (newest first). */}
              <p className="bell-footer">
                <Link to="/staff/messages" onClick={() => setOpen(false)}>
                  All student messages
                  {data.counts?.open ? ` (${data.counts.open} waiting for a reply)` : ""} →
                </Link>
              </p>
            </>
          ) : (
            <>
          {data.opportunities?.length > 0 && (
            <div className="bell-invites">
              <strong className="bell-title">Scholarship opportunities</strong>
              {data.opportunities.map((item) => (
                <li key={item.id} className={item.read_at ? "" : "unread"}>
                  <Link to={`/student/scholarships/${item.scholarship_id}`} onClick={() => setOpen(false)}>
                    <strong>{item.scholarship}</strong>
                    <span style={{ whiteSpace: "pre-line" }}>{item.message}</span>
                    <small className="muted">{timeAgo(item.sent_at)}</small>
                  </Link>
                  <span className="button-row">
                    <Link className="button button-small button-secondary" to={`/student/scholarships/${item.scholarship_id}`} onClick={() => setOpen(false)}>
                      View Scholarship
                    </Link>
                    {item.availability === "open" && !item.agency_direct && (
                      <Link className="button button-small button-primary" to={`/student/scholarships/${item.scholarship_id}`} onClick={() => setOpen(false)}>
                        Apply Now
                      </Link>
                    )}
                    <Link
                      className="button button-small button-secondary"
                      to={`/student/contact?topic=other&subject=${encodeURIComponent(`Question about ${item.scholarship}`)}`}
                      onClick={() => setOpen(false)}
                    >
                      Ask OAS
                    </Link>
                  </span>
                </li>
              ))}
            </div>
          )}

          {data.unread_replies > 0 && (
            <Link className="bell-reply" to="/student/contact" onClick={() => setOpen(false)}>
              OAS replied to {data.unread_replies === 1 ? "your message" : `${data.unread_replies} of your messages`}. Open Contact OAS →
            </Link>
          )}

          {data.invitations?.length > 0 && (
            <div className="bell-invites">
              <strong className="bell-title">OAS thinks you may be eligible for</strong>
              {data.invitations.map((item) => (
                <Link
                  key={item.scholarship_id}
                  className="bell-reply"
                  to={`/student/scholarships/${item.scholarship_id}`}
                  onClick={() => setOpen(false)}
                >
                  {item.scholarship} <small className="muted">· {timeAgo(item.invited_at)}</small>
                </Link>
              ))}
            </div>
          )}

          <strong className="bell-title">Updates on your applications</strong>

          {data.items.length === 0 ? (
            <p className="muted">No updates yet.</p>
          ) : (
            <ul>
              {data.items.map((item) => (
                <li key={item.id} className={item.read_at ? "" : "unread"}>
                  <Link to={`/student/applications/${item.application_id}`} onClick={() => setOpen(false)}>
                    <strong>{stepLabel(item.to_status)}</strong>
                    <span>{item.application?.scholarship?.name}</span>
                    <small className="muted">{timeAgo(item.created_at)}</small>
                  </Link>
                </li>
              ))}
            </ul>
          )}
            </>
          )}
        </div>
      )}
    </div>
  );
}
