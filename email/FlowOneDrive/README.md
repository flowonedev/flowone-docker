# FlowOne Drive

Desktop sync client for FlowOne Drive - sync your files like Google Drive or OneDrive.

## Features

- **Two-way sync**: Changes sync both from desktop to cloud and cloud to desktop
- **System tray**: Runs in the background with status indicator
- **File browser**: Built-in file browser with grid/list views
- **Shared folder notifications**: Get notified when collaborators make changes
- **Conflict resolution**: Handles file conflicts automatically or prompts user
- **Selective sync**: Choose which folders to sync (coming soon)

## Development

### Prerequisites

- Node.js 18+
- npm or yarn

### Setup

```bash
# Install dependencies
npm install

# Start development (runs both Electron main and Vite renderer)
npm run dev

# In another terminal, start Electron
npm start
```

### Build

```bash
# Build for production
npm run build

# Create distributable
npm run dist
```

## Project Structure

```
FlowOneDrive/
  src/
    main/           # Electron main process
      index.ts      # Entry point
      config.ts     # Configuration store
      database.ts   # SQLite database
      syncEngine.ts # Core sync logic
      fileWatcher.ts # File system watcher
      tray.ts       # System tray
      notifications.ts # Desktop notifications
    preload/        # Preload scripts (IPC bridge)
    renderer/       # Vue 3 frontend
      components/   # Vue components
      stores/       # Pinia stores
      styles/       # CSS
  assets/           # App icons
  dist/             # Build output
  release/          # Packaged app
```

## Configuration

The app stores its configuration in:
- Windows: `%APPDATA%/mailflow-drive-config.json`
- Sync database: `%APPDATA%/sync-state.db`
- Default sync folder: `%USERPROFILE%/FlowOneDrive`

## API Requirements

The app connects to a FlowOne backend that provides:

- `POST /api/auth/login` - Authentication
- `GET /api/drive` - List files and folders
- `GET /api/drive/folders/all` - Get all folders
- `GET /api/drive/files/{id}/download` - Download file
- `POST /api/drive/upload` - Upload file
- `POST /api/drive/upload-versioned` - Upload with versioning
- `DELETE /api/drive/files/{id}` - Delete file
- `GET /api/drive/shared-with-me` - Get shared folders

## License

MIT

