import { useEffect, useState } from "react";
import { NavLink, Outlet, useLocation } from "react-router-dom";
import api from "../services/api";
import { ROLE_LABELS, useAuth } from "../lib/auth";
import { APP_NAME, LOGO, MOTTO, OFFICE, UNIVERSITY, WORDMARK } from "../lib/brand";
import NotificationBell from "./NotificationBell";
import SearchBox from "./SearchBox";
import SidebarProfile from "./SidebarProfile";
import WelcomeModal from "./WelcomeModal";

const NAVIGATION = {
  student: [
    ["/student/dashboard", "Dashboard"],
    ["/student/scholarships", "Scholarships"],
    ["/student/applications", "My Applications"],
    ["/student/documents", "My Documents"],
    ["/student/history", "My Scholarship History"],
    ["/student/profile", "My Profile"],
    ["/student/contact", "Contact OAS"],
    ["/student/help", "Help"],
  ],
  // Same order as the OAS workflow: review -> agency's list -> grantees -> payroll.
  staff: [
    ["/staff/dashboard", "Dashboard"],
    ["/staff/applications", "Applications"],
    ["/staff/agency-lists", "Approved Lists"],
    ["/staff/forwarded", "Forwarded to Agency"],
    ["/staff/scholars", "Scholar Records"],
    ["/staff/enrollment", "Enrollment"],
    ["/staff/payroll", "Payroll"],
    ["/staff/scholarships", "Scholarships"],
    ["/staff/announcements", "Announcements"],
    ["/staff/messages", "Student Messages"],
    ["/staff/data-bank", "Data Bank"],
    ["/staff/reports", "Reports"],
    ["/staff/help", "Help"],
  ],
  // The administrator manages accounts and settings; OAS staff process applications.
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

// Title shown in the top bar (and on printouts) for each address.
function pageTitle(pathname, role) {
  if (/^\/student\/scholarships\/[^/]+$/.test(pathname)) return "Scholarship Details";
  if (/^\/student\/applications\/[^/]+$/.test(pathname)) return "Application";
  if (pathname.endsWith("/profile")) return "My Profile";
  if (pathname === "/staff/auto-review") return "Auto-Review";
  if (/\/announcements\/[^/]+$/.test(pathname)) return "Announcement";

  const match = (NAVIGATION[role] || []).find(([path]) => path === pathname);
  return match ? match[1] : APP_NAME;
}

function printedAt() {
  return new Date().toLocaleString("en-PH", { dateStyle: "long", timeStyle: "short" });
}

/*
  The frame around every logged-in page: CSU Green sidebar + white top bar.
  On phones the sidebar slides in from the left when the ☰ button is pressed.
  When printing (Ctrl+P), the sidebar and top bar are hidden and a CSU
  letterhead appears instead (see the PRINT section of index.css).
*/
export default function Layout() {
  const { user, logout } = useAuth();
  const location = useLocation();
  // The phone menu remembers which page it was opened on, so it closes
  // automatically as soon as the user moves to another page.
  const [menuOpenedOn, setMenuOpenedOn] = useState(null);
  const menuOpen = menuOpenedOn === location.pathname;
  const setMenuOpen = (open) => setMenuOpenedOn(open ? location.pathname : null);
  const [printTime, setPrintTime] = useState(printedAt);
  const [office, setOffice] = useState(null);

  // OAS contact details for the footer (set by the admin in System Settings).
  useEffect(() => {
    api
      .get("/settings/public")
      .then((response) => setOffice(response.data))
      .catch(() => setOffice(null));
  }, []);

  // Stamp the current time on the printout just before printing.
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
          {navigation.map(([path, label]) => (
            <NavLink
              key={path}
              to={path}
              className={({ isActive }) => (isActive ? "nav-item active" : "nav-item")}
            >
              {label}
            </NavLink>
          ))}
        </nav>

        <div className="sidebar-footer">
          <p className="sidebar-motto">{MOTTO}</p>

          <button className="logout-button" onClick={logout}>
            Sign out
          </button>
        </div>
      </aside>

      {/* Dark layer behind the open phone menu; tap it to close the menu. */}
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
              ☰
            </button>

            <span className="topbar-title">{title}</span>
          </div>

          <div className="user-menu">
            <SearchBox />

            {user.role === "student" && <NotificationBell />}

            <span className="user-name">{user.name}</span>

            <span className="role-label">{user.role}</span>
          </div>
        </header>

        {/* Only visible on paper (Ctrl+P). */}
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

        {/* Keyed on the address: following a link with a different ?filter=
            (e.g. from the search box) opens the page fresh with that filter. */}
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

// Contact details of the OAS on every page (only the fields that are set).
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
