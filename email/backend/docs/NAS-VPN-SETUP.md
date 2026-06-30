# NAS Storage via VPN - Setup Guide

This document explains how the Email App connects to the Synology NAS at home for Drive storage.

## Architecture Overview

```
┌─────────────────────┐         Internet          ┌─────────────────────┐
│   VPS (devcon1.hu)  │◄────────────────────────►│  Home Synology NAS  │
│                     │      OpenVPN Tunnel       │                     │
│  Email App          │      (encrypted)          │  pixelranger.       │
│  /mnt/nas-drive ────┼──────────────────────────►│  synology.me        │
│                     │                           │                     │
│  VPN IP: 10.8.0.6   │                           │  VPN IP: 10.8.0.1   │
│                     │                           │  LAN IP: 192.168.   │
│                     │                           │          1.106      │
└─────────────────────┘                           └─────────────────────┘
```

## Connection Details

| Component | Value |
|-----------|-------|
| Synology DDNS | `pixelranger.synology.me` |
| VPN Protocol | OpenVPN (TCP, port 1194) |
| VPS VPN IP | `10.8.0.6` |
| NAS VPN IP | `10.8.0.1` |
| NAS LAN IP | `192.168.1.106` |
| NFS Mount Point | `/mnt/nas-drive` |
| NFS Share Path | `/volume1/mailflow-drive` |

---

## VPS Configuration Files

### OpenVPN Config
**Location:** `/etc/openvpn/client/synology.conf`

```conf
dev tun
nobind
tls-client

remote pixelranger.synology.me 1194

# ... certificates and keys ...

proto tcp-client
auth-user-pass /etc/openvpn/client/synology-auth.txt
script-security 2
up /etc/openvpn/client/synology-up.sh
```

### Credentials
**Location:** `/etc/openvpn/client/synology-auth.txt`

```
your_synology_username
your_synology_password
```

**Permissions:** `chmod 600 /etc/openvpn/client/synology-auth.txt`

### Route Script
**Location:** `/etc/openvpn/client/synology-up.sh`

```bash
#!/bin/bash
ip route add 192.168.1.0/24 via 10.8.0.5 dev tun0
```

**Permissions:** `chmod +x /etc/openvpn/client/synology-up.sh`

### NFS Mount (fstab)
**Location:** `/etc/fstab`

```
192.168.1.106:/volume1/mailflow-drive /mnt/nas-drive nfs defaults,_netdev,x-systemd.automount,x-systemd.requires=openvpn-client@synology.service 0 0
```

---

## Synology NAS Configuration

### VPN Server Settings
- **Package:** VPN Server
- **Protocol:** OpenVPN
- **Port:** 1194 (TCP)
- **Dynamic IP:** 10.8.0.1
- **Allow clients to access server's LAN:** YES (critical!)

### NFS Permissions
- **Control Panel > Shared Folder > mailflow-drive > NFS Permissions**
- **Allowed IP:** `10.8.0.0/24` (VPN subnet)
- **Privilege:** Read/Write
- **Squash:** Map all users to admin
- **Allow non-privileged ports:** Yes
- **Allow subfolders:** Yes

### Router Port Forward
- **Protocol:** TCP (not UDP!)
- **External Port:** 1194
- **Internal IP:** 192.168.1.106 (NAS)
- **Internal Port:** 1194

---

## Service Management

### Check VPN Status
```bash
systemctl status openvpn-client@synology
```

### Restart VPN
```bash
systemctl restart openvpn-client@synology
```

### Check if NAS is Reachable
```bash
ping 192.168.1.106
```

### Check NFS Mount
```bash
df -h /mnt/nas-drive
ls -la /mnt/nas-drive
```

### Manual Mount (if needed)
```bash
mount -t nfs 192.168.1.106:/volume1/mailflow-drive /mnt/nas-drive
```

### View VPN Logs
```bash
journalctl -u openvpn-client@synology -f
```

---

## Troubleshooting

### VPN Won't Connect

1. **Check Synology DDNS resolves correctly:**
   ```bash
   nslookup pixelranger.synology.me
   ```

2. **Verify home IP hasn't changed:**
   Compare nslookup result with https://whatismyip.com from home

3. **Check router port forward:**
   - Must be TCP 1194
   - Destination must be NAS IP (192.168.1.106)

4. **Check Synology VPN Server is running:**
   - Open VPN Server app on Synology
   - Check "Connection List" for attempts

### NFS Mount Fails with "Access Denied"

1. **Check NFS permissions on Synology:**
   - Control Panel > Shared Folder > mailflow-drive > Edit > NFS Permissions
   - Must allow `10.8.0.0/24`

2. **Check VPN is connected first:**
   ```bash
   ip addr show tun0
   ping 192.168.1.106
   ```

### Files Not Going to NAS

1. **Check Email App storage settings:**
   - Settings > Advanced > Drive Storage
   - Should show "NAS Storage (NFS)" with `/mnt/nas-drive`

2. **Check storage config file:**
   ```bash
   cat /var/www/vps-email/data/global/storage_config.json
   ```
   Should show `"driver": "nfs"`

---

## Email App Storage Configuration

Storage configuration is now managed via the **Panel** application. The Email App queries the Panel API to determine which storage to use.

### Panel API Integration

The Email App calls:
```
GET {PANEL_API_URL}/api/storage/config
Authorization: Bearer {PANEL_API_KEY}
```

Expected response:
```json
{
  "success": true,
  "data": {
    "default_storage": {
      "id": 1,
      "name": "Synology NAS",
      "type": "nfs",
      "mount_point": "/mnt/nas-drive",
      "status": "connected"
    },
    "domain_overrides": [
      {
        "domain": "client.com",
        "sub_path": "client-folder",
        "storage": {
          "id": 2,
          "name": "VPS Backup",
          "type": "nfs",
          "mount_point": "/mnt/vps-backup"
        }
      }
    ]
  }
}
```

### Environment Variables

Set in the server environment or `.env`:
```bash
PANEL_API_URL=https://panel.devcon1.hu/api
PANEL_API_KEY=your-secret-api-key
```

### Fallback

If Panel is unavailable, the Email App falls back to local storage defined in `config.php`:
```php
'drive' => [
    'storage_path' => '/var/www/vps-email/storage/drive',
],
```

**To change storage configuration:**
1. Go to Panel > NAS Storage
2. Configure storage connections
3. Set default storage or assign per-domain overrides
4. Email App automatically picks up changes (cached for 5 minutes)

---

## Security Notes

- VPN credentials stored in `/etc/openvpn/client/synology-auth.txt` (chmod 600)
- OpenVPN encrypts all traffic between VPS and NAS
- NFS traffic only travels over encrypted VPN tunnel
- Router port forward is restricted (can add source IP filter for VPS: 185.208.227.207)

---

## Useful Commands Cheatsheet

```bash
# VPN
systemctl status openvpn-client@synology    # Check status
systemctl restart openvpn-client@synology   # Restart
journalctl -u openvpn-client@synology -f    # View logs

# Network
ping 10.8.0.1                               # Ping VPN gateway
ping 192.168.1.106                          # Ping NAS
ip route | grep 192.168                     # Check routes

# NFS
df -h /mnt/nas-drive                        # Check mount & space
mount -a                                     # Remount all fstab entries
ls -la /mnt/nas-drive                       # List files

# DNS
nslookup pixelranger.synology.me            # Check DDNS resolution
```

---

## Change History

| Date | Change |
|------|--------|
| 2026-01-26 | Initial setup - VPN + NFS connection to Synology NAS |

