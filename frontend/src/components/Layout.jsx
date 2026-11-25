import { useEffect, useState } from "react";
import { NavLink, Outlet, useLocation } from "react-router-dom";
import api from "../services/api";
import { ROLE_LABELS, useAuth } from "../lib/auth";
import { APP_NAME, LOGO, MOTTO, OFFICE, UNIVERSITY, WORDMARK } from "../lib/brand";
import NotificationBell from "./NotificationBell";
import SearchBox from "./SearchBox";
import SidebarProfile from "./SidebarProfile";
import WelcomeModal from "./WelcomeModal";
import Icon from "./Icon";
import { iconFor } from "../lib/icons";

const NAVIGATION = {
  student: [
    ["/student/dashboard", "Dashboard"],
    ["/student/scholarships", "Scholarships"],
    ["/student/documents", "My Documents"],
    ["/student/applications", "My Applications"],
    ["/student/history", "My Scholarship History"],
    ["/student/profile", "My Profile"],
    ["/student/contact", "Contact OAS"],
    ["/student/help", "Help"],
  ],
  staff: [
    ["/staff/dashboard", "Dashboard"],
    { heading: "1 · Programs" },
    ["/staff/scholarships", "Scholarships"],
    ["/staff/recommendations", "AI Recommendations"],
    { heading: "2 · Applications" },
    ["/staff/batches", "Processing Batches"],
    ["/staff/applications", "Applications"],
    ["/staff/forwarded", "Forwarded to Agency"],
    ["/staff/agency-lists", "Approved Lists"],
    { heading: "3 · Grantees" },
    ["/staff/enrollment", "Enrollment"],
    ["/staff/scholars", "Scholar Records"],
    ["/staff/payroll", "Payroll"],
    { heading: "Office" },
    ["/staff/external-grantees", "External Grantees"],
    ["/staff/announcements", "Announcements"],
    ["/staff/messages", "Student Messages"],
    ["/staff/data-bank", "Data Bank"],
    ["/staff/reports", "Reports"],
    ["/staff/help", "Help"],
  ],
  admin: [
    ["/admin/dashboard", "Dashboard"],
    ["/admin/staff", "Manage Staff"],
    ["/admin/students", "Manage Students"],
    ["/admin/activity", "Activity Logs"],
    ["/admin/settings", "System Settings"],
    ["/admin/scholarships", "All Scholarships"],
    ["/admin/reports", "Reports"],
    ["/admin/help", "Help"],
  ],
};

function pageTitle(pathname, role) {
  if (/^\/student\/scholarships\/[^/]+$/.test(pathname)) return "Scholarship Details";
  if (/^\/student\/applications\/[^/]+$/.test(pathname)) return "Application";
  if (pathname.endsWith("/profile")) return "My Profile";
  if (pathname === "/staff/auto-review") return "Auto-Review";
  if (/\/announcements\/[^/]+$/.test(pathname)) return "Announcement";

  const match = (NAVIGATION[role] || []).find((item) => Array.isArray(item) && item[0] === pathname);
  return match ? match[1] : APP_NAME;
}

function printedAt() {
  return new Date().toLocaleString("en-PH", { dateStyle: "long", timeStyle: "short" });
}

export default function Layout() {
  const { user, logout } = useAuth();
  const location = useLocation();
  const [menuOpenedOn, setMenuOpenedOn] = useState(null);
  const menuOpen = menuOpenedOn === location.pathname;
  const setMenuOpen = (open) => setMenuOpenedOn(open ? location.pathname : null);
  const [printTime, setPrintTime] = useState(printedAt);
  const [office, setOffice] = useState(null);

  useEffect(() => {
    api
      .get("/settings/public")
      .then((response) => setOffice(response.data))
      .catch(() => setOffice(null));
  }, []);

  useEffect(() => {
    const stamp = () => setPrintTime(printedAt());
    window.addEventListener("beforeprint", stamp);
    return () => window.removeEventListener("beforeprint", stamp);
  }, []);

  const navigation = NAVIGATION[user.role] || [];
  const title = pageTitle(location.pathname, user.role);

  return (
    <div className={menuOpen ? "app-shell menu-open" : "app-shell"}>
      <aside className="sidebar" id="main-menu">
        <div className="sidebar-brand">
          <img src={LOGO} alt={`${UNIVERSITY} logo`} className="sidebar-logo" />

          <div>
            <strong className="sidebar-university">{UNIVERSITY}</strong>

            <span className="sidebar-app">{APP_NAME}</span>

            <small>{user.role === "student" ? "Student Portal" : ROLE_LABELS[user.role]}</small>
          </div>
        </div>

        <SidebarProfile />

        <nav aria-label="Main menu">
          {navigation.map((item) =>
            Array.isArray(item) ? (
              <NavLink
                key={item[0]}
                to={item[0]}
                className={({ isActive }) => (isActive ? "nav-item active" : "nav-item")}
              >
                <Icon name={iconFor(item[0])} className="nav-icon" />
                <span>{item[1]}</span>
              </NavLink>
            ) : (
              <span key={item.heading} className="nav-heading">
                {item.heading}
              </span>
            )
          )}
        </nav>

        <div className="sidebar-footer">
          <p className="sidebar-motto">{MOTTO}</p>

          <button className="logout-button" onClick={logout}>
            Sign out
          </button>
        </div>
      </aside>

      <div className="sidebar-overlay" onClick={() => setMenuOpen(false)} />

      <main className="main-content">
        <header className="topbar">
          <div className="topbar-left">
            <button
              className="menu-button"
              onClick={() => setMenuOpen(!menuOpen)}
              aria-label={menuOpen ? "Close menu" : "Open menu"}
              aria-expanded={menuOpen}
              aria-controls="main-menu"
            >
              <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true">
                <path d={menuOpen ? "M18 6 6 18M6 6l12 12" : "M4 7h16M4 12h16M4 17h16"} />
              </svg>
            </button>

            <span className="topbar-title">{title}</span>
          </div>

          <div className="user-menu">
            <SearchBox />

            {(user.role === "student" || user.role === "staff") && <NotificationBell />}

            <span className="role-label">{user.role}</span>
          </div>
        </header>

        <div className="print-header" aria-hidden="true">
          <img src={LOGO} alt="" className="print-logo" />

          <div>
            <img src={WORDMARK} alt={UNIVERSITY} className="print-wordmark" />
            <p className="print-office">{OFFICE}</p>
          </div>

          <div className="print-meta">
            <strong>{title}</strong>
            <span>Printed {printTime}</span>
          </div>
        </div>

        <div className="content" key={location.pathname + location.search}>
          <Outlet />
        </div>

        {office && <OfficeFooter office={office} isStudent={user.role === "student"} />}

        <p className="print-footer" aria-hidden="true">
          {APP_NAME} · {UNIVERSITY} · {MOTTO}
        </p>
      </main>

      <WelcomeModal />
    </div>
  );
}

function OfficeFooter({ office, isStudent }) {
  const parts = [office.oas_location, office.oas_email, office.oas_phone].filter(Boolean);

  return (
    <footer className="office-footer">
      <span>
        <strong>{OFFICE}</strong>
        {parts.length > 0 && " · "}
        {parts.join(" · ")}
      </span>
      {isStudent && <NavLink to="/student/contact">Contact OAS</NavLink>}
    </footer>
  );
}
