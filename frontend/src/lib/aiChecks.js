import { useCallback, useEffect, useRef, useState } from "react";
import api, { errMsg, isCancel } from "../services/api";

const POLL_MS = 3000;
const MAX_WAIT_MS = 7 * 60 * 1000;

function doneMessage(document, fallback) {
  if (document?.status === "validated") return ["success", "AI check finished: no issues found."];
  if (document?.status === "flagged") return ["info", "AI check finished: possible issues were flagged. Open the result to see why."];
  if (document?.status === "needs_review") return ["info", fallback || "The file could not be read well enough. OAS will check it manually."];
  return ["info", fallback || "AI check finished."];
}

export function needsAiCheck(document) {
  return Boolean(document) && !document.staff_verified_at && ["uploaded", "needs_review"].includes(document.status);
}

export function useAiChecks({ toast, onChange, confirm }) {
  const [running, setRunning] = useState(() => new Set());
  const timers = useRef({});
  const alive = useRef(true);
  const controllers = useRef({});
  const stopAll = useRef(false);
  const pollRef = useRef(null);
  const startRef = useRef(null);

  useEffect(() => {
    alive.current = true;
    const pending = timers.current;
    return () => {
      alive.current = false;
      Object.values(pending).forEach((timer) => window.clearTimeout(timer));
    };
  }, []);

  const mark = useCallback((id, on) => {
    setRunning((current) => {
      const next = new Set(current);
      if (on) next.add(id);
      else next.delete(id);
      return next;
    });
  }, []);

  const finish = useCallback(
    (id, kind, message) => {
      window.clearTimeout(timers.current[id]);
      delete timers.current[id];
      if (!alive.current) return;
      mark(id, false);
      if (message) toast?.[kind]?.(message);
      onChange?.();
    },
    [mark, onChange, toast]
  );

  const poll = useCallback(
    (id, startedAt) => {
      timers.current[id] = window.setTimeout(async () => {
        if (!alive.current) return;
        try {
          const { data } = await api.get(`/documents/${id}/check-status`);
          if (data.state === "running") {
            if (Date.now() - startedAt > MAX_WAIT_MS) {
              finish(id, "info", "The AI check is taking very long. It keeps running; look again in a few minutes.");
            } else {
              pollRef.current?.(id, startedAt);
            }
            return;
          }
          if (data.state === "failed") {
            finish(id, "error", data.message || "The AI check stopped. Press Run AI check again.");
            return;
          }
          const [kind, text] = doneMessage(data.document);
          finish(id, kind, text);
        } catch (err) {
          if (Date.now() - startedAt > MAX_WAIT_MS) finish(id, "error", errMsg(err, "Could not get the AI result."));
          else pollRef.current?.(id, startedAt);
        }
      }, POLL_MS);
    },
    [finish]
  );

  const start = useCallback(
    async (id, force = false) => {
      if (timers.current[id] || running.has(id)) return;
      mark(id, true);
      const controller = new AbortController();
      controllers.current[id] = controller;
      try {
        const response = await api.post(`/documents/${id}/validate`, force ? { confirm: true } : {}, { signal: controller.signal });
        if (response.status === 202 || response.data?.queued) {
          if (response.data?.message) toast?.info?.(response.data.message);
          poll(id, Date.now());
          return;
        }
        const [kind, text] = doneMessage(response.data?.document, response.data?.message);
        finish(id, kind, text);
      } catch (err) {
        if (isCancel(err)) return;
        if (err.response?.status === 409 && err.response.data?.needs_confirmation && !force) {
          mark(id, false);
          const again = confirm
            ? await confirm({ title: "Already verified by staff", message: err.response.data.message, confirmLabel: "Run AI check anyway" })
            : false;
          if (again) return startRef.current?.(id, true);
          if (!confirm) toast?.info?.(err.response.data.message);
          return;
        }
        finish(id, "error", errMsg(err, "The AI check could not run."));
      } finally {
        if (controllers.current[id] === controller) delete controllers.current[id];
      }
    },
    [confirm, finish, mark, poll, running, toast]
  );

  useEffect(() => {
    pollRef.current = poll;
    startRef.current = start;
  }, [poll, start]);

  const cancel = useCallback(
    async (id, quiet = false) => {
      controllers.current[id]?.abort();
      delete controllers.current[id];
      window.clearTimeout(timers.current[id]);
      delete timers.current[id];
      mark(id, false);
      try {
        const { data } = await api.post(`/documents/${id}/cancel-check`);
        if (!quiet) toast?.info?.(data.message);
      } catch (err) {
        if (!quiet) toast?.error?.(errMsg(err, "Could not cancel the AI check."));
      }
      onChange?.();
    },
    [mark, onChange, toast]
  );

  const cancelAll = useCallback(async () => {
    stopAll.current = true;
    const ids = [...new Set([...Object.keys(timers.current), ...Object.keys(controllers.current)].map(Number))];
    await Promise.all(ids.map((id) => cancel(id, true)));
    toast?.info?.(ids.length ? `Cancelled ${ids.length} AI check${ids.length === 1 ? "" : "s"}. Nothing was saved.` : "No AI check was running.");
  }, [cancel, toast]);

  const startAll = useCallback(
    async (documents) => {
      const todo = (documents || []).filter(needsAiCheck).filter((d) => !timers.current[d.id]);
      if (!todo.length) {
        toast?.info?.("Every document was already checked by the AI or verified by staff.");
        return;
      }
      stopAll.current = false;
      for (const document of todo) {
        if (stopAll.current) break;
        await start(document.id);
      }
    },
    [start, toast]
  );

  const resume = useCallback(
    (documents) => {
      (documents || []).forEach((document) => {
        if (document?.status === "processing" && !timers.current[document.id]) {
          mark(document.id, true);
          poll(document.id, Date.now());
        }
      });
    },
    [mark, poll]
  );

  const isRunning = useCallback((id) => running.has(id), [running]);

  return { start, startAll, resume, isRunning, cancel, cancelAll, anyRunning: running.size > 0 };
}
