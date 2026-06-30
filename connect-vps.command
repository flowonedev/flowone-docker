#!/bin/bash
# Double-click this file (or run it) to SSH into the FlowOne VPS.
# Host: flowone.pro  |  Port: 1985  |  User: pxr

KEY="/Users/pixelranger/WORK/ssh_keys/vps/vps_sftp_key"
HOST="flowone.pro"
PORT="1985"
USER="pxr"

if [ ! -f "$KEY" ]; then
  echo "SSH key not found at: $KEY"
  exit 1
fi

# ssh refuses keys that are group/world-accessible.
chmod 600 "$KEY" 2>/dev/null

echo "Connecting to ${USER}@${HOST}:${PORT} ..."
exec ssh -i "$KEY" -p "$PORT" "${USER}@${HOST}"
