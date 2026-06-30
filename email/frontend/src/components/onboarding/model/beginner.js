export const beginnerModel = {
  id: 'beginner',
  tierIcon: 'mail',
  tierColor: 'text-blue-500',
  svgPrefix: 'bg',
  tooltipGroup: 'beginner',
  totalSteps: 12,

  // 1-indexed via getStepKey(model, step): stepKeys[step - 1]
  stepKeys: [
    'welcome',   // step 1 (overlay)
    'email',     // step 2
    'compose',   // step 3
    'threads',   // step 4
    'organize',  // step 5 (labels + filters)
    'accounts',  // step 6
    'contacts',  // step 7
    'tasks',     // step 8
    'search',    // step 9
    'calendar',  // step 10
    'security',  // step 11
    'summary',   // step 12 (overlay)
  ],

  nodes: [
    { id: 'email',    icon: 'mail',           labelKey: 'onboarding.beginner.nodes.email',    step: 2 },
    { id: 'compose',  icon: 'edit_note',      labelKey: 'onboarding.beginner.nodes.compose',  step: 3 },
    { id: 'threads',  icon: 'forum',          labelKey: 'onboarding.beginner.nodes.threads',  step: 4 },
    { id: 'labels',   icon: 'label',          labelKey: 'onboarding.beginner.nodes.labels',   step: 5 },
    { id: 'filters',  icon: 'filter_alt',     labelKey: 'onboarding.beginner.nodes.filters',  step: 5 },
    { id: 'accounts', icon: 'switch_account', labelKey: 'onboarding.beginner.nodes.accounts', step: 6 },
    { id: 'contacts', icon: 'contacts',       labelKey: 'onboarding.beginner.nodes.contacts', step: 7 },
    { id: 'tasks',    icon: 'task_alt',       labelKey: 'onboarding.beginner.nodes.tasks',    step: 8 },
    { id: 'search',   icon: 'search',         labelKey: 'onboarding.beginner.nodes.search',   step: 9 },
    { id: 'calendar', icon: 'calendar_month', labelKey: 'onboarding.beginner.nodes.calendar', step: 10 },
    { id: 'security', icon: 'shield',         labelKey: 'onboarding.beginner.nodes.security', step: 11 },
  ],

  positions: {
    email:    { x: 0.12, y: 0.14 },
    compose:  { x: 0.30, y: 0.14 },
    threads:  { x: 0.52, y: 0.14 },
    labels:   { x: 0.20, y: 0.38 },
    filters:  { x: 0.42, y: 0.38 },
    accounts: { x: 0.12, y: 0.64 },
    contacts: { x: 0.30, y: 0.64 },
    tasks:    { x: 0.74, y: 0.22 },
    search:   { x: 0.74, y: 0.44 },
    calendar: { x: 0.74, y: 0.68 },
    security: { x: 0.42, y: 0.84 },
  },

  edges: [
    { from: 'email',    to: 'compose',  step: 3,  branch: 'main' },
    { from: 'compose',  to: 'threads',  step: 4,  branch: 'main' },
    { from: 'email',    to: 'labels',   step: 5,  branch: 'organize' },
    { from: 'labels',   to: 'filters',  step: 5,  branch: 'organize' },
    { from: 'email',    to: 'accounts', step: 6,  branch: 'account' },
    { from: 'accounts', to: 'contacts', step: 7,  branch: 'account' },
    { from: 'threads',  to: 'tasks',    step: 8,  branch: 'action' },
    { from: 'tasks',    to: 'search',   step: 9,  branch: 'action' },
    { from: 'search',   to: 'calendar', step: 10, branch: 'action' },
    { from: 'contacts', to: 'security', step: 11, branch: 'account' },
    { from: 'filters',  to: 'security', step: 11, branch: 'organize' },
  ],

  branches: {
    main:     { start: '#3b82f6', end: '#06b6d4' },
    organize: { start: '#8b5cf6', end: '#a855f7' },
    account:  { start: '#10b981', end: '#14b8a6' },
    action:   { start: '#f59e0b', end: '#ef4444' },
  },

  sceneNodeMap: {
    2: 'email', 3: 'compose', 4: 'threads', 5: 'labels',
    6: 'accounts', 7: 'contacts', 8: 'tasks', 9: 'search',
    10: 'calendar', 11: 'security',
  },
  sceneRange: { first: 2, last: 11 },

  quizTopicMap: {
    q1: 'inbox', q2: 'compose', q3: 'threads', q4: 'organization',
    q5: 'organization', q6: 'accounts', q7: 'contacts', q8: 'tasks',
    q9: 'search', q10: 'calendar', q11: 'security', q12: 'inbox',
  },
}
