import { useEffect, useState } from "react";
import { useLocation } from "react-router-dom";
import api, { errMsg } from "../../services/api";
import { formatDateTime, timeAgo } from "../../lib/format";
import { useToast } from "../../lib/toast";
import PageHeader from "../../components/PageHeader";
import EmptyState from "../../components/EmptyState";
import Loading from "../../components/Loading";
import SentNotices from "./SentNotices";

/*
  Student Messages (staff).
  - Student questions: messages students sent from Contact OAS. LEFT = the list of messages (newest first);
    RIGHT = the conversation you opened: the student's message, the OAS reply, and the reply box.
  - Invitations sent: one-way notices the OAS sent from AI Recommendations (students cannot answer them here).
  /staff/messages?open=<id> opens that conversation (used by the staff bell).
*/
const TABS = [
  ["open", "Waiting for a reply"],
  ["answered", "Answered"],
  ["closed", "Closed"],
  ["all", "All"],
];

export default function MessagesPage() {
  const toast = useToast();

  const location = useLocation();
  // /staff/messages?open=<id> (from the staff bell) shows that conversation in the "All" tab, so it is never
  // filtered out. The layout opens the page fresh when the address changes, so reading it once is enough.
  const wantedId = Number(new URLSearchParams(location.search).get("open")) || null;
  const [status, setStatus] = useState(wantedId ? "all" : "open");
  const [query, setQuery] = useState("");
  const [data, setData] = useState(null);
  const [replies, setReplies] = useState({}); // message id -> reply text
  const [busy, setBusy] = useState(null);
  const [view, setView] = useState("questions"); // questions | invitations
  const [openId, setOpenId] = useState(wantedId);

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
  // The conversation shown on the right: the one picked, else the newest in the list.
  const current = data.messages.find((m) => m.id === openId) || data.messages[0] || null;

  return (
    <div>
      <PageHeader
        title="Student Messages"
        subtitle="Student questions = messages students sent from Contact OAS (you reply here). Invitations sent by OAS = one-way notices from AI Recommendations."
      />

      <div className="filter-row" role="group" aria-label="Messages or sent invitations">
        <button
          id="view-questions"
          className={view === "questions" ? "button button-primary" : "button button-secondary"}
          aria-pressed={view === "questions"}
          onClick={() => setView("questions")}
        >
          Student questions
        </button>
        <button
          id="view-invitations"
          className={view === "invitations" ? "button button-primary" : "button button-secondary"}
          aria-pressed={view === "invitations"}
          onClick={() => setView("invitations")}
        >
          Invitations sent by OAS
        </button>
      </div>

      {view === "invitations" ? (
        <SentNotices />
      ) : (
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
          {query && (
            <button type="button" className="button button-secondary" onClick={() => { setQuery(""); fetchMessages(status, ""); }}>
              Clear
            </button>
          )}
        </form>

        {data.messages.length === 0 ? (
          <EmptyState message={status === "open" ? "No messages waiting. Well done!" : "No messages here."} />
        ) : (
          <div className="msg-layout">
            <div>
              <p className="muted small">Messages · newest first</p>
              <ul className="msg-index" aria-label="Messages, newest first">
                {data.messages.map((item) => {
                  const s = item.student || {};
                  return (
                    <li key={item.id}>
                      <button
                        type="button"
                        className={`msg-row${current?.id === item.id ? " active" : ""}${item.status === "open" ? " is-open" : ""}`}
                        aria-pressed={current?.id === item.id}
                        onClick={() => setOpenId(item.id)}
                      >
                        <span className="msg-row-top">
                          <strong>
                            {s.last_name}, {s.first_name}
                          </strong>
                          <span className="muted small">{timeAgo(item.created_at)}</span>
                        </span>
                        <span className="msg-row-subject">{item.subject}</span>
                        <span className="muted small">{STATUS_LABEL[item.status] || item.status}</span>
                      </button>
                    </li>
                  );
                })}
              </ul>
            </div>

            {current && (
              <Conversation
                item={current}
                topics={data.topics}
                busy={busy === current.id}
                reply={replies[current.id] ?? ""}
                onReplyChange={(text) => setReplies((old) => ({ ...old, [current.id]: text }))}
                onSend={(close) => reply(current, close)}
                onStatus={(next) => setMessageStatus(current, next)}
              />
            )}
          </div>
        )}
      </section>
      )}
    </div>
  );
}

const STATUS_LABEL = { open: "Waiting for a reply", answered: "Answered", closed: "Closed" };

// One conversation: the student's message first, then the OAS reply, then the reply box.
function Conversation({ item, topics, busy, reply, onReplyChange, onSend, onStatus }) {
  const s = item.student || {};
  return (
    <article className="msg-thread" aria-label={`Conversation with ${s.first_name} ${s.last_name}`}>
      <header className="msg-thread-head">
        <strong>{item.subject}</strong>
        <small className="muted">
          <strong className="student-name">
            {s.first_name} {s.last_name}
          </strong>{" "}
          · {s.student_id} · {s.course}
          {s.year_level ? `, ${s.year_level}` : ""} · {topics[item.topic] || item.topic}
          {s.user?.email ? ` · ${s.user.email}` : ""}
          {s.contact_number ? ` · ${s.contact_number}` : ""}
        </small>
      </header>

      <div className="msg-bubble from-student">
        <small className="muted">Student · {formatDateTime(item.created_at)}</small>
        <p className="message-body">{item.message}</p>
      </div>

      {item.reply && (
        <div className="msg-bubble from-oas">
          <small className="muted">
            {item.replier?.name || "OAS"} · {formatDateTime(item.replied_at)}
            {item.reply_read_at ? " · seen by the student" : " · not seen yet"}
          </small>
          <p>{item.reply}</p>
        </div>
      )}

      {item.status !== "closed" ? (
        <div className="message-answer">
          <label htmlFor={`reply-${item.id}`}>{item.reply ? "Change the reply (the student sees the new text)" : "Your reply"}</label>
          <textarea
            id={`reply-${item.id}`}
            rows={4}
            maxLength={3000}
            value={reply}
            onChange={(event) => onReplyChange(event.target.value)}
            placeholder="Write your reply"
          />
          <div className="button-row">
            <button className="button button-small button-primary" disabled={busy} onClick={() => onSend(false)}>
              Send reply
            </button>
            <button className="button button-small button-secondary" disabled={busy} onClick={() => onSend(true)}>
              Send and close
            </button>
            <button className="button button-small button-secondary" disabled={busy} onClick={() => onStatus("closed")}>
              Close without reply
            </button>
          </div>
        </div>
      ) : (
        <button className="link-button" onClick={() => onStatus("open")}>
          Reopen
        </button>
      )}
    </article>
  );
}
