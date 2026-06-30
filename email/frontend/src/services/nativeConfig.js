/**
 * Native app (Capacitor) detection.
 *
 * When running inside the Capacitor shell the web assets are served from a
 * local scheme, so relative API URLs like "/api/..." won't reach the backend.
 * The per-deployment origin to prepend is resolved dynamically (from the user's
 * email domain at login) by serverRegistry — see getApiOrigin() / getWsUrl().
 *
 * The historical static `API_ORIGIN` constant (hardcoded to flowone.pro) has
 * been removed: a single binary now ships to many per-domain deployments.
 */

import { isNative, getApiOrigin } from './serverRegistry'

export { isNative, getApiOrigin }
