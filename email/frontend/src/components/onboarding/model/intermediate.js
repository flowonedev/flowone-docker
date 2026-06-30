export const intermediateModel = {
  id: 'intermediate',
  tierIcon: 'groups',
  tierColor: 'text-violet-500',
  svgPrefix: 'ig',
  tooltipGroup: 'intermediate',
  totalSteps: 13,

  stepKeys: [
    'welcome',     // step 1 (overlay)
    'boards',      // step 2
    'chat',        // step 3
    'drive',       // step 4
    'video',       // step 5
    'huddles',     // step 6
    'moodboards',  // step 7
    'colleagues',  // step 8
    'tracking',    // step 9
    'reactions',   // step 10
    'ai',          // step 11
    'templates',   // step 12
    'summary',     // step 13 (overlay)
  ],

  nodes: [
    { id: 'boards',     icon: 'dashboard',  labelKey: 'onboarding.intermediate.steps.boards.title',     step: 2 },
    { id: 'chat',       icon: 'chat',       labelKey: 'onboarding.intermediate.steps.chat.title',       step: 3 },
    { id: 'drive',      icon: 'cloud',      labelKey: 'onboarding.intermediate.steps.drive.title',      step: 4 },
    { id: 'video',      icon: 'videocam',   labelKey: 'onboarding.intermediate.steps.video.title',      step: 5 },
    { id: 'huddles',    icon: 'headphones', labelKey: 'onboarding.intermediate.steps.huddles.title',    step: 6 },
    { id: 'moodboards', icon: 'palette',    labelKey: 'onboarding.intermediate.steps.moodboards.title', step: 7 },
    { id: 'colleagues', icon: 'badge',      labelKey: 'onboarding.intermediate.steps.colleagues.title', step: 8 },
    { id: 'tracking',   icon: 'visibility', labelKey: 'onboarding.intermediate.steps.tracking.title',   step: 9 },
    { id: 'reactions',  icon: 'thumb_up',   labelKey: 'onboarding.intermediate.steps.reactions.title',  step: 10 },
    { id: 'ai',         icon: 'smart_toy',  labelKey: 'onboarding.intermediate.steps.ai.title',         step: 11 },
    { id: 'templates',  icon: 'draft',      labelKey: 'onboarding.intermediate.steps.templates.title',  step: 12 },
  ],

  positions: {
    boards:     { x: 0.12, y: 0.15 },
    chat:       { x: 0.28, y: 0.15 },
    drive:      { x: 0.50, y: 0.15 },
    video:      { x: 0.72, y: 0.15 },
    huddles:    { x: 0.84, y: 0.38 },
    moodboards: { x: 0.12, y: 0.48 },
    colleagues: { x: 0.28, y: 0.48 },
    reactions:  { x: 0.44, y: 0.48 },
    tracking:   { x: 0.60, y: 0.48 },
    ai:         { x: 0.30, y: 0.80 },
    templates:  { x: 0.55, y: 0.80 },
  },

  edges: [
    { from: 'boards',     to: 'chat',       step: 3,  branch: 'collab' },
    { from: 'chat',       to: 'drive',      step: 4,  branch: 'collab' },
    { from: 'drive',      to: 'video',      step: 5,  branch: 'collab' },
    { from: 'video',      to: 'huddles',    step: 6,  branch: 'comm' },
    { from: 'boards',     to: 'moodboards', step: 7,  branch: 'creative' },
    { from: 'chat',       to: 'colleagues', step: 8,  branch: 'team' },
    { from: 'drive',      to: 'tracking',   step: 9,  branch: 'insight' },
    { from: 'chat',       to: 'reactions',  step: 10, branch: 'comm' },
    { from: 'colleagues', to: 'ai',         step: 11, branch: 'team' },
    { from: 'tracking',   to: 'templates',  step: 12, branch: 'insight' },
    { from: 'ai',         to: 'templates',  step: 12, branch: 'team' },
  ],

  branches: {
    collab:   { start: '#8b5cf6', end: '#a855f7' },
    comm:     { start: '#3b82f6', end: '#06b6d4' },
    creative: { start: '#ec4899', end: '#f43f5e' },
    team:     { start: '#10b981', end: '#14b8a6' },
    insight:  { start: '#f59e0b', end: '#ef4444' },
  },

  sceneNodeMap: {
    2: 'boards', 3: 'chat', 4: 'drive', 5: 'video', 6: 'huddles',
    7: 'moodboards', 8: 'colleagues', 9: 'tracking', 10: 'reactions',
    11: 'ai', 12: 'templates',
  },
  sceneRange: { first: 2, last: 12 },

  quizTopicMap: {
    q1: 'boards', q2: 'huddles', q3: 'chat', q4: 'drive',
    q5: 'video', q6: 'moodboards', q7: 'colleagues', q8: 'tracking',
    q9: 'reactions', q10: 'ai', q11: 'templates', q12: 'collaboration',
  },
}
