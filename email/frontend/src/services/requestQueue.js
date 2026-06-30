/**
 * Priority-based request queue with concurrency limiting.
 *
 * Prevents page-load burst overload by limiting the number of requests that
 * can be in-flight simultaneously. When the limit is reached, new requests
 * wait in a priority-sorted queue and are released in order.
 *
 * Priority levels (lower number = higher priority):
 *   CRITICAL (0) - Auth checks, core settings (needed before UI renders)
 *   HIGH     (1) - Accounts, folders (needed for the main view shell)
 *   NORMAL   (2) - Messages, labels, filters (main content)
 *   LOW      (3) - Notifications, todos, calendar, addons (background data)
 */

export const PRIORITY = {
  CRITICAL: 0,
  HIGH: 1,
  NORMAL: 2,
  LOW: 3,
};

const MAX_CONCURRENT = 6;
let activeCount = 0;
const queue = [];

/**
 * URL-to-priority mapping. Requests whose URL starts with one of these
 * prefixes get the associated priority automatically (unless the caller
 * explicitly sets config._priority).
 */
const PRIORITY_MAP = [
  ['/settings',      PRIORITY.CRITICAL],
  ['/auth/',         PRIORITY.CRITICAL],
  ['/accounts',      PRIORITY.HIGH],
  ['/folders',       PRIORITY.HIGH],
  ['/messages',      PRIORITY.NORMAL],
  ['/labels',        PRIORITY.NORMAL],
  ['/filters',       PRIORITY.NORMAL],
  ['/search',        PRIORITY.NORMAL],
  ['/notifications', PRIORITY.LOW],
  ['/todos',         PRIORITY.LOW],
  ['/addons',        PRIORITY.LOW],
  ['/calendar',      PRIORITY.LOW],
  ['/mood-boards',   PRIORITY.LOW],
  ['/board-pro/',    PRIORITY.LOW],
];

export function getPriorityForUrl(url) {
  if (!url) return PRIORITY.NORMAL;
  for (const [prefix, prio] of PRIORITY_MAP) {
    if (url.startsWith(prefix)) return prio;
  }
  return PRIORITY.NORMAL;
}

/**
 * Acquire a concurrency slot. Resolves immediately if a slot is free,
 * otherwise queues the caller in priority order and resolves when a
 * slot becomes available.
 */
export function acquire(priority = PRIORITY.NORMAL) {
  if (activeCount < MAX_CONCURRENT) {
    activeCount++;
    return Promise.resolve();
  }

  return new Promise((resolve) => {
    const item = { resolve, priority };
    const idx = queue.findIndex((q) => q.priority > priority);
    if (idx === -1) queue.push(item);
    else queue.splice(idx, 0, item);
  });
}

/**
 * Release a concurrency slot and wake the next queued request (if any).
 */
export function release() {
  activeCount = Math.max(0, activeCount - 1);
  if (queue.length > 0 && activeCount < MAX_CONCURRENT) {
    const next = queue.shift();
    activeCount++;
    next.resolve();
  }
}
