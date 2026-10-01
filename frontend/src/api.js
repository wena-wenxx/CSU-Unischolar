import axios from 'axios';

export const API_URL = import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000/api';
export const FILES_URL = import.meta.env.VITE_FILES_URL || 'http://127.0.0.1:8000/storage';

const api = axios.create({ baseURL: API_URL, headers: { Accept: 'application/json' } });

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('token');
  if (token) config.headers.Authorization = `Bearer ${token}`;
  return config;
});

api.interceptors.response.use(
  (res) => res,
  (err) => {
    if (err.response?.status === 401 && localStorage.getItem('token')) {
      localStorage.removeItem('token');
      localStorage.removeItem('user');
      window.location.reload();
    }
    return Promise.reject(err);
  }
);

export function errMsg(err) {
  const data = err?.response?.data;
  if (data?.missing_requirements) return `Missing: ${data.missing_requirements.join(', ')}`;
  if (data?.errors) return Object.values(data.errors).flat().join(' ');
  if (data?.message) return data.message;
  if (err?.code === 'ERR_NETWORK') return 'Cannot reach the server. Is "php artisan serve" running?';
  return 'Something went wrong. Please try again.';
}

export default api;
