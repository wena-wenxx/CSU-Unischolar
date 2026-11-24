/*
  Where this browser TAB keeps its login.

  sessionStorage belongs to one tab (or window), so a student, a staff member and the admin can be
  signed in at the same time in three windows of the same browser, as in the demonstration, without
  one login replacing another. Signing out in one window signs out only that window (the server
  revokes only that window's token). Closing the tab ends its session; a reload keeps it.

  Older versions kept the login in localStorage (shared by every tab); it is moved here once.
*/
const KEYS = ["token", "user"];

(function moveOldLogin() {
  try {
    if (!window.sessionStorage.getItem("token") && window.localStorage.getItem("token")) {
      KEYS.forEach((key) => {
        const value = window.localStorage.getItem(key);
        if (value !== null) window.sessionStorage.setItem(key, value);
      });
    }
    KEYS.forEach((key) => window.localStorage.removeItem(key));
  } catch {
    // storage blocked: the user simply signs in again
  }
})();

export function getItem(key) {
  try {
    return window.sessionStorage.getItem(key);
  } catch {
    return null;
  }
}

export function setItem(key, value) {
  try {
    window.sessionStorage.setItem(key, value);
  } catch {
    // ignore
  }
}

export function removeItem(key) {
  try {
    window.sessionStorage.removeItem(key);
  } catch {
    // ignore
  }
}
