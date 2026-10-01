import { useState } from 'react';
import api, { errMsg } from '../api';
import { Alert, Button, inputClass } from '../ui';

export default function AuthPage({ onLogin }) {
  const [mode, setMode] = useState('login');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [form, setForm] = useState({
    email: '', password: '', first_name: '', middle_name: '', last_name: '',
    student_id: '', course: '', year_level: '', college: '', contact_number: '',
  });

  const set = (key) => (e) => setForm({ ...form, [key]: e.target.value });

  const submit = async (e) => {
    e.preventDefault();
    setError('');
    setBusy(true);
    try {
      const url = mode === 'login' ? '/login' : '/register';
      const payload = mode === 'login' ? { email: form.email, password: form.password } : form;
      const res = await api.post(url, payload);
      localStorage.setItem('token', res.data.token);
      localStorage.setItem('user', JSON.stringify(res.data.user));
      onLogin(res.data.user);
    } catch (err) {
      setError(errMsg(err));
    } finally {
      setBusy(false);
    }
  };

  const field = (key, label, type = 'text', required = false) => (
    <div className="mb-3">
      <label className="block text-sm mb-1">{label}{required && ' *'}</label>
      <input type={type} value={form[key]} onChange={set(key)} required={required} className={inputClass} />
    </div>
  );

  return (
    <div className="min-h-screen flex items-center justify-center bg-gray-100 p-4">
      <form onSubmit={submit} className="bg-white p-8 rounded shadow-md w-full max-w-md">
        <h1 className="text-2xl font-bold text-center text-green-900">CSU UniScholar</h1>
        <p className="text-center text-sm text-gray-500 mb-6">
          {mode === 'login' ? 'Log in to continue' : 'Create a student account'}
        </p>
        <Alert>{error}</Alert>

        {mode === 'register' && (
          <>
            {field('first_name', 'First name', 'text', true)}
            {field('middle_name', 'Middle name')}
            {field('last_name', 'Last name', 'text', true)}
            {field('student_id', 'Student ID (e.g. 2026-00101)', 'text', true)}
            {field('course', 'Course (e.g. BSIT)')}
            {field('year_level', 'Year level')}
            {field('college', 'College')}
            {field('contact_number', 'Contact number')}
          </>
        )}
        {field('email', 'Email', 'email', true)}
        {field('password', 'Password (min 8 characters)', 'password', true)}

        <Button type="submit" disabled={busy} className="w-full py-2">
          {busy ? 'Please wait...' : mode === 'login' ? 'Login' : 'Register'}
        </Button>

        <p className="text-sm text-center mt-4">
          {mode === 'login' ? (
            <>New student? <button type="button" className="text-green-700 underline" onClick={() => setMode('register')}>Register here</button></>
          ) : (
            <>Already registered? <button type="button" className="text-green-700 underline" onClick={() => setMode('login')}>Back to login</button></>
          )}
        </p>
      </form>
    </div>
  );
}
