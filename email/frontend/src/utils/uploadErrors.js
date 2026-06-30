/**
 * Human-readable message for a failed file upload.
 *
 * Covers both transports the apps use:
 *   - axios pipeline (web/desktop): timeouts surface as `code: 'ECONNABORTED'`.
 *   - CapacitorHttp-bypass fetch (native): timeouts surface as an AbortError,
 *     which `uploadFormData` normalizes to `code: 'ECONNABORTED'`.
 *
 * Handles, in order: timeouts/cancellation, no-response network failures,
 * 413 / WAF HTML block pages (which almost always mean "body too large"), and
 * explicit server messages returned as either `error` (chat backend) or
 * `message` (drive backend).
 *
 * @param {any} e Error thrown by `uploadFormData` / axios.
 * @returns {string} A short, user-facing reason.
 */
export function describeUploadError(e) {
  // Timed out (AbortController on native; axios `timeout` on web) or cancelled.
  if (e?.name === "AbortError" || e?.code === "ECONNABORTED") {
    return "Upload timed out — check your connection and try again";
  }

  const res = e?.response;
  if (!res) {
    // No HTTP response at all: DNS/TLS/CORS/connection-dropped failures land here.
    const msg = e?.message;
    if (msg && msg !== "Network Error" && msg !== "Failed to fetch") return msg;
    return "Network error or connection lost";
  }

  const status = res.status;
  const contentType = String(res.headers?.["content-type"] || "").toLowerCase();

  // 413, or a WAF/proxy HTML error page, almost always means the request body
  // exceeded a server-side size limit (OpenLiteSpeed / LSAPI / PHP).
  if (status === 413) return "File too large for the server";
  if (contentType.includes("text/html")) {
    return "File too large or blocked by the server";
  }

  // Chat backend returns { error }, drive backend returns { message }.
  const apiMessage = res.data?.error || res.data?.message;
  if (apiMessage) return apiMessage;

  return `Upload failed (HTTP ${status})`;
}
