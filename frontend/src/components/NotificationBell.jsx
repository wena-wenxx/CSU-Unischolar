import { useCallback, useEffect, useRef, useState } from "react";
import { Link, useLocation } from "react-router-dom";
import api from "../services/api";
import { stepLabel, timeAgo } from "../lib/format";
import { useAuth } from "../lib/auth";

/*
  Students see application updates and OAS replies. Staff see open messages
  from students. The staff inbox is shared, so an open message is the
  notification until it is answered or closed.
*/
export default function NotificationBell() {
  const { user } = useAuth();
  const location = useLocation();
  const isStaff = user.role === "staff";
  const [data, setData] = useState({ unread: 0, items: [], unread_replies: 0, invitations: [], counts: {}, messages: [] });
  const [open, setOpen] = useState(false);
  const boxRef = useRef(null);

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

    if (!isStaff && opening && data.unread > 0) {
      api
        .post("/student/notifications/read")
        .then(() => setData((current) => ({ ...current, unread: 0 })))
        .catch(() => {});
    }
  }

  const count = isStaff ? data.counts?.open || 0 : data.unread + (data.unread_replies || 0);

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
              <strong className="bell-title">Student messages</strong>

              {data.messages?.length === 0 ? (
                <p className="muted">No messages waiting.</p>
              ) : (
                <ul>
                  {data.messages.map((item) => {
                    const student = item.student || {};
                    const studentName = [student.first_name, student.last_name].filter(Boolean).join(" ");

                    return (
                      <li key={item.id} className="unread">
                        <Link to="/staff/messages" onClick={() => setOpen(false)}>
                          <strong>{item.subject}</strong>
                          <span>{studentName || "Student"}</span>
                          <small className="muted">{timeAgo(item.created_at)}</small>
                        </Link>
                      </li>
                    );
                  })}
                </ul>
              )}
            </>
          ) : (
            <>
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
