export function Badge({ info, fallback }) {
  const i = info || { label: fallback || '-', color: 'bg-gray-100 text-gray-700' };
  return <span className={`inline-block px-2 py-0.5 rounded text-xs font-medium ${i.color}`}>{i.label}</span>;
}
export function Card({ children, className = '' }) {
  return <div className={`bg-white border rounded-lg shadow-sm p-4 ${className}`}>{children}</div>;
}
export function PageTitle({ children, sub }) {
  return (
    <div className="mb-4">
      <h1 className="text-2xl font-bold text-green-900">{children}</h1>
      {sub && <p className="text-sm text-gray-500">{sub}</p>}
    </div>
  );
}
export function Alert({ type = 'error', children }) {
  if (!children) return null;
  const colors = type === 'error' ? 'bg-red-50 text-red-700 border-red-200' : 'bg-green-50 text-green-700 border-green-200';
  return <div className={`border rounded p-2 mb-3 text-sm ${colors}`}>{children}</div>;
}
export function Button({ children, variant = 'primary', className = '', ...props }) {
  const styles = {
    primary: 'bg-green-700 text-white hover:bg-green-800',
    secondary: 'bg-gray-200 text-gray-800 hover:bg-gray-300',
    danger: 'bg-red-600 text-white hover:bg-red-700',
  }[variant];
  return <button {...props} className={`px-3 py-1.5 rounded text-sm disabled:opacity-50 ${styles} ${className}`}>{children}</button>;
}
export const inputClass = 'border rounded px-2 py-1.5 w-full text-sm';
