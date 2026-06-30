import { Notification, app } from 'electron'
import path from 'path'

export class NotificationManager {
  private enabled = true

  constructor() {
    // Check if notifications are supported
    if (!Notification.isSupported()) {
      console.warn('Desktop notifications not supported on this platform')
      this.enabled = false
    }
  }

  setEnabled(value: boolean): void {
    this.enabled = value
  }

  show(title: string, body: string, onClick?: () => void): void {
    if (!this.enabled || !Notification.isSupported()) {
      return
    }

    const notification = new Notification({
      title,
      body,
      icon: this.getAppIcon(),
      silent: false,
    })

    if (onClick) {
      notification.on('click', onClick)
    }

    notification.show()
  }

  showSyncComplete(filesChanged: number): void {
    if (filesChanged === 0) return

    this.show(
      'Sync Complete',
      `${filesChanged} file${filesChanged > 1 ? 's' : ''} synchronized`
    )
  }

  showSyncError(message: string): void {
    this.show(
      'Sync Error',
      message
    )
  }

  showFileUploaded(filename: string): void {
    this.show(
      'File Uploaded',
      `"${filename}" has been uploaded to FlowOne Drive`
    )
  }

  showFileDownloaded(filename: string): void {
    this.show(
      'File Downloaded',
      `"${filename}" has been downloaded from FlowOne Drive`
    )
  }

  showConflict(filename: string, onClick?: () => void): void {
    this.show(
      'Sync Conflict',
      `"${filename}" was modified both locally and remotely. Click to resolve.`,
      onClick
    )
  }

  showSharedFolderChange(folderName: string, userEmail: string, action: string): void {
    this.show(
      'Shared Folder Updated',
      `${userEmail} ${action} "${folderName}"`
    )
  }

  showNewShare(folderName: string, sharedBy: string): void {
    this.show(
      'New Shared Folder',
      `${sharedBy} shared "${folderName}" with you`
    )
  }

  // IMPORTANT: Collaborator changed a file notification
  showCollaboratorChange(filename: string, userEmail: string, folderName?: string): void {
    const location = folderName ? ` in "${folderName}"` : ''
    this.show(
      'File Updated by Collaborator',
      `${userEmail} modified "${filename}"${location}`
    )
  }

  showInstantSync(filename: string, action: 'uploaded' | 'downloaded' | 'deleted'): void {
    const actionText = action === 'uploaded' ? 'synced to cloud' : action === 'downloaded' ? 'downloaded' : 'deleted'
    this.show(
      'Instant Sync',
      `"${filename}" ${actionText}`
    )
  }

  showFileEditing(filename: string, userEmail: string, folderName?: string): void {
    const location = folderName ? ` in "${folderName}"` : ''
    this.show(
      'File Being Edited',
      `${userEmail} is editing "${filename}"${location}`
    )
  }

  showFileEditingEnded(filename: string, userEmail: string): void {
    this.show(
      'Editing Ended',
      `${userEmail} stopped editing "${filename}"`
    )
  }

  private getAppIcon(): string | undefined {
    // macOS toasts always show the app's own icon; passing one again would
    // render it twice. Windows/Linux need an explicit on-disk path (asar
    // contents are not readable by the OS toast renderer, hence the
    // extraResources copy of icon.png).
    if (process.platform === 'darwin') {
      return undefined
    }
    const assetsDir = app.isPackaged
      ? path.join(process.resourcesPath, 'assets')
      : path.join(__dirname, '..', '..', 'assets')
    return path.join(assetsDir, 'icon.png')
  }
}

