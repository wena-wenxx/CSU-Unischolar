import { useState } from "react";
import { initials, photoUrl } from "../lib/format";

/* The user's photo, or their initials when there is no photo (or it fails to load). */
export default function Avatar({ user, name, className = "" }) {
  const src = photoUrl(user);
  const [failed, setFailed] = useState(null);

  if (src && failed !== src) {
    return (
      <span className={`avatar avatar-photo ${className}`}>
        <img src={src} alt="" onError={() => setFailed(src)} />
      </span>
    );
  }

  return (
    <span className={`avatar ${className}`} aria-hidden="true">
      {initials(name || user?.name)}
    </span>
  );
}
