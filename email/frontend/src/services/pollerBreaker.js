// pollerBreaker.js — circuit breaker for client-side pollers.
//
// Phase 1 of the OAuth + IMAP rewrite. The frontend has many independent
// setInterval loops (unread counts, notifications, sync reconciliation,
// linked-account sync, calendar auto-sync, attachment indexing, body
// indexing, etc). When the server returns sustained 403/5xx, the pollers
// just keep firing on schedule and refresh the CPGuard ban timer.
//
// This module provides:
//   * a per-poller consecutive-error counter that suspends a single
//     poller for 5 minutes after N failures, and
//   * a global pause flag that ALL pollers must check on every tick,
//     tripped automatically when the api.js interceptor sees a 403 that
//     looks like a CPGuard / WAF block.
//
// Pollers integrate by calling pollerShouldRun(id) at the top of their
// tick, and pollerRecordResult(id, errorOrNull) after each request.

const POLLER_ERROR_THRESHOLD = 3;
const POLLER_SUSPEND_MS = 5 * 60 * 1000;        // 5 minutes
const GLOBAL_PAUSE_DEFAULT_MS = 15 * 60 * 1000; // 15 minutes

const pollerStates = new Map();
let globalPauseUntil = 0;
let globalPauseReason = null;

const listeners = new Set();

function notify() {
  for (const fn of listeners) {
    try { fn({ globalPauseUntil, globalPauseReason }); } catch (e) {}
  }
}

export function onBreakerChange(fn) {
  listeners.add(fn);
  return () => listeners.delete(fn);
}

export function pollerShouldRun(pollerId) {
  if (Date.now() < globalPauseUntil) return false;
  const state = pollerStates.get(pollerId);
  if (!state) return true;
  return Date.now() >= state.suspendUntil;
}

export function pollerRecordResult(pollerId, error) {
  if (!error) {
    pollerStates.set(pollerId, { consecutiveErrors: 0, suspendUntil: 0 });
    return;
  }
  const status = error?.response?.status;
  // Only network-level / server-level errors trip the breaker. 401/404/422
  // are application errors and should not be confused with "the server
  // hates us right now".
  const tripStatuses = status === 403 || status === 0 || (status >= 500 && status < 600);
  const tripNetwork = !status && (error?.code === 'ECONNABORTED' || error?.message?.includes('Network Error'));
  if (!tripStatuses && !tripNetwork) {
    return;
  }
  const state = pollerStates.get(pollerId) || { consecutiveErrors: 0, suspendUntil: 0 };
  state.consecutiveErrors += 1;
  if (state.consecutiveErrors >= POLLER_ERROR_THRESHOLD) {
    state.suspendUntil = Date.now() + POLLER_SUSPEND_MS;
    console.warn(
      `[pollerBreaker] "${pollerId}" tripped after ${state.consecutiveErrors} consecutive errors (status=${status}); suspended for ${Math.round(POLLER_SUSPEND_MS / 1000)}s`
    );
    state.consecutiveErrors = 0; // reset so the next batch can also trip
  }
  pollerStates.set(pollerId, state);
}

export function pauseAllPollers(reason, durationMs = GLOBAL_PAUSE_DEFAULT_MS) {
  const until = Date.now() + durationMs;
  if (until > globalPauseUntil) {
    globalPauseUntil = until;
    globalPauseReason = reason;
    console.warn(`[pollerBreaker] GLOBAL pause for ${Math.round(durationMs / 1000)}s — reason: ${reason}`);
    notify();
  }
}

export function resumeAllPollers() {
  if (globalPauseUntil > 0) {
    globalPauseUntil = 0;
    globalPauseReason = null;
    console.info('[pollerBreaker] global pause cleared by caller');
    notify();
  }
}

export function getBreakerState() {
  return {
    globalPauseUntil,
    globalPauseReason,
    pollers: Object.fromEntries(pollerStates),
  };
}

/**
 * Heuristic for whether a 403 looks like a security plugin (CPGuard,
 * ModSec, Cloudflare WAF) rather than a real authorization failure.
 *
 * These pages typically render a small HTML stub that mentions the
 * blocking product or are served from a `/error_pages/` path. Real
 * application 403s come back as JSON with our error envelope.
 */
export function looksLikeSecurityBlock(error) {
  if (error?.response?.status !== 403) return false;
  const data = error.response.data;
  if (typeof data === 'string') {
    const lower = data.toLowerCase();
    return /cpguard|cloudflare|mod[_-]?sec|access denied|imunify|web application firewall/.test(lower);
  }
  // JSON body with our own envelope: not a WAF block.
  if (data && typeof data === 'object' && 'success' in data) {
    return false;
  }
  // Non-JSON, non-string body on a 403 → almost certainly the WAF
  // returned its default HTML page that axios couldn't parse.
  return data == null;
}
