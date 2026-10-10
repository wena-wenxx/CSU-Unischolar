import { useEffect, useState } from "react";
import api, { errMsg } from "../../services/api";
import { formatDateTime, timeAgo } from "../../lib/format";
import { useToast } from "../../lib/toast";
import PageHeader from "../../components/PageHeader";
import EmptyState from "../../components/EmptyState";
import Loading from "../../components/Loading";

/* Student Messages (staff): questions from Contact OAS. Oldest open message first. */
const TABS = [
  ["open", "Waiting for a reply"],
  ["answered", "Answered"],
  ["closed", "Closed"],
  ["all", "All"],
];

export default function MessagesPage() {
  const toast = useToast();

  const [status, setStatus] = useState("open");
  const [query, setQuery] = useState("");
  const [data, setData] = useState(null);
  const [replies, setReplies] = useState({}); // message id -> reply text
  const [busy, setBusy] = useState(null);

  function fetchMessages(nextStatus = status, q = query) {
    return api
      .get("/staff/messages", { params: { status: nextStatus, q: q || undefined } })
      .then((response) => setData(response.data))
      .catch((err) => {
        toast.error(errMsg(err, "Unable to load messages."));
        setData({ topics: {}, counts: {}, messages: [] });
      });
  }

  useEffect(() => {
    fetchMessages();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  function changeTab(next) {
    setStatus(next);
    fetchMessages(next);
  }

  async function reply(item, close) {
    const text = (replies[item.id] ?? "").trim();
    if (!text) {
      toast.error("Type your reply first.");
      return;
    }

    setBusy(item.id);
    try {
      const { data: result } = await api.post(`/staff/messages/${item.id}/reply`, { reply: text, close });
      toast.success(result.message);
      setReplies((current) => ({ ...current, [item.id]: undefined }));
      await fetchMessages();
    } catch (err) {
      toast.error(errMsg(err, "Unable to send the reply."));
    } finally {
      setBusy(null);
    }
  }

  async function setMessageStatus(item, next) {
    setBusy(item.id);
    try {
      const { data: result } = await api.post(`/staff/messages/${item.id}/status`, { status: next });
      toast.success(result.message);
      await fetchMessages();
    } catch (err) {
      toast.error(errMsg(err, "Unable to change the message."));
    } finally {
      setBusy(null);
    }
  }

  if (!data) return <Loading />;

  const counts = data.counts || {};

  return (
    <div>
      <PageHeader title="Student Messages" subtitle="Questions students sent from Contact OAS" />

      <section className="card">
        <div className="filter-row" role="group" aria-label="Show messages">
          {TABS.map(([key, label]) => (
            <button
              key={key}
              className={status === key ? "button button-small button-primary" : "button button-small button-secondary"}
              aria-pressed={status === key}
              onClick={() => changeTab(key)}
            >
              {label}
              {key !== "all" && ` (${counts[key] ?? 0})`}
            </button>
          ))}
        </div>

        <form
          className="inline-form table-search"
          onSubmit={(event) => {
            event.preventDefault();
            fetchMessages();
          }}
        >
          <label htmlFor="msg-search" className="sr-only">
            Search messages
          </label>
          <input
            id="msg-search"
            type="search"
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            placeholder="Search a student, Student ID or words"
          />
          <button className="button button-primary">Search</button>
        </form>

        {data.messages.length === 0 ? (
          <EmptyState message={status === "open" ? "No messages waiting. Well done!" : "No messages here."} />
        ) : (
          <ul className="message-list">
            {data.messages.map((item) => {
              const s = item.student || {};
              return (
                <li key={item.id} className="message-item">
                  <div className="message-head">
                    <strong>{item.subject}</strong>
                    <span className="muted small">{timeAgo(item.created_at)}</span>
                  </div>
                  <small className="muted">
                    {s.first_name} {s.last_name} · {s.student_id} · {s.course}
                    {s.year_level ? `, ${s.year_level}` : ""} · {data.topics[item.topic] || item.topic}
                    {s.user?.email ? ` · ${s.user.email}` : ""}
                    {s.contact_number ? ` · ${s.contact_number}` : ""}
                  </small>
                  <p className="message-body">{item.message}</p>

                  {item.reply && (
                    <div className="message-reply">
                      <strong>Reply</strong>
                      <small className="muted">
                        {" "}
                        · {item.replier?.name || "OAS"} · {formatDateTime(item.replied_at)}
                        {item.reply_read_at ? " · seen by the student" : " · not seen yet"}
                      </small>
                      <p>{item.reply}</p>
                    </div>
                  )}

                  {item.status !== "closed" && (
                    <div className="message-answer">
                      <label htmlFor={`reply-${item.id}`} className="sr-only">
                        Reply to {s.first_name}
                      </label>
                      <textarea
                        id={`reply-${item.id}`}
                        rows={3}
                        maxLength={3000}
                        value={replies[item.id] ?? ""}
                        onChange={(event) => setReplies((current) => ({ ...current, [item.id]: event.target.value }))}
                        placeholder={item.reply ? "Write another reply (replaces the one above)" : "Write your reply"}
                      />
                      <div className="button-row">
                        <button className="button button-small button-primary" disabled={busy === item.id} onClick={() => reply(item, false)}>
                          Send reply
                        </button>
                        <button className="button button-small button-secondary" disabled={busy === item.id} onClick={() => reply(item, true)}>
                          Send and close
                        </button>
                        <button className="button button-small button-secondary" disabled={busy === item.id} onClick={() => setMessageStatus(item, "closed")}>
                          Close without reply
                        </button>
                      </div>
                    </div>
                  )}

                  {item.status === "closed" && (
                    <button className="link-button" onClick={() => setMessageStatus(item, "open")}>
                      Reopen
                    </button>
                  )}
                </li>
              );
            })}
          </ul>
        )}
      </section>
    </div>
  );
}
