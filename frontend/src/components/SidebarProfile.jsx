import { useEffect, useRef, useState } from "react";
import { Link, useLocation } from "react-router-dom";
import { useAuth } from "../lib/auth";
import { initials } from "../lib/format";

/*
  Who is logged in, at the top of the sidebar: an initials avatar, the name,
  the Student ID (students) or e-mail (staff) and the role.
  Click it for View Profile / Help / Sign Out.
  (No photo upload: the system does not store profile pictures.)
*/

export default function SidebarProfile() {
  const { user, logout } = useAuth();
  const location = useLocation();
  const boxRef = useRef(null);
  const [openOn, setOpenOn] = useState(null); // closes by itself on another page
  const open = openOn === location.pathname;

  useEffect(() => {
    if (!open) return undefined;
    function onClick(event) {
      if (boxRef.current && !boxRef.current.contains(event.target)) setOpenOn(null);
    }
    document.addEventListener("mousedown", onClick);
    return () => document.removeEventListener("mousedown", onClick);
  }, [open]);

  const student = user.student;
  const name = student ? `${student.first_name} ${student.last_name}` : user.name;
  const detail = student ? student.student_id : user.email;
  const role = user.role === "staff" ? "OAS Staff" : "Student";

  return (
    <div className="sidebar-profile" ref={boxRef}>
      <button
        type="button"
        className="profile-trigger"
        onClick={() => setOpenOn(open ? null : location.pathname)}
        aria-expanded={open}
        aria-controls="profile-menu"
      >
        <span className="avatar" aria-hidden="true">
          {initials(name)}
        </span>
        <span className="profile-text">
          <strong>{name}</strong>
          <small>{detail}</small>
          <small className="profile-role">{role}</small>
        </span>
        <span className="profile-caret" aria-hidden="true">
          ▾
        </span>
      </button>

      {open && (
        <div className="profile-menu" id="profile-menu" role="menu">
          <Link role="menuitem" to={`/${user.role}/profile`}>
            View Profile
          </Link>
          <Link role="menuitem" to={`/${user.role}/help`}>
            Help
          </Link>
          <button role="menuitem" type="button" onClick={logout}>
            Sign Out
          </button>
        </div>
      )}
    </div>
  );
}
