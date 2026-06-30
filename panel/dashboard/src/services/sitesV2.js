// panel/dashboard/src/services/sitesV2.js
//
// Thin client for the v2 (asynchronous, queue-backed) site
// provisioning endpoints. Every method returns a Promise that
// resolves to the deserialised `data` portion of the agent
// envelope, or rejects with an Error whose `status` property
// matches the HTTP status the controller mapped.
//
// Design notes:
//   - The legacy /api/sites endpoints stay supported for backwards
//     compatibility. New UI code MUST use this module so the operator
//     gets job IDs + live progress streams rather than blocking HTTP
//     calls.
//   - `pollJobUntilTerminal` is the canonical way to wait for a
//     specific job to finish from the UI. It uses exponential
//     backoff capped at 5s and gives up after `timeoutMs` so a
//     dropped network connection doesn't hang the page.
//   - `tailJobEvents` is intended for the operator details panel:
//     it polls /api/jobs/{id}/events with `since_id` cursor to get
//     incremental progress lines without re-fetching the whole
//     timeline.

import api from "@/services/api";

const V2 = "/sites/v2";

function unwrap(response) {
  if (!response || !response.data) {
    throw new Error("Empty response from API");
  }
  if (response.data.success === false) {
    const err = new Error(response.data.error || "Request failed");
    err.details = response.data.details || null;
    err.status = response.status;
    throw err;
  }
  return response.data.data ?? response.data;
}

// ─────────────────────────────────────────────────────────────
// Site CRUD
// ─────────────────────────────────────────────────────────────

/**
 * Enqueue a CREATE job for a new site. Resolves to:
 *   { job: { id, status: "queued", ... }, site_id, site, duplicate }
 */
export async function createSite(payload) {
  const response = await api.post(V2, payload);
  return unwrap(response);
}

/**
 * Enqueue a DELETE job. `domain` is the path key; `payload`
 * carries optional knobs like skip_snapshot.
 */
export async function deleteSite(domain, payload = {}) {
  const response = await api.delete(`${V2}/${encodeURIComponent(domain)}`, {
    data: payload,
  });
  return unwrap(response);
}

export async function listSites(params = {}) {
  const response = await api.get(V2, { params });
  return unwrap(response);
}

export async function getSite(domain) {
  const response = await api.get(`${V2}/${encodeURIComponent(domain)}`);
  return unwrap(response);
}

// ─────────────────────────────────────────────────────────────
// Lifecycle transitions (Step 4c)
// ─────────────────────────────────────────────────────────────

export async function suspendSite(domain, payload = {}) {
  const response = await api.post(
    `${V2}/${encodeURIComponent(domain)}/suspend`,
    payload,
  );
  return unwrap(response);
}

export async function resumeSite(domain, payload = {}) {
  const response = await api.post(
    `${V2}/${encodeURIComponent(domain)}/resume`,
    payload,
  );
  return unwrap(response);
}

export async function archiveSite(domain, payload = {}) {
  const response = await api.post(
    `${V2}/${encodeURIComponent(domain)}/archive`,
    payload,
  );
  return unwrap(response);
}

export async function restoreSite(domain, payload = {}) {
  if (!payload || !payload.payload || !payload.payload.archive_path) {
    throw new Error(
      "restoreSite requires payload.archive_path to identify the archive to hydrate from",
    );
  }
  const response = await api.post(
    `${V2}/${encodeURIComponent(domain)}/restore`,
    payload,
  );
  return unwrap(response);
}

/**
 * List archive directories on disk, optionally scoped to one domain.
 * Returns:
 *   { root, domain, archives: [...], count, partial? }
 *
 * Each archive entry has:
 *   path, domain, name, archived_at, archived_at_unix, job_id,
 *   size_bytes, mtime_unix
 *
 * The Restore picker passes the selected `path` into restoreSite() so
 * the operator never has to type a long absolute path.
 */
export async function listArchives(domain = null, params = {}) {
  const url = domain
    ? `${V2}/${encodeURIComponent(domain)}/archives`
    : `${V2}/archives`;
  const response = await api.get(url, { params });
  return unwrap(response);
}

/**
 * Hard-delete a tombstone (a site whose actual_state is already
 * 'absent' from a successful DELETE saga). Removes the sites row,
 * all dependent history tables, and the snapshot directory on disk.
 *
 * Refuses to operate on live sites — those must be deleted through
 * the saga first. This is the only "fully forget this site" path
 * exposed by the panel.
 *
 * `payload.dry_run = true` returns row counts without applying any
 * changes; the UI uses this for the preview step in the confirmation
 * modal.
 */
export async function purgeTombstone(domain, payload = {}) {
  const response = await api.post(
    `${V2}/${encodeURIComponent(domain)}/purge`,
    payload,
  );
  return unwrap(response);
}

// ─────────────────────────────────────────────────────────────
// Jobs
// ─────────────────────────────────────────────────────────────

export async function listJobs(params = {}) {
  const response = await api.get("/jobs", { params });
  return unwrap(response);
}

export async function getJob(id) {
  const response = await api.get(`/jobs/${id}`);
  return unwrap(response);
}

export async function getJobEvents(id, sinceId = 0, limit = 100) {
  const response = await api.get(`/jobs/${id}/events`, {
    params: { since_id: sinceId, limit },
  });
  return unwrap(response);
}

export async function cancelJob(id, reason = "cancelled via UI") {
  const response = await api.post(`/jobs/${id}/cancel`, { reason });
  return unwrap(response);
}

export async function retryJob(id, reason = "retry requested via UI") {
  const response = await api.post(`/jobs/${id}/retry`, { reason });
  return unwrap(response);
}

// ─────────────────────────────────────────────────────────────
// High-level helpers
// ─────────────────────────────────────────────────────────────

/**
 * Poll /api/jobs/{id} until the job reaches a terminal status or
 * `timeoutMs` elapses. Calls `onProgress(job)` after every poll
 * so the UI can refresh its view.
 *
 * The polling backoff doubles up to 5 seconds, so the first few
 * checks happen quickly and long-running jobs settle into a
 * sustainable rate.
 *
 * Resolves to the final job object; rejects on timeout or
 * persistent fetch errors (3+ consecutive failures).
 */
export async function pollJobUntilTerminal(
  jobId,
  { timeoutMs = 5 * 60 * 1000, onProgress } = {},
) {
  const start = Date.now();
  let delay = 500;
  let failures = 0;

  while (true) {
    if (Date.now() - start > timeoutMs) {
      throw new Error(`Job ${jobId} did not finish within ${timeoutMs}ms`);
    }
    try {
      const data = await getJob(jobId);
      failures = 0;
      const job = data?.job;
      if (typeof onProgress === "function") {
        try {
          onProgress(data);
        } catch {
          // swallow callback errors so they don't abort polling
        }
      }
      if (job?.terminal) {
        return data;
      }
    } catch (e) {
      failures += 1;
      if (failures >= 3) {
        throw e;
      }
    }
    await new Promise((r) => setTimeout(r, delay));
    delay = Math.min(delay * 1.5, 5000);
  }
}

/**
 * Tail job events for live progress display. Calls `onEvent(event)`
 * for each new event until the returned function is invoked or the
 * job finishes. Returns a teardown function the caller MUST call
 * when the component unmounts.
 *
 * Example:
 *   const stop = tailJobEvents(123, {
 *     onEvent: (e) => events.value.push(e),
 *     onTerminal: (status) => { stop(); refreshSite() },
 *   })
 *   onUnmounted(stop)
 */
export function tailJobEvents(
  jobId,
  { onEvent, onTerminal, intervalMs = 1500 } = {},
) {
  let cancelled = false;
  let sinceId = 0;
  let timer = null;

  const tick = async () => {
    if (cancelled) return;
    try {
      const data = await getJobEvents(jobId, sinceId, 200);
      if (Array.isArray(data?.events)) {
        for (const event of data.events) {
          if (cancelled) return;
          if (typeof onEvent === "function") onEvent(event);
        }
      }
      if (data?.last_id) sinceId = data.last_id;
      if (data?.job_terminal && typeof onTerminal === "function") {
        cancelled = true;
        onTerminal(data.job_status);
        return;
      }
    } catch {
      // Transient errors silently retry; persistent ones eventually
      // surface via the calling component's own error handling
      // since the events panel is non-critical.
    }
    if (!cancelled) {
      timer = setTimeout(tick, intervalMs);
    }
  };

  // Kick off the loop immediately so the user sees the first
  // batch of historical events without waiting for the polling
  // interval.
  tick();

  return () => {
    cancelled = true;
    if (timer) clearTimeout(timer);
  };
}

export default {
  createSite,
  deleteSite,
  listSites,
  getSite,
  suspendSite,
  resumeSite,
  archiveSite,
  restoreSite,
  purgeTombstone,
  listJobs,
  getJob,
  getJobEvents,
  cancelJob,
  retryJob,
  pollJobUntilTerminal,
  tailJobEvents,
};
