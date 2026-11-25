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
  }
}

export function removeItem(key) {
  try {
    window.sessionStorage.removeItem(key);
  } catch {
  }
}
