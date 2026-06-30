# FlowOne PRODUCTION mailsync server (IMAP IDLE + Redis pub/sub WebSocket, :1235).
#
# Build context: the email/ directory. Build with:
#   docker build -f docker/mailsync/Dockerfile -t flowone-mailsync:latest .
#
# Bakes the Node service (npm ci --omit=dev). Self-contained under server/.
# NOTE: keep this single-replica — it holds in-memory IMAP IDLE + call state.
FROM node:20-bookworm-slim

ENV NODE_ENV=production
WORKDIR /app

# Install prod deps first for layer caching (lockfile present -> npm ci).
COPY mailsync/server/package*.json ./
RUN npm ci --omit=dev || npm install --omit=dev

# App source.
COPY mailsync/server /app

EXPOSE 1235

# TCP liveness: the WS server is up once it accepts connections on its port.
HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=5 \
  CMD node -e "require('net').connect(${MAILSYNC_WS_PORT:-1235},'127.0.0.1').on('connect',()=>process.exit(0)).on('error',()=>process.exit(1))"

CMD ["node", "src/index.js"]
