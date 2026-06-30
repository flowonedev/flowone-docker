import { useStatisticsStore } from '@/stores/statistics'

/**
 * Simple event tracking composable
 * Use this to log various user actions for statistics
 */
export function useEventTracker() {
  const statisticsStore = useStatisticsStore()
  
  /**
   * Log an email sent event
   */
  async function trackEmailSent(recipients = []) {
    await statisticsStore.logEvent('email_sent', {
      to: recipients,
      timestamp: new Date().toISOString()
    })
  }
  
  /**
   * Log an email replied event
   */
  async function trackEmailReplied(originalMessageId, replyTimeSeconds = null) {
    await statisticsStore.logEvent('email_replied', {
      original_message_id: originalMessageId,
      reply_time_seconds: replyTimeSeconds,
      timestamp: new Date().toISOString()
    })
  }
  
  /**
   * Log an email moved event
   */
  async function trackEmailMoved(fromFolder, toFolder) {
    await statisticsStore.logEvent('email_moved', {
      from_folder: fromFolder,
      to_folder: toFolder,
      timestamp: new Date().toISOString()
    })
  }
  
  /**
   * Log an email deleted event
   */
  async function trackEmailDeleted() {
    await statisticsStore.logEvent('email_deleted', {
      timestamp: new Date().toISOString()
    })
  }
  
  /**
   * Log a task created event
   */
  async function trackTaskCreated(taskTitle = '') {
    await statisticsStore.logEvent('task_created', {
      title: taskTitle,
      timestamp: new Date().toISOString()
    })
  }
  
  /**
   * Log a task completed event
   */
  async function trackTaskCompleted(taskTitle = '') {
    await statisticsStore.logEvent('task_completed', {
      title: taskTitle,
      timestamp: new Date().toISOString()
    })
  }
  
  /**
   * Log a calendar event created
   */
  async function trackCalendarEventCreated(eventTitle = '') {
    await statisticsStore.logEvent('calendar_event_created', {
      title: eventTitle,
      timestamp: new Date().toISOString()
    })
  }
  
  /**
   * Log a file uploaded to drive
   */
  async function trackFileUploaded(fileName = '', fileSize = 0) {
    await statisticsStore.logEvent('drive_file_uploaded', {
      name: fileName,
      size: fileSize,
      timestamp: new Date().toISOString()
    })
  }
  
  /**
   * Log AI summary generated
   */
  async function trackAISummary() {
    await statisticsStore.logEvent('ai_summary', {
      timestamp: new Date().toISOString()
    })
  }
  
  /**
   * Log AI rewrite used
   */
  async function trackAIRewrite() {
    await statisticsStore.logEvent('ai_rewrite', {
      timestamp: new Date().toISOString()
    })
  }
  
  /**
   * Log theme change
   */
  async function trackThemeChanged(theme) {
    await statisticsStore.logEvent('theme_changed', {
      theme,
      timestamp: new Date().toISOString()
    })
  }
  
  /**
   * Log accent color change
   */
  async function trackAccentChanged(accent) {
    await statisticsStore.logEvent('accent_changed', {
      accent,
      timestamp: new Date().toISOString()
    })
  }
  
  return {
    trackEmailSent,
    trackEmailReplied,
    trackEmailMoved,
    trackEmailDeleted,
    trackTaskCreated,
    trackTaskCompleted,
    trackCalendarEventCreated,
    trackFileUploaded,
    trackAISummary,
    trackAIRewrite,
    trackThemeChanged,
    trackAccentChanged
  }
}

