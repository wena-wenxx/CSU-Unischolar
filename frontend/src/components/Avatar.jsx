import { useState } from "react";
import { initials, photoUrl } from "../lib/format";

/* The user's photo, or their initials when there is no photo (or it fails to load). */
export default function Avatar({ user, name, className = "", silhouette = false }) {
  const src = photoUrl(user);
  const [failed, setFailed] = useState(null);

  if (src && failed !== src) {
    return (
      <span className={`avatar avatar-photo ${className}`}>
        <img src={src} alt="" onError={() => setFailed(src)} />
      </span>
    );
  }

  if (silhouette) {
    // No photo yet: a plain person shape, like the CSU MySchool portal.
    return (
      <span className={`avatar avatar-silhouette ${className}`} aria-hidden="true">
        <svg viewBox="0 0 64 64" focusable="false">
          <circle cx="32" cy="23" r="12" />
          <path d="M8 60c0-13 11-22 24-22s24 9 24 22z" />
        </svg>
      </span>
    );
  }

  return (
    <span className={`avatar ${className}`} aria-hidden="true">
      {initials(name || user?.name)}
    </span>
  );
}
