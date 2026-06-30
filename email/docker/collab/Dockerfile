# FlowOne PRODUCTION collab server (Hocuspocus WebSocket, :1234).
#
# Build context: the email/ directory. Build with:
#   docker build -f docker/collab/Dockerfile -t flowone-collab:latest .
#
# Bakes the Node service (npm ci --omit=dev) instead of the dev bind-mount.
# src imports ../../shared/collabConstants.js, so the sibling shared/ dir is
# copied alongside the server (layout: /app/server + /app/shared).
FROM node:20-bookworm-slim

ENV NODE_ENV=production
WORKDIR /app/server

# Install prod deps first for layer caching (lockfile present -> npm ci).
COPY collab/server/package*.json ./
RUN npm ci --omit=dev || npm install --omit=dev

# App source + the shared constants it imports via ../../shared.
COPY collab/server /app/server
COPY collab/shared /app/shared

EXPOSE 1234

# TCP liveness: the WS server is up once it accepts connections on its port.
HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=5 \
  CMD node -e "require('net').connect(${COLLAB_WS_PORT:-1234},'127.0.0.1').on('connect',()=>process.exit(0)).on('error',()=>process.exit(1))"

CMD ["node", "src/index.js"]
