import { MOTTO } from "../lib/brand";

export default function Loading({ message = "Loading..." }) {
  return (
    <div className="loading" role="status">
      <div className="spinner"></div>

      <p>{message}</p>

      <small className="loading-motto">{MOTTO}</small>
    </div>
  );
}
