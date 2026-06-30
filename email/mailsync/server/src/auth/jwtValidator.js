/**
 * JWT Validator
 * 
 * Validates JWT tokens from the PHP backend.
 * Supports RS256 (public key) with HS256 fallback during migration.
 */

import jwt from 'jsonwebtoken'
import { config } from '../config.js'

/**
 * Get the verification key and algorithm based on config.
 * RS256 uses the public key; HS256 uses the shared secret.
 */
function getVerificationKey() {
  if (config.jwt.algorithm === 'RS256' && config.jwt.publicKey) {
    return config.jwt.publicKey
  }
  return config.jwt.secret
}

/**
 * Validate a JWT token and extract user info.
 * 
 * Tries the configured algorithm first (RS256).
 * Falls back to HS256 if the primary fails and a secret is available,
 * allowing old tokens to remain valid during migration.
 * 
 * @param {string} token - JWT token from client
 * @returns {object|null} Decoded token payload or null if invalid
 */
export function validateToken(token) {
  if (!token) {
    console.warn('[JWT] validateToken called with empty/null token')
    return null
  }

  // Remove 'Bearer ' prefix if present
  const cleanToken = token.startsWith('Bearer ') ? token.slice(7) : token

  // Log token diagnostics (first/last chars only for security)
  const tokenPreview = cleanToken.length > 20
    ? `${cleanToken.substring(0, 10)}...${cleanToken.substring(cleanToken.length - 10)} (len=${cleanToken.length})`
    : `(len=${cleanToken.length})`

  // Try primary algorithm first
  try {
    const verifyKey = getVerificationKey()

    if (!verifyKey) {
      console.error('[JWT] FATAL: No verification key available! Set JWT_PUBLIC_KEY_PATH (RS256) or JWT_SECRET (HS256).')
      return null
    }

    console.log(`[JWT] Validating token: ${tokenPreview}, algo=${config.jwt.algorithm}`)

    const decoded = jwt.verify(cleanToken, verifyKey, {
      algorithms: [config.jwt.algorithm],
    })

    const email = decoded.email || decoded.sub
    if (!email) {
      console.error('[JWT] Token missing email/sub field. Token fields:', Object.keys(decoded).join(', '))
      return null
    }

    console.log(`[JWT] Token valid for: ${email}`)

    const result = {
      email,
      exp: decoded.exp,
      iat: decoded.iat,
      ...decoded,
    }

    if (decoded.type === 'mood_guest') {
      result.isMoodGuest = true
      result.allowedBoardId = decoded.board_id
      result.guestId = decoded.guest_id
      result.displayName = decoded.guest_name || 'Guest'
      console.log(`[JWT] Mood guest token: board=${decoded.board_id}, guest=${decoded.guest_id}`)
    }

    return result
  } catch (primaryError) {
    // If RS256 failed and we have an HS256 secret, try fallback
    if (config.jwt.algorithm === 'RS256' && config.jwt.secret) {
      try {
        const decoded = jwt.verify(cleanToken, config.jwt.secret, {
          algorithms: ['HS256'],
        })

        const email = decoded.email || decoded.sub
        if (!email) {
          console.error('[JWT] Token missing email/sub field (HS256 fallback).')
          return null
        }

        console.log(`[JWT] Token valid via HS256 fallback for: ${email} — migration still in progress`)

        const result = {
          email,
          exp: decoded.exp,
          iat: decoded.iat,
          ...decoded,
        }

        if (decoded.type === 'mood_guest') {
          result.isMoodGuest = true
          result.allowedBoardId = decoded.board_id
          result.guestId = decoded.guest_id
          result.displayName = decoded.guest_name || 'Guest'
        }

        return result
      } catch (fallbackError) {
        // Both failed
        logTokenError(primaryError, tokenPreview)
        return null
      }
    }

    logTokenError(primaryError, tokenPreview)
    return null
  }
}

/**
 * Log JWT validation errors with appropriate detail
 */
function logTokenError(error, tokenPreview) {
  if (error.name === 'TokenExpiredError') {
    console.error('[JWT] Token EXPIRED at:', new Date(error.expiredAt).toISOString())
  } else if (error.name === 'JsonWebTokenError') {
    console.error('[JWT] Invalid token:', error.message, '| Preview:', tokenPreview)
  } else {
    console.error('[JWT] Validation error:', error.name, error.message)
  }
}

/**
 * Check if a token is about to expire (within threshold)
 * @param {object} decoded - Decoded token
 * @param {number} thresholdSeconds - Seconds before expiry to consider "expiring soon"
 * @returns {boolean} True if expiring soon
 */
export function isTokenExpiringSoon(decoded, thresholdSeconds = 300) {
  if (!decoded || !decoded.exp) {
    return true
  }
  
  const now = Math.floor(Date.now() / 1000)
  return decoded.exp - now < thresholdSeconds
}

/**
 * Extract token from WebSocket upgrade request
 * @param {object} request - HTTP upgrade request
 * @returns {string|null} Token or null
 */
export function extractTokenFromRequest(request) {
  // Try Authorization header first
  const authHeader = request.headers['authorization']
  if (authHeader && authHeader.startsWith('Bearer ')) {
    return authHeader.slice(7)
  }

  // Try query parameter (for WebSocket connections)
  const url = new URL(request.url, `http://${request.headers.host}`)
  const tokenParam = url.searchParams.get('token')
  if (tokenParam) {
    return tokenParam
  }

  // Try Sec-WebSocket-Protocol header (alternative method)
  const protocol = request.headers['sec-websocket-protocol']
  if (protocol) {
    // Protocol can be comma-separated, token would be one of them
    const protocols = protocol.split(',').map(p => p.trim())
    for (const p of protocols) {
      if (p.startsWith('token.')) {
        return p.slice(6)
      }
    }
  }

  return null
}
