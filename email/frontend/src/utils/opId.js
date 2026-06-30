/**
 * Per-action idempotency id.
 *
 * Industry-standard mutation idempotency (Stripe `Idempotency-Key`, Gmail's
 * offline op queue): the CLIENT mints a unique id per user gesture and sends
 * it with the write. A network retry of the SAME gesture reuses the id so the
 * server collapses the duplicate; a brand-new gesture gets a fresh id so the
 * op is always honoured.
 *
 * The backend (MailboxController::resolveOpNonce) feeds this into the
 * imap_outbox idempotency key. This is what prevents the read -> unread ->
 * read "jumps back" divergence that a coarse server-side (per-day) nonce
 * caused: each click is its own durable intent.
 */
export function newOpId() {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID();
  }
  // Fallback for older runtimes without crypto.randomUUID.
  return 'op-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
}
