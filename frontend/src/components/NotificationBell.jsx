import { useCallback, useEffect, useRef, useState } from "react";
import { Link, useLocation } from "react-router-dom";
import api from "../services/api";
import { stepLabel, timeAgo } from "../lib/format";

/*
  Students only: a bell in the top bar with the number of new status
  changes on their applications. Opening it marks them as read.
  It checks again whenever the student moves to another page.
*/
export default function NotificationBell() {
  const location = useLocation();
  const [data, setData] = useState({ unread: 0, items: [] });
  const [open, setOpen] = useState(false);
  const boxRef = useRef(null);

  const load = useCallback(() => {
    api
      .get("/student/notifications")
      .then((response) => setData(response.data))
      .catch(() => {});
  }, []);

  useEffect(() => {
    load();
  }, [load, location.pathname]);

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

    if (opening && data.unread > 0) {
      api
        .post("/student/notifications/read")
        .then(() => setData((current) => ({ ...current, unread: 0 })))
        .catch(() => {});
    }
  }

  return (
    <div className="bell" ref={boxRef}>
      <button
        type="button"
        className="bell-button"
        onClick={toggle}
        aria-expanded={open}
        aria-label={data.unread ? `Notifications, ${data.unread} new` : "Notifications"}
      >
        <span aria-hidden="true">🔔</span>
        {data.unread > 0 && <span className="bell-count">{data.unread}</span>}
      </button>

      {open && (
        <div className="bell-panel" role="dialog" aria-label="Notifications">
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
        </div>
      )}
    </div>
  );
}
