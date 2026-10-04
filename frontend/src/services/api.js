import axios from "axios";

// Backend address. Override in frontend/.env with VITE_API_URL if needed.
export const API_URL =
  import.meta.env.VITE_API_URL || "http://127.0.0.1:8000/api";

// Uploaded files are served from Laravel's public storage
// (run "php artisan storage:link" once in backend/).
export const FILES_URL =
  import.meta.env.VITE_FILES_URL ||
  API_URL.replace(/\/api\/?$/, "") + "/storage";

const api = axios.create({
  baseURL: API_URL,
  headers: {
    Accept: "application/json",
  },
});

api.interceptors.request.use(
  (config) => {
    const token = localStorage.getItem("token");

    if (token) {
      config.headers.Authorization = `Bearer ${token}`;
    }

    return config;
  },
  (error) => Promise.reject(error)
);

api.interceptors.response.use(
  (response) => response,
  (error) => {
    // Token expired or was revoked: log out and go back to the login page.
    if (error.response?.status === 401 && localStorage.getItem("token")) {
      localStorage.removeItem("token");
      localStorage.removeItem("user");
      window.location.reload();
    }

    return Promise.reject(error);
  }
);

// Turns any API error into one readable sentence for the user.
export function errMsg(err, fallback = "Something went wrong. Please try again.") {
  const data = err?.response?.data;

  if (data?.errors) return Object.values(data.errors).flat().join(" ");
  if (data?.message) return data.message;
  if (err?.code === "ERR_NETWORK") {
    return 'Cannot reach the server. Is "php artisan serve" running?';
  }

  return fallback;
}

export default api;
