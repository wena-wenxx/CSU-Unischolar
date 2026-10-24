import { useEffect, useRef, useState } from "react";
import { Link, useLocation } from "react-router-dom";
import { ROLE_LABELS, useAuth } from "../lib/auth";
import { courseShort } from "../lib/format";
import Avatar from "./Avatar";

/*
  Who is logged in, at the top of the sidebar: the photo (or initials), the
  name, then the Student ID and course (students) or e-mail and role (staff,
  admin), like the CSU student portal. Click it for View Profile / Help /
  Sign Out. The photo is uploaded on My Profile.
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
  const year = student?.year_level ? String(student.year_level).match(/\d/)?.[0] : "";
  const role = student
    ? [courseShort(student.course), year].filter(Boolean).join(" - ") || ROLE_LABELS.student
    : ROLE_LABELS[user.role] || user.role;

  return (
    <div className="sidebar-profile" ref={boxRef}>
      <button
        type="button"
        className="profile-trigger"
        onClick={() => setOpenOn(open ? null : location.pathname)}
        aria-expanded={open}
        aria-controls="profile-menu"
      >
        <Avatar user={user} name={name} />
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
