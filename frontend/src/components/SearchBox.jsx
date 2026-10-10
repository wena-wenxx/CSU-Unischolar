import { useEffect, useRef, useState } from "react";
import { useLocation, useNavigate } from "react-router-dom";
import api from "../services/api";
import { useAuth } from "../lib/auth";

/*
  Search box in the top bar (every page).
  - Page names are matched here in the browser ("payroll" -> Go to Payroll).
  - Students, programs and applications come from GET /search?q=...
  Arrow keys move through the suggestions, Enter opens one, Escape closes.
*/
const PAGES = {
  staff: [
    ["Dashboard", "/staff/dashboard", "home overview numbers"],
    ["Applications", "/staff/applications", "review ai check flags needs action"],
    ["Auto-Review", "/staff/auto-review", "bulk forward ready incomplete sort"],
    ["Forwarded to Agency", "/staff/forwarded", "ched dost agency transmittal sent waiting"],
    ["Enrollment", "/staff/enrollment", "registrar list verify all enrolled"],
    ["Student Messages", "/staff/messages", "contact inbox reply questions"],
    ["Scholar Records", "/staff/scholars", "grantee tag enrolled"],
    ["Payroll", "/staff/payroll", "pay payout ready"],
    ["Data Bank", "/staff/data-bank", "student history records"],
    ["Scholarships", "/staff/scholarships", "programs create requirements deadline"],
    ["Approved Lists", "/staff/agency-lists", "agency approved list upload csv"],
    ["Announcements", "/staff/announcements", "news post notice"],
    ["Reports", "/staff/reports", "export csv excel"],
    ["My Profile", "/staff/profile", "account"],
    ["Help", "/staff/help", "guide how manual"],
  ],
  student: [
    ["Dashboard", "/student/dashboard", "home announcements"],
    ["Scholarships", "/student/scholarships", "apply programs deadline"],
    ["My Applications", "/student/applications", "status submitted"],
    ["My Documents", "/student/documents", "files upload cor grades id indigency birth certificate clearance"],
    ["My Scholarship History", "/student/history", "grant payroll completed"],
    ["My Profile", "/student/profile", "contact correction password"],
    ["Contact OAS", "/student/contact", "message ask question office hours phone email"],
    ["Help", "/student/help", "guide how to apply"],
  ],
  admin: [
    ["Dashboard", "/admin/dashboard", "home overview"],
    ["Manage Staff", "/admin/staff", "accounts users oas staff admin create"],
    ["Manage Students", "/admin/students", "accounts users student create"],
    ["Activity Logs", "/admin/activity", "audit who did what history"],
    ["System Settings", "/admin/settings", "school year semester office hours contact"],
    ["All Scholarships", "/admin/scholarships", "programs"],
    ["Reports", "/admin/reports", "export csv excel"],
    ["My Profile", "/admin/profile", "account password"],
    ["Help", "/admin/help", "guide how manual"],
  ],
};

function pageMatches(role, text) {
  const q = text.toLowerCase();

  return (PAGES[role] || [])
    .filter(([name, , words]) => `${name} ${words}`.toLowerCase().includes(q) || q.includes(name.toLowerCase()))
    .slice(0, 4)
    .map(([name, to]) => ({ kind: "Page", label: `Go to ${name}`, to }));
}

export default function SearchBox() {
  const { user } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const boxRef = useRef(null);

  const [text, setText] = useState("");
  const [remote, setRemote] = useState([]);
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(0);

  // Ask the server 250 ms after the user stops typing.
  useEffect(() => {
    const q = text.trim();
    if (q.length < 2) return undefined;

    let cancelled = false;
    const timer = setTimeout(() => {
      api
        .get("/search", { params: { q } })
        .then((response) => {
          if (!cancelled) setRemote(response.data);
        })
        .catch(() => {});
    }, 250);

    return () => {
      cancelled = true;
      clearTimeout(timer);
    };
  }, [text]);

  // Close when clicking elsewhere.
  useEffect(() => {
    function onClick(event) {
      if (boxRef.current && !boxRef.current.contains(event.target)) setOpen(false);
    }
    document.addEventListener("mousedown", onClick);
    return () => document.removeEventListener("mousedown", onClick);
  }, []);

  const q = text.trim();
  const suggestions = q.length < 2 ? [] : [...pageMatches(user.role, q), ...remote];

  function go(item) {
    setOpen(false);
    setText("");
    setRemote([]);
    if (item.to !== location.pathname + location.search) navigate(item.to);
  }

  function onKeyDown(event) {
    if (event.key === "ArrowDown") {
      event.preventDefault();
      setOpen(true);
      setActive((i) => Math.min(i + 1, suggestions.length - 1));
    } else if (event.key === "ArrowUp") {
      event.preventDefault();
      setActive((i) => Math.max(i - 1, 0));
    } else if (event.key === "Enter" && suggestions[active]) {
      event.preventDefault();
      go(suggestions[active]);
    } else if (event.key === "Escape") {
      setOpen(false);
    }
  }

  const placeholder =
    { staff: "Search students, programs, pages…", admin: "Search accounts, pages…" }[user.role] || "Search scholarships, pages…";

  return (
    <div className="search-box" ref={boxRef}>
      <label htmlFor="global-search" className="sr-only">
        Search
      </label>
      <span className="search-icon" aria-hidden="true">
        ⌕
      </span>
      <input
        id="global-search"
        type="search"
        autoComplete="off"
        placeholder={placeholder}
        value={text}
        onChange={(event) => {
          setText(event.target.value);
          setActive(0);
          setOpen(true);
          if (event.target.value.trim().length < 2) setRemote([]);
        }}
        onFocus={() => setOpen(true)}
        onKeyDown={onKeyDown}
        role="combobox"
        aria-expanded={open && suggestions.length > 0}
        aria-controls="global-search-list"
      />

      {open && q.length >= 2 && (
        <ul className="search-results" id="global-search-list" role="listbox">
          {suggestions.length === 0 ? (
            <li className="search-empty">No matches for “{q}”.</li>
          ) : (
            suggestions.map((item, index) => (
              <li
                key={`${item.kind}-${item.to}-${index}`}
                role="option"
                aria-selected={index === active}
                className={index === active ? "active" : ""}
                onMouseDown={(event) => {
                  event.preventDefault();
                  go(item);
                }}
                onMouseEnter={() => setActive(index)}
              >
                <span className="search-kind">{item.kind}</span>
                <span className="search-label">{item.label}</span>
                {item.detail && <span className="search-detail">{item.detail}</span>}
              </li>
            ))
          )}
        </ul>
      )}
    </div>
  );
}
