export const advancedModel = {
  id: 'advanced',
  tierIcon: 'business_center',
  tierColor: 'text-amber-500',
  svgPrefix: 'ag',
  tooltipGroup: 'advanced',
  totalSteps: 13,

  stepKeys: [
    'welcome',      // step 1 (overlay)
    'crmClient',    // step 2
    'portal',       // step 3
    'pipelines',    // step 4
    'automations',  // step 5
    'boardAuto',    // step 6
    'sequences',    // step 7
    'timeTracking', // step 8
    'clientReport', // step 9
    'invoice',      // step 10
    'campaigns',    // step 11
    'dashboard',    // step 12
    'summary',      // step 13 (overlay)
  ],

  nodes: [
    { id: 'crmClient',    icon: 'person',        labelKey: 'onboarding.advanced.steps.crmClient.title',    step: 2 },
    { id: 'portal',       icon: 'storefront',    labelKey: 'onboarding.advanced.steps.portal.title',       step: 3 },
    { id: 'pipelines',    icon: 'filter_alt',    labelKey: 'onboarding.advanced.steps.pipelines.title',    step: 4 },
    { id: 'automations',  icon: 'bolt',          labelKey: 'onboarding.advanced.steps.automations.title',  step: 5 },
    { id: 'boardAuto',    icon: 'smart_toy',     labelKey: 'onboarding.advanced.steps.boardAuto.title',    step: 6 },
    { id: 'sequences',    icon: 'schedule_send', labelKey: 'onboarding.advanced.steps.sequences.title',    step: 7 },
    { id: 'timeTracking', icon: 'timer',         labelKey: 'onboarding.advanced.steps.timeTracking.title', step: 8 },
    { id: 'clientReport', icon: 'assessment',    labelKey: 'onboarding.advanced.steps.clientReport.title', step: 9 },
    { id: 'invoice',      icon: 'receipt_long',  labelKey: 'onboarding.advanced.steps.invoice.title',      step: 10 },
    { id: 'campaigns',    icon: 'campaign',      labelKey: 'onboarding.advanced.steps.campaigns.title',    step: 11 },
    { id: 'dashboard',    icon: 'analytics',     labelKey: 'onboarding.advanced.steps.dashboard.title',    step: 12 },
  ],

  positions: {
    crmClient:    { x: 0.12, y: 0.12 },
    portal:       { x: 0.12, y: 0.38 },
    pipelines:    { x: 0.30, y: 0.18 },
    automations:  { x: 0.54, y: 0.18 },
    boardAuto:    { x: 0.78, y: 0.18 },
    sequences:    { x: 0.72, y: 0.48 },
    timeTracking: { x: 0.24, y: 0.55 },
    clientReport: { x: 0.42, y: 0.55 },
    invoice:      { x: 0.58, y: 0.55 },
    campaigns:    { x: 0.18, y: 0.76 },
    dashboard:    { x: 0.42, y: 0.84 },
  },

  edges: [
    { from: 'crmClient',    to: 'portal',       step: 3,  branch: 'crm' },
    { from: 'crmClient',    to: 'pipelines',    step: 4,  branch: 'sales' },
    { from: 'pipelines',    to: 'automations',  step: 5,  branch: 'sales' },
    { from: 'automations',  to: 'boardAuto',    step: 6,  branch: 'auto' },
    { from: 'automations',  to: 'sequences',    step: 7,  branch: 'auto' },
    { from: 'crmClient',    to: 'timeTracking', step: 8,  branch: 'crm' },
    { from: 'timeTracking', to: 'clientReport', step: 9,  branch: 'finance' },
    { from: 'clientReport', to: 'invoice',      step: 10, branch: 'finance' },
    { from: 'sequences',    to: 'invoice',      step: 10, branch: 'auto' },
    { from: 'crmClient',    to: 'campaigns',    step: 11, branch: 'marketing' },
    { from: 'invoice',      to: 'dashboard',    step: 12, branch: 'finance' },
    { from: 'campaigns',    to: 'dashboard',    step: 12, branch: 'marketing' },
  ],

  branches: {
    crm:       { start: '#6366f1', end: '#8b5cf6' },
    sales:     { start: '#f59e0b', end: '#ef4444' },
    auto:      { start: '#10b981', end: '#06b6d4' },
    finance:   { start: '#3b82f6', end: '#2563eb' },
    marketing: { start: '#ec4899', end: '#f43f5e' },
  },

  sceneNodeMap: {
    2: 'crmClient', 3: 'portal', 4: 'pipelines', 5: 'automations',
    6: 'boardAuto', 7: 'sequences', 8: 'timeTracking', 9: 'clientReport',
    10: 'invoice', 11: 'campaigns', 12: 'dashboard',
  },
  sceneRange: { first: 2, last: 12 },

  quizTopicMap: {
    q1: 'crmClient', q2: 'portal', q3: 'pipelines', q4: 'automations',
    q5: 'boardAuto', q6: 'sequences', q7: 'timeTracking', q8: 'clientReport',
    q9: 'invoice', q10: 'dashboard', q11: 'campaigns', q12: 'workflow',
  },
}
