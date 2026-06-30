/**
 * Step guide data for all modules.
 * Each guide defines: titleKey, subtitleKey, headerIcon, headerColor, storageKey, steps[].
 * Step keys are resolved by StepGuide.vue through i18n t().
 */

const g = (mod, n) => `stepGuide.${mod}.${n}`
const v = (mod, step, idx) => `stepGuide.${mod}.s${step}v${idx}`

export const driveGuide = {
  titleKey: g('drive', 'title'),
  subtitleKey: g('drive', 'subtitle'),
  headerIcon: 'cloud',
  headerColor: 'blue',
  storageKey: 'drive_guide_dismissed',
  steps: [
    { icon: 'upload_file', titleKey: g('drive','s1title'), color: 'blue', descKey: g('drive','s1desc'), exampleKey: g('drive','s1example'),
      visual: [
        { labelKey: v('drive',1,1), icon: 'upload_file', accent: 'blue' },
        { labelKey: v('drive',1,2), icon: 'cloud_done', accent: 'green', arrow: true },
      ]},
    { icon: 'folder', titleKey: g('drive','s2title'), color: 'amber', descKey: g('drive','s2desc'), exampleKey: g('drive','s2example'),
      visual: [
        { labelKey: v('drive',2,1), icon: 'folder', accent: 'amber' },
        { labelKey: v('drive',2,2), icon: 'subdirectory_arrow_right', accent: 'surface', arrow: true },
        { labelKey: v('drive',2,3), icon: 'description', accent: 'blue', arrow: true },
      ]},
    { icon: 'share', titleKey: g('drive','s3title'), color: 'green', descKey: g('drive','s3desc'), exampleKey: g('drive','s3example'),
      visual: [
        { labelKey: v('drive',3,1), icon: 'folder_shared', accent: 'green' },
        { labelKey: v('drive',3,2), icon: 'group', accent: 'purple', arrow: true },
        { labelKey: v('drive',3,3), icon: 'edit', accent: 'blue', arrow: true },
      ]},
    { icon: 'history', titleKey: g('drive','s4title'), color: 'purple', descKey: g('drive','s4desc'), exampleKey: g('drive','s4example'),
      visual: [
        { labelKey: v('drive',4,1), icon: 'edit_document', accent: 'blue' },
        { labelKey: v('drive',4,2), icon: 'history', accent: 'purple', arrow: true },
        { labelKey: v('drive',4,3), icon: 'restore', accent: 'green', arrow: true },
      ]},
    { icon: 'hub', titleKey: g('drive','s5title'), color: 'primary', descKey: g('drive','s5desc'), exampleKey: g('drive','s5example'),
      visual: [
        { labelKey: v('drive',5,1), icon: 'attach_file', accent: 'primary' },
        { labelKey: v('drive',5,2), icon: 'view_kanban', accent: 'amber', arrow: true },
        { labelKey: v('drive',5,3), icon: 'chat', accent: 'blue', arrow: true },
      ]},
  ],
}

export const clientsGuide = {
  titleKey: g('clients', 'title'),
  subtitleKey: g('clients', 'subtitle'),
  headerIcon: 'domain',
  headerColor: 'green',
  storageKey: 'clients_guide_dismissed',
  steps: [
    { icon: 'domain_add', titleKey: g('clients','s1title'), color: 'green', descKey: g('clients','s1desc'), exampleKey: g('clients','s1example'),
      visual: [
        { labelKey: v('clients',1,1), icon: 'mail', accent: 'blue' },
        { labelKey: v('clients',1,2), icon: 'domain', accent: 'green', arrow: true },
      ]},
    { icon: 'contacts', titleKey: g('clients','s2title'), color: 'blue', descKey: g('clients','s2desc'), exampleKey: g('clients','s2example'),
      visual: [
        { labelKey: v('clients',2,1), icon: 'domain', accent: 'green' },
        { labelKey: v('clients',2,2), icon: 'person', accent: 'blue', arrow: true },
        { labelKey: v('clients',2,3), icon: 'person', accent: 'blue', arrow: true },
      ]},
    { icon: 'receipt', titleKey: g('clients','s3title'), color: 'amber', descKey: g('clients','s3desc'), exampleKey: g('clients','s3example'),
      visual: [
        { labelKey: v('clients',3,1), icon: 'badge', accent: 'amber' },
        { labelKey: v('clients',3,2), icon: 'account_balance', accent: 'surface', arrow: true },
        { labelKey: v('clients',3,3), icon: 'payments', accent: 'green', arrow: true },
      ]},
    { icon: 'link', titleKey: g('clients','s4title'), color: 'purple', descKey: g('clients','s4desc'), exampleKey: g('clients','s4example'),
      visual: [
        { labelKey: v('clients',4,1), icon: 'domain', accent: 'green' },
        { labelKey: v('clients',4,2), icon: 'link', accent: 'surface', arrow: true },
        { labelKey: v('clients',4,3), icon: 'view_kanban', accent: 'purple', arrow: true },
      ]},
    { icon: 'dashboard', titleKey: g('clients','s5title'), color: 'primary', descKey: g('clients','s5desc'), exampleKey: g('clients','s5example'),
      visual: [
        { labelKey: v('clients',5,1), icon: 'schedule', accent: 'blue' },
        { labelKey: v('clients',5,2), icon: 'task', accent: 'amber', arrow: true },
        { labelKey: v('clients',5,3), icon: 'payments', accent: 'green', arrow: true },
      ]},
  ],
}

export const calendarGuide = {
  titleKey: g('calendar', 'title'),
  subtitleKey: g('calendar', 'subtitle'),
  headerIcon: 'calendar_month',
  headerColor: 'blue',
  storageKey: 'calendar_guide_dismissed',
  steps: [
    { icon: 'event', titleKey: g('calendar','s1title'), color: 'blue', descKey: g('calendar','s1desc'), exampleKey: g('calendar','s1example'),
      visual: [
        { labelKey: v('calendar',1,1), icon: 'add', accent: 'blue' },
        { labelKey: v('calendar',1,2), icon: 'event', accent: 'primary', arrow: true },
      ]},
    { icon: 'groups', titleKey: g('calendar','s2title'), color: 'green', descKey: g('calendar','s2desc'), exampleKey: g('calendar','s2example'),
      visual: [
        { labelKey: v('calendar',2,1), icon: 'person_add', accent: 'green' },
        { labelKey: v('calendar',2,2), icon: 'videocam', accent: 'blue', arrow: true },
        { labelKey: v('calendar',2,3), icon: 'event_available', accent: 'green', arrow: true },
      ]},
    { icon: 'notifications', titleKey: g('calendar','s3title'), color: 'amber', descKey: g('calendar','s3desc'), exampleKey: g('calendar','s3example'),
      visual: [
        { labelKey: v('calendar',3,1), icon: 'event', accent: 'blue' },
        { labelKey: v('calendar',3,2), icon: 'alarm', accent: 'amber', arrow: true },
        { labelKey: v('calendar',3,3), icon: 'notifications', accent: 'red', arrow: true },
      ]},
    { icon: 'event_repeat', titleKey: g('calendar','s4title'), color: 'purple', descKey: g('calendar','s4desc'), exampleKey: g('calendar','s4example'),
      visual: [
        { labelKey: v('calendar',4,1), icon: 'event_repeat', accent: 'purple' },
        { labelKey: v('calendar',4,2), icon: 'date_range', accent: 'surface', arrow: true },
      ]},
    { icon: 'hub', titleKey: g('calendar','s5title'), color: 'primary', descKey: g('calendar','s5desc'), exampleKey: g('calendar','s5example'),
      visual: [
        { labelKey: v('calendar',5,1), icon: 'calendar_month', accent: 'blue' },
        { labelKey: v('calendar',5,2), icon: 'view_kanban', accent: 'amber', arrow: true },
        { labelKey: v('calendar',5,3), icon: 'timer', accent: 'green', arrow: true },
      ]},
  ],
}

export const timeTrackerGuide = {
  titleKey: g('timeTracker', 'title'),
  subtitleKey: g('timeTracker', 'subtitle'),
  headerIcon: 'timer',
  headerColor: 'green',
  storageKey: 'time_tracker_guide_dismissed',
  steps: [
    { icon: 'play_circle', titleKey: g('timeTracker','s1title'), color: 'green', descKey: g('timeTracker','s1desc'), exampleKey: g('timeTracker','s1example'),
      visual: [
        { labelKey: v('timeTracker',1,1), icon: 'play_circle', accent: 'green' },
        { labelKey: v('timeTracker',1,2), icon: 'timer', accent: 'blue', arrow: true },
        { labelKey: v('timeTracker',1,3), icon: 'stop_circle', accent: 'red', arrow: true },
      ]},
    { icon: 'domain', titleKey: g('timeTracker','s2title'), color: 'blue', descKey: g('timeTracker','s2desc'), exampleKey: g('timeTracker','s2example'),
      visual: [
        { labelKey: v('timeTracker',2,1), icon: 'timer', accent: 'green' },
        { labelKey: v('timeTracker',2,2), icon: 'link', accent: 'surface', arrow: true },
        { labelKey: v('timeTracker',2,3), icon: 'domain', accent: 'blue', arrow: true },
      ]},
    { icon: 'category', titleKey: g('timeTracker','s3title'), color: 'amber', descKey: g('timeTracker','s3desc'), exampleKey: g('timeTracker','s3example'),
      visual: [
        { labelKey: v('timeTracker',3,1), icon: 'code', accent: 'purple' },
        { labelKey: v('timeTracker',3,2), icon: 'palette', accent: 'amber', arrow: true },
        { labelKey: v('timeTracker',3,3), icon: 'groups', accent: 'blue', arrow: true },
      ]},
    { icon: 'bar_chart', titleKey: g('timeTracker','s4title'), color: 'purple', descKey: g('timeTracker','s4desc'), exampleKey: g('timeTracker','s4example'),
      visual: [
        { labelKey: v('timeTracker',4,1), icon: 'bar_chart', accent: 'purple' },
        { labelKey: v('timeTracker',4,2), icon: 'calendar_month', accent: 'blue', arrow: true },
        { labelKey: v('timeTracker',4,3), icon: 'domain', accent: 'green', arrow: true },
      ]},
    { icon: 'payments', titleKey: g('timeTracker','s5title'), color: 'primary', descKey: g('timeTracker','s5desc'), exampleKey: g('timeTracker','s5example'),
      visual: [
        { labelKey: v('timeTracker',5,1), icon: 'timer', accent: 'green' },
        { labelKey: v('timeTracker',5,2), icon: 'calculate', accent: 'amber', arrow: true },
        { labelKey: v('timeTracker',5,3), icon: 'receipt', accent: 'primary', arrow: true },
      ]},
  ],
}

export const moodBoardGuide = {
  titleKey: g('moodBoard', 'title'),
  subtitleKey: g('moodBoard', 'subtitle'),
  headerIcon: 'palette',
  headerColor: 'purple',
  storageKey: 'mood_board_guide_dismissed',
  steps: [
    { icon: 'add_circle', titleKey: g('moodBoard','s1title'), color: 'purple', descKey: g('moodBoard','s1desc'), exampleKey: g('moodBoard','s1example'),
      visual: [
        { labelKey: v('moodBoard',1,1), icon: 'add', accent: 'purple' },
        { labelKey: v('moodBoard',1,2), icon: 'dashboard', accent: 'blue', arrow: true },
      ]},
    { icon: 'image', titleKey: g('moodBoard','s2title'), color: 'blue', descKey: g('moodBoard','s2desc'), exampleKey: g('moodBoard','s2example'),
      visual: [
        { labelKey: v('moodBoard',2,1), icon: 'image', accent: 'blue' },
        { labelKey: v('moodBoard',2,2), icon: 'text_fields', accent: 'surface', arrow: true },
        { labelKey: v('moodBoard',2,3), icon: 'shapes', accent: 'amber', arrow: true },
      ]},
    { icon: 'hub', titleKey: g('moodBoard','s3title'), color: 'green', descKey: g('moodBoard','s3desc'), exampleKey: g('moodBoard','s3example'),
      visual: [
        { labelKey: v('moodBoard',3,1), icon: 'widgets', accent: 'blue' },
        { labelKey: v('moodBoard',3,2), icon: 'line_end', accent: 'green', arrow: true },
        { labelKey: v('moodBoard',3,3), icon: 'widgets', accent: 'purple', arrow: true },
      ]},
    { icon: 'slideshow', titleKey: g('moodBoard','s4title'), color: 'amber', descKey: g('moodBoard','s4desc'), exampleKey: g('moodBoard','s4example'),
      visual: [
        { labelKey: v('moodBoard',4,1), icon: 'slideshow', accent: 'amber' },
        { labelKey: v('moodBoard',4,2), icon: 'arrow_forward', accent: 'surface', arrow: true },
        { labelKey: v('moodBoard',4,3), icon: 'present_to_all', accent: 'primary', arrow: true },
      ]},
    { icon: 'share', titleKey: g('moodBoard','s5title'), color: 'primary', descKey: g('moodBoard','s5desc'), exampleKey: g('moodBoard','s5example'),
      visual: [
        { labelKey: v('moodBoard',5,1), icon: 'link', accent: 'primary' },
        { labelKey: v('moodBoard',5,2), icon: 'group', accent: 'green', arrow: true },
      ]},
  ],
}

export const teamGuide = {
  titleKey: g('team', 'title'),
  subtitleKey: g('team', 'subtitle'),
  headerIcon: 'group',
  headerColor: 'blue',
  storageKey: 'team_guide_dismissed',
  steps: [
    { icon: 'person_add', titleKey: g('team','s1title'), color: 'blue', descKey: g('team','s1desc'), exampleKey: g('team','s1example'),
      visual: [
        { labelKey: v('team',1,1), icon: 'mail', accent: 'blue' },
        { labelKey: v('team',1,2), icon: 'person_add', accent: 'green', arrow: true },
      ]},
    { icon: 'admin_panel_settings', titleKey: g('team','s2title'), color: 'amber', descKey: g('team','s2desc'), exampleKey: g('team','s2example'),
      visual: [
        { labelKey: v('team',2,1), icon: 'shield_person', accent: 'red' },
        { labelKey: v('team',2,2), icon: 'edit', accent: 'amber', arrow: true },
        { labelKey: v('team',2,3), icon: 'visibility', accent: 'surface', arrow: true },
      ]},
    { icon: 'corporate_fare', titleKey: g('team','s3title'), color: 'purple', descKey: g('team','s3desc'), exampleKey: g('team','s3example'),
      visual: [
        { labelKey: v('team',3,1), icon: 'corporate_fare', accent: 'purple' },
        { labelKey: v('team',3,2), icon: 'group', accent: 'blue', arrow: true },
      ]},
    { icon: 'folder_shared', titleKey: g('team','s4title'), color: 'green', descKey: g('team','s4desc'), exampleKey: g('team','s4example'),
      visual: [
        { labelKey: v('team',4,1), icon: 'person', accent: 'blue' },
        { labelKey: v('team',4,2), icon: 'view_kanban', accent: 'amber', arrow: true },
        { labelKey: v('team',4,3), icon: 'chat', accent: 'green', arrow: true },
      ]},
    { icon: 'monitoring', titleKey: g('team','s5title'), color: 'primary', descKey: g('team','s5desc'), exampleKey: g('team','s5example'),
      visual: [
        { labelKey: v('team',5,1), icon: 'timeline', accent: 'primary' },
        { labelKey: v('team',5,2), icon: 'security', accent: 'amber', arrow: true },
      ]},
  ],
}

export const chatGuide = {
  titleKey: g('chat', 'title'),
  subtitleKey: g('chat', 'subtitle'),
  headerIcon: 'chat',
  headerColor: 'green',
  storageKey: 'chat_guide_dismissed',
  steps: [
    { icon: 'chat_bubble', titleKey: g('chat','s1title'), color: 'blue', descKey: g('chat','s1desc'), exampleKey: g('chat','s1example'),
      visual: [
        { labelKey: v('chat',1,1), icon: 'person', accent: 'blue' },
        { labelKey: v('chat',1,2), icon: 'chat_bubble', accent: 'green', arrow: true },
        { labelKey: v('chat',1,3), icon: 'person', accent: 'blue', arrow: true },
      ]},
    { icon: 'forum', titleKey: g('chat','s2title'), color: 'purple', descKey: g('chat','s2desc'), exampleKey: g('chat','s2example'),
      visual: [
        { labelKey: v('chat',2,1), icon: 'tag', accent: 'purple' },
        { labelKey: v('chat',2,2), icon: 'group', accent: 'blue', arrow: true },
        { labelKey: v('chat',2,3), icon: 'forum', accent: 'green', arrow: true },
      ]},
    { icon: 'attach_file', titleKey: g('chat','s3title'), color: 'amber', descKey: g('chat','s3desc'), exampleKey: g('chat','s3example'),
      visual: [
        { labelKey: v('chat',3,1), icon: 'image', accent: 'blue' },
        { labelKey: v('chat',3,2), icon: 'description', accent: 'amber', arrow: true },
        { labelKey: v('chat',3,3), icon: 'cloud', accent: 'green', arrow: true },
      ]},
    { icon: 'call', titleKey: g('chat','s4title'), color: 'green', descKey: g('chat','s4desc'), exampleKey: g('chat','s4example'),
      visual: [
        { labelKey: v('chat',4,1), icon: 'mic', accent: 'green' },
        { labelKey: v('chat',4,2), icon: 'videocam', accent: 'blue', arrow: true },
        { labelKey: v('chat',4,3), icon: 'screen_share', accent: 'purple', arrow: true },
      ]},
    { icon: 'hub', titleKey: g('chat','s5title'), color: 'primary', descKey: g('chat','s5desc'), exampleKey: g('chat','s5example'),
      visual: [
        { labelKey: v('chat',5,1), icon: 'chat', accent: 'green' },
        { labelKey: v('chat',5,2), icon: 'view_kanban', accent: 'amber', arrow: true },
        { labelKey: v('chat',5,3), icon: 'notifications', accent: 'red', arrow: true },
      ]},
  ],
}

export const automationHubGuide = {
  titleKey: g('automationHub', 'title'),
  subtitleKey: g('automationHub', 'subtitle'),
  headerIcon: 'smart_toy',
  headerColor: 'purple',
  storageKey: 'automation_hub_guide_dismissed',
  steps: [
    { icon: 'account_tree', titleKey: g('automationHub','s1title'), color: 'purple', descKey: g('automationHub','s1desc'), exampleKey: g('automationHub','s1example'),
      visual: [
        { labelKey: v('automationHub',1,1), icon: 'bolt', accent: 'amber' },
        { labelKey: v('automationHub',1,2), icon: 'account_tree', accent: 'purple', arrow: true },
        { labelKey: v('automationHub',1,3), icon: 'check_circle', accent: 'green', arrow: true },
      ]},
    { icon: 'sensors', titleKey: g('automationHub','s2title'), color: 'amber', descKey: g('automationHub','s2desc'), exampleKey: g('automationHub','s2example'),
      visual: [
        { labelKey: v('automationHub',2,1), icon: 'mail', accent: 'blue' },
        { labelKey: v('automationHub',2,2), icon: 'schedule', accent: 'amber', arrow: true },
        { labelKey: v('automationHub',2,3), icon: 'webhook', accent: 'purple', arrow: true },
      ]},
    { icon: 'play_circle', titleKey: g('automationHub','s3title'), color: 'green', descKey: g('automationHub','s3desc'), exampleKey: g('automationHub','s3example'),
      visual: [
        { labelKey: v('automationHub',3,1), icon: 'send', accent: 'blue' },
        { labelKey: v('automationHub',3,2), icon: 'add_task', accent: 'green', arrow: true },
        { labelKey: v('automationHub',3,3), icon: 'notifications', accent: 'red', arrow: true },
      ]},
    { icon: 'fork_right', titleKey: g('automationHub','s4title'), color: 'blue', descKey: g('automationHub','s4desc'), exampleKey: g('automationHub','s4example'),
      visual: [
        { labelKey: v('automationHub',4,1), icon: 'filter_alt', accent: 'blue' },
        { labelKey: v('automationHub',4,2), icon: 'fork_right', accent: 'amber', arrow: true },
        { labelKey: v('automationHub',4,3), icon: 'route', accent: 'purple', arrow: true },
      ]},
    { icon: 'monitoring', titleKey: g('automationHub','s5title'), color: 'primary', descKey: g('automationHub','s5desc'), exampleKey: g('automationHub','s5example'),
      visual: [
        { labelKey: v('automationHub',5,1), icon: 'list_alt', accent: 'surface' },
        { labelKey: v('automationHub',5,2), icon: 'check_circle', accent: 'green', arrow: true },
        { labelKey: v('automationHub',5,3), icon: 'error', accent: 'red', arrow: true },
      ]},
  ],
}

export const mailingListsGuide = {
  titleKey: g('mailingLists', 'title'),
  subtitleKey: g('mailingLists', 'subtitle'),
  headerIcon: 'contact_mail',
  headerColor: 'blue',
  storageKey: 'mailing_lists_guide_dismissed',
  steps: [
    { icon: 'playlist_add', titleKey: g('mailingLists','s1title'), color: 'blue', descKey: g('mailingLists','s1desc'), exampleKey: g('mailingLists','s1example'),
      visual: [
        { labelKey: v('mailingLists',1,1), icon: 'add', accent: 'blue' },
        { labelKey: v('mailingLists',1,2), icon: 'list', accent: 'primary', arrow: true },
      ]},
    { icon: 'person_add', titleKey: g('mailingLists','s2title'), color: 'green', descKey: g('mailingLists','s2desc'), exampleKey: g('mailingLists','s2example'),
      visual: [
        { labelKey: v('mailingLists',2,1), icon: 'upload', accent: 'amber' },
        { labelKey: v('mailingLists',2,2), icon: 'person_add', accent: 'green', arrow: true },
        { labelKey: v('mailingLists',2,3), icon: 'group', accent: 'blue', arrow: true },
      ]},
    { icon: 'filter_alt', titleKey: g('mailingLists','s3title'), color: 'purple', descKey: g('mailingLists','s3desc'), exampleKey: g('mailingLists','s3example'),
      visual: [
        { labelKey: v('mailingLists',3,1), icon: 'group', accent: 'blue' },
        { labelKey: v('mailingLists',3,2), icon: 'filter_alt', accent: 'purple', arrow: true },
        { labelKey: v('mailingLists',3,3), icon: 'people', accent: 'green', arrow: true },
      ]},
    { icon: 'manage_accounts', titleKey: g('mailingLists','s4title'), color: 'amber', descKey: g('mailingLists','s4desc'), exampleKey: g('mailingLists','s4example'),
      visual: [
        { labelKey: v('mailingLists',4,1), icon: 'check_circle', accent: 'green' },
        { labelKey: v('mailingLists',4,2), icon: 'unsubscribe', accent: 'red', arrow: true },
        { labelKey: v('mailingLists',4,3), icon: 'report', accent: 'amber', arrow: true },
      ]},
    { icon: 'campaign', titleKey: g('mailingLists','s5title'), color: 'primary', descKey: g('mailingLists','s5desc'), exampleKey: g('mailingLists','s5example'),
      visual: [
        { labelKey: v('mailingLists',5,1), icon: 'list', accent: 'blue' },
        { labelKey: v('mailingLists',5,2), icon: 'arrow_forward', accent: 'surface', arrow: true },
        { labelKey: v('mailingLists',5,3), icon: 'campaign', accent: 'primary', arrow: true },
      ]},
  ],
}

export const campaignsGuide = {
  titleKey: g('campaigns', 'title'),
  subtitleKey: g('campaigns', 'subtitle'),
  headerIcon: 'campaign',
  headerColor: 'primary',
  storageKey: 'campaigns_guide_dismissed',
  steps: [
    { icon: 'add_circle', titleKey: g('campaigns','s1title'), color: 'primary', descKey: g('campaigns','s1desc'), exampleKey: g('campaigns','s1example'),
      visual: [
        { labelKey: v('campaigns',1,1), icon: 'add', accent: 'primary' },
        { labelKey: v('campaigns',1,2), icon: 'draft', accent: 'surface', arrow: true },
      ]},
    { icon: 'edit', titleKey: g('campaigns','s2title'), color: 'blue', descKey: g('campaigns','s2desc'), exampleKey: g('campaigns','s2example'),
      visual: [
        { labelKey: v('campaigns',2,1), icon: 'web', accent: 'blue' },
        { labelKey: v('campaigns',2,2), icon: 'edit', accent: 'amber', arrow: true },
        { labelKey: v('campaigns',2,3), icon: 'preview', accent: 'green', arrow: true },
      ]},
    { icon: 'group', titleKey: g('campaigns','s3title'), color: 'green', descKey: g('campaigns','s3desc'), exampleKey: g('campaigns','s3example'),
      visual: [
        { labelKey: v('campaigns',3,1), icon: 'list', accent: 'blue' },
        { labelKey: v('campaigns',3,2), icon: 'filter_alt', accent: 'purple', arrow: true },
        { labelKey: v('campaigns',3,3), icon: 'group', accent: 'green', arrow: true },
      ]},
    { icon: 'send', titleKey: g('campaigns','s4title'), color: 'amber', descKey: g('campaigns','s4desc'), exampleKey: g('campaigns','s4example'),
      visual: [
        { labelKey: v('campaigns',4,1), icon: 'send', accent: 'primary' },
        { labelKey: v('campaigns',4,2), icon: 'schedule', accent: 'amber', arrow: true },
      ]},
    { icon: 'analytics', titleKey: g('campaigns','s5title'), color: 'purple', descKey: g('campaigns','s5desc'), exampleKey: g('campaigns','s5example'),
      visual: [
        { labelKey: v('campaigns',5,1), icon: 'visibility', accent: 'blue' },
        { labelKey: v('campaigns',5,2), icon: 'ads_click', accent: 'green', arrow: true },
        { labelKey: v('campaigns',5,3), icon: 'trending_up', accent: 'purple', arrow: true },
      ]},
  ],
}

export const crmPipelineGuide = {
  titleKey: g('crmPipeline', 'title'),
  subtitleKey: g('crmPipeline', 'subtitle'),
  headerIcon: 'filter_alt',
  headerColor: 'blue',
  storageKey: 'crm_pipeline_guide_dismissed',
  steps: [
    { icon: 'view_column', titleKey: g('crmPipeline','s1title'), color: 'blue', descKey: g('crmPipeline','s1desc'), exampleKey: g('crmPipeline','s1example'),
      visual: [
        { labelKey: v('crmPipeline',1,1), icon: 'add', accent: 'blue' },
        { labelKey: v('crmPipeline',1,2), icon: 'view_column', accent: 'primary', arrow: true },
      ]},
    { icon: 'handshake', titleKey: g('crmPipeline','s2title'), color: 'green', descKey: g('crmPipeline','s2desc'), exampleKey: g('crmPipeline','s2example'),
      visual: [
        { labelKey: v('crmPipeline',2,1), icon: 'handshake', accent: 'green' },
        { labelKey: v('crmPipeline',2,2), icon: 'person', accent: 'blue', arrow: true },
        { labelKey: v('crmPipeline',2,3), icon: 'payments', accent: 'amber', arrow: true },
      ]},
    { icon: 'swap_horiz', titleKey: g('crmPipeline','s3title'), color: 'amber', descKey: g('crmPipeline','s3desc'), exampleKey: g('crmPipeline','s3example'),
      visual: [
        { labelKey: v('crmPipeline',3,1), icon: 'start', accent: 'surface' },
        { labelKey: v('crmPipeline',3,2), icon: 'handshake', accent: 'amber', arrow: true },
        { labelKey: v('crmPipeline',3,3), icon: 'emoji_events', accent: 'green', arrow: true },
      ]},
    { icon: 'trending_up', titleKey: g('crmPipeline','s4title'), color: 'purple', descKey: g('crmPipeline','s4desc'), exampleKey: g('crmPipeline','s4example'),
      visual: [
        { labelKey: v('crmPipeline',4,1), icon: 'bar_chart', accent: 'purple' },
        { labelKey: v('crmPipeline',4,2), icon: 'payments', accent: 'green', arrow: true },
      ]},
    { icon: 'flag', titleKey: g('crmPipeline','s5title'), color: 'primary', descKey: g('crmPipeline','s5desc'), exampleKey: g('crmPipeline','s5example'),
      visual: [
        { labelKey: v('crmPipeline',5,1), icon: 'emoji_events', accent: 'green' },
        { labelKey: v('crmPipeline',5,2), icon: 'close', accent: 'red', arrow: true },
        { labelKey: v('crmPipeline',5,3), icon: 'analytics', accent: 'purple', arrow: true },
      ]},
  ],
}

export const crmInvoicesGuide = {
  titleKey: g('crmInvoices', 'title'),
  subtitleKey: g('crmInvoices', 'subtitle'),
  headerIcon: 'receipt_long',
  headerColor: 'green',
  storageKey: 'crm_invoices_guide_dismissed',
  steps: [
    { icon: 'add_circle', titleKey: g('crmInvoices','s1title'), color: 'green', descKey: g('crmInvoices','s1desc'), exampleKey: g('crmInvoices','s1example'),
      visual: [
        { labelKey: v('crmInvoices',1,1), icon: 'add', accent: 'green' },
        { labelKey: v('crmInvoices',1,2), icon: 'list', accent: 'blue', arrow: true },
        { labelKey: v('crmInvoices',1,3), icon: 'calculate', accent: 'amber', arrow: true },
      ]},
    { icon: 'domain', titleKey: g('crmInvoices','s2title'), color: 'blue', descKey: g('crmInvoices','s2desc'), exampleKey: g('crmInvoices','s2example'),
      visual: [
        { labelKey: v('crmInvoices',2,1), icon: 'receipt_long', accent: 'green' },
        { labelKey: v('crmInvoices',2,2), icon: 'link', accent: 'surface', arrow: true },
        { labelKey: v('crmInvoices',2,3), icon: 'domain', accent: 'blue', arrow: true },
      ]},
    { icon: 'send', titleKey: g('crmInvoices','s3title'), color: 'primary', descKey: g('crmInvoices','s3desc'), exampleKey: g('crmInvoices','s3example'),
      visual: [
        { labelKey: v('crmInvoices',3,1), icon: 'mail', accent: 'primary' },
        { labelKey: v('crmInvoices',3,2), icon: 'picture_as_pdf', accent: 'red', arrow: true },
      ]},
    { icon: 'payments', titleKey: g('crmInvoices','s4title'), color: 'amber', descKey: g('crmInvoices','s4desc'), exampleKey: g('crmInvoices','s4example'),
      visual: [
        { labelKey: v('crmInvoices',4,1), icon: 'draft', accent: 'surface' },
        { labelKey: v('crmInvoices',4,2), icon: 'send', accent: 'blue', arrow: true },
        { labelKey: v('crmInvoices',4,3), icon: 'paid', accent: 'green', arrow: true },
      ]},
    { icon: 'account_balance', titleKey: g('crmInvoices','s5title'), color: 'purple', descKey: g('crmInvoices','s5desc'), exampleKey: g('crmInvoices','s5example'), badgeKey: g('crmInvoices','s5badge'),
      visual: [
        { labelKey: v('crmInvoices',5,1), icon: 'receipt_long', accent: 'green' },
        { labelKey: v('crmInvoices',5,2), icon: 'sync', accent: 'purple', arrow: true },
        { labelKey: v('crmInvoices',5,3), icon: 'account_balance', accent: 'primary', arrow: true },
      ]},
  ],
}

export const crmDashboardGuide = {
  titleKey: g('crmDashboard', 'title'),
  subtitleKey: g('crmDashboard', 'subtitle'),
  headerIcon: 'dashboard',
  headerColor: 'primary',
  storageKey: 'crm_dashboard_guide_dismissed',
  steps: [
    { icon: 'view_column', titleKey: g('crmDashboard','s1title'), color: 'blue', descKey: g('crmDashboard','s1desc'), exampleKey: g('crmDashboard','s1example'),
      visual: [
        { labelKey: v('crmDashboard',1,1), icon: 'filter_alt', accent: 'blue' },
        { labelKey: v('crmDashboard',1,2), icon: 'handshake', accent: 'green', arrow: true },
        { labelKey: v('crmDashboard',1,3), icon: 'payments', accent: 'amber', arrow: true },
      ]},
    { icon: 'payments', titleKey: g('crmDashboard','s2title'), color: 'green', descKey: g('crmDashboard','s2desc'), exampleKey: g('crmDashboard','s2example'),
      visual: [
        { labelKey: v('crmDashboard',2,1), icon: 'trending_up', accent: 'green' },
        { labelKey: v('crmDashboard',2,2), icon: 'emoji_events', accent: 'amber', arrow: true },
      ]},
    { icon: 'history', titleKey: g('crmDashboard','s3title'), color: 'amber', descKey: g('crmDashboard','s3desc'), exampleKey: g('crmDashboard','s3example'),
      visual: [
        { labelKey: v('crmDashboard',3,1), icon: 'timeline', accent: 'amber' },
        { labelKey: v('crmDashboard',3,2), icon: 'group', accent: 'blue', arrow: true },
      ]},
    { icon: 'bar_chart', titleKey: g('crmDashboard','s4title'), color: 'purple', descKey: g('crmDashboard','s4desc'), exampleKey: g('crmDashboard','s4example'),
      visual: [
        { labelKey: v('crmDashboard',4,1), icon: 'bar_chart', accent: 'purple' },
        { labelKey: v('crmDashboard',4,2), icon: 'show_chart', accent: 'blue', arrow: true },
      ]},
    { icon: 'bolt', titleKey: g('crmDashboard','s5title'), color: 'primary', descKey: g('crmDashboard','s5desc'), exampleKey: g('crmDashboard','s5example'),
      visual: [
        { labelKey: v('crmDashboard',5,1), icon: 'handshake', accent: 'green' },
        { labelKey: v('crmDashboard',5,2), icon: 'receipt_long', accent: 'amber', arrow: true },
        { labelKey: v('crmDashboard',5,3), icon: 'person', accent: 'blue', arrow: true },
      ]},
  ],
}

export const boardsGuide = {
  titleKey: g('boards', 'title'),
  subtitleKey: g('boards', 'subtitle'),
  headerIcon: 'view_kanban',
  headerColor: 'amber',
  storageKey: 'boards_guide_dismissed',
  steps: [
    { icon: 'dashboard_customize', titleKey: g('boards','s1title'), color: 'amber', descKey: g('boards','s1desc'), exampleKey: g('boards','s1example'),
      visual: [
        { labelKey: v('boards',1,1), icon: 'add', accent: 'amber' },
        { labelKey: v('boards',1,2), icon: 'view_kanban', accent: 'primary', arrow: true },
      ]},
    { icon: 'add_card', titleKey: g('boards','s2title'), color: 'blue', descKey: g('boards','s2desc'), exampleKey: g('boards','s2example'),
      visual: [
        { labelKey: v('boards',2,1), icon: 'assignment', accent: 'blue' },
        { labelKey: v('boards',2,2), icon: 'description', accent: 'surface', arrow: true },
        { labelKey: v('boards',2,3), icon: 'checklist', accent: 'green', arrow: true },
      ]},
    { icon: 'label', titleKey: g('boards','s3title'), color: 'green', descKey: g('boards','s3desc'), exampleKey: g('boards','s3example'),
      visual: [
        { labelKey: v('boards',3,1), icon: 'label', accent: 'green' },
        { labelKey: v('boards',3,2), icon: 'filter_alt', accent: 'purple', arrow: true },
        { labelKey: v('boards',3,3), icon: 'search', accent: 'blue', arrow: true },
      ]},
    { icon: 'drag_indicator', titleKey: g('boards','s4title'), color: 'purple', descKey: g('boards','s4desc'), exampleKey: g('boards','s4example'),
      visual: [
        { labelKey: v('boards',4,1), icon: 'view_list', accent: 'surface' },
        { labelKey: v('boards',4,2), icon: 'drag_indicator', accent: 'purple', arrow: true },
        { labelKey: v('boards',4,3), icon: 'view_list', accent: 'green', arrow: true },
      ]},
    { icon: 'group', titleKey: g('boards','s5title'), color: 'primary', descKey: g('boards','s5desc'), exampleKey: g('boards','s5example'),
      visual: [
        { labelKey: v('boards',5,1), icon: 'person_add', accent: 'blue' },
        { labelKey: v('boards',5,2), icon: 'comment', accent: 'green', arrow: true },
        { labelKey: v('boards',5,3), icon: 'attach_file', accent: 'amber', arrow: true },
      ]},
  ],
}
