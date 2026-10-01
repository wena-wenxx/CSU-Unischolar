import { useCallback, useEffect, useState } from 'react';
import api, { errMsg } from '../../api';
import { SCHOLARSHIP_STATUS } from '../../constants';
import { Alert, Button, Card, PageTitle, inputClass } from '../../ui';

const EMPTY = { name: '', provider: '', description: '', amount: '', status: 'active', application_start: '', application_end: '' };

export default function ScholarshipsManagePage() {
  const [list, setList] = useState([]);
  const [form, setForm] = useState(EMPTY);
  const [editingId, setEditingId] = useState(null);
  const [reqForm, setReqForm] = useState({});
  const [editingReq, setEditingReq] = useState(null); // { id, name, description, is_required }
  const [msg, setMsg] = useState('');
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    try { setList((await api.get('/scholarships')).data); }
    catch (err) { setError(errMsg(err)); }
  }, []);
  useEffect(() => { load(); }, [load]);

  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });

  const save = async (e) => {
    e.preventDefault();
    setMsg(''); setError('');
    const payload = Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v === '' ? null : v]));
    try {
      if (editingId) await api.put(`/scholarships/${editingId}`, payload);
      else await api.post('/scholarships', payload);
      setMsg(editingId ? 'Scholarship updated.' : 'Scholarship created.');
      setForm(EMPTY); setEditingId(null);
      load();
    } catch (err) { setError(errMsg(err)); }
  };

  const edit = (s) => {
    setEditingId(s.id);
    setForm({ name: s.name || '', provider: s.provider || '', description: s.description || '',
      amount: s.amount ?? '', status: s.status, application_start: s.application_start || '', application_end: s.application_end || '' });
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  const remove = async (s) => {
    if (!window.confirm(`Delete "${s.name}"? Its applications will also be deleted.`)) return;
    try { await api.delete(`/scholarships/${s.id}`); load(); } catch (err) { setError(errMsg(err)); }
  };

  const addReq = async (s) => {
    const r = reqForm[s.id] || {};
    if (!r.name) return;
    try {
      await api.post(`/scholarships/${s.id}/requirements`, { name: r.name, is_required: r.is_required ?? true });
      setReqForm({ ...reqForm, [s.id]: { name: '', is_required: true } });
      load();
    } catch (err) { setError(errMsg(err)); }
  };

  const saveReq = async () => {
    try {
      await api.put(`/requirements/${editingReq.id}`, {
        name: editingReq.name, description: editingReq.description, is_required: editingReq.is_required,
      });
      setEditingReq(null);
      load();
    } catch (err) { setError(errMsg(err)); }
  };

  const deleteReq = async (id) => {
    if (!window.confirm('Delete this requirement? Any related uploaded documents stay, but future checks won\'t require it.')) return;
    try { await api.delete(`/requirements/${id}`); load(); } catch (err) { setError(errMsg(err)); }
  };

  return (
    <div className="p-6 max-w-5xl mx-auto">
      <PageTitle sub="Create scholarship programs and their requirements">Scholarship Management</PageTitle>
      <Alert>{error}</Alert>
      <Alert type="success">{msg}</Alert>

      <Card className="mb-6">
        <h2 className="font-semibold mb-3">{editingId ? 'Edit scholarship' : 'Add a scholarship'}</h2>
        <form onSubmit={save} className="grid md:grid-cols-2 gap-3">
          <input className={inputClass} placeholder="Name *" value={form.name} onChange={set('name')} required />
          <input className={inputClass} placeholder="Provider (e.g. CHED)" value={form.provider} onChange={set('provider')} />
          <input className={inputClass} type="number" placeholder="Amount (optional)" value={form.amount} onChange={set('amount')} />
          <select className={inputClass} value={form.status} onChange={set('status')}>
            {SCHOLARSHIP_STATUS.map((s) => <option key={s}>{s}</option>)}
          </select>
          <label className="text-xs text-gray-500">Application start<input className={inputClass} type="date" value={form.application_start} onChange={set('application_start')} /></label>
          <label className="text-xs text-gray-500">Application end<input className={inputClass} type="date" value={form.application_end} onChange={set('application_end')} /></label>
          <textarea className={`${inputClass} md:col-span-2`} placeholder="Description" value={form.description} onChange={set('description')} />
          <div className="md:col-span-2 flex gap-2">
            <Button type="submit">{editingId ? 'Save changes' : 'Create scholarship'}</Button>
            {editingId && <Button type="button" variant="secondary" onClick={() => { setEditingId(null); setForm(EMPTY); }}>Cancel</Button>}
          </div>
        </form>
      </Card>

      <div className="grid gap-4">
        {list.map((s) => (
          <Card key={s.id}>
            <div className="flex justify-between">
              <div>
                <h3 className="font-semibold">{s.name} <span className="text-xs text-gray-500">({s.status})</span></h3>
                <p className="text-sm text-gray-500">{s.provider}</p>
              </div>
              <div className="flex gap-2">
                <Button variant="secondary" onClick={() => edit(s)}>Edit</Button>
                <Button variant="danger" onClick={() => remove(s)}>Delete</Button>
              </div>
            </div>

            <p className="text-sm font-medium mt-2">Requirements</p>
            <ul className="text-sm">
              {(s.requirements || []).map((r) => (
                <li key={r.id} className="flex items-center justify-between border-b py-1">
                  {editingReq?.id === r.id ? (
                    <div className="flex gap-2 w-full items-center">
                      <input className={inputClass} value={editingReq.name} onChange={(e) => setEditingReq({ ...editingReq, name: e.target.value })} />
                      <label className="text-xs whitespace-nowrap"><input type="checkbox" checked={editingReq.is_required}
                        onChange={(e) => setEditingReq({ ...editingReq, is_required: e.target.checked })} /> required</label>
                      <Button onClick={saveReq}>Save</Button>
                      <Button variant="secondary" onClick={() => setEditingReq(null)}>Cancel</Button>
                    </div>
                  ) : (
                    <>
                      <span>{r.name}{!r.is_required && ' (optional)'}</span>
                      <span className="flex gap-2">
                        <button className="text-xs text-blue-700 underline" onClick={() => setEditingReq({ id: r.id, name: r.name, description: r.description, is_required: r.is_required })}>Edit</button>
                        <button className="text-xs text-red-700 underline" onClick={() => deleteReq(r.id)}>Delete</button>
                      </span>
                    </>
                  )}
                </li>
              ))}
              {(!s.requirements || s.requirements.length === 0) && <li className="text-gray-400 py-1">None yet</li>}
            </ul>
            <div className="flex gap-2 mt-2">
              <input className={inputClass} placeholder="New requirement name"
                value={reqForm[s.id]?.name || ''}
                onChange={(e) => setReqForm({ ...reqForm, [s.id]: { ...reqForm[s.id], name: e.target.value } })} />
              <label className="text-xs flex items-center gap-1 whitespace-nowrap">
                <input type="checkbox" checked={reqForm[s.id]?.is_required ?? true}
                  onChange={(e) => setReqForm({ ...reqForm, [s.id]: { ...reqForm[s.id], is_required: e.target.checked } })} /> required
              </label>
              <Button onClick={() => addReq(s)}>Add</Button>
            </div>
          </Card>
        ))}
      </div>
    </div>
  );
}
