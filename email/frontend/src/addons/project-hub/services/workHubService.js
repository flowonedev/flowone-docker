export const WORK_HUB_MODES = [
  { key: 'my-work', label: 'My Work', icon: 'task_alt', admin: false },
  { key: 'team', label: 'Team', icon: 'group', admin: true },
  { key: 'task-time', label: 'Task Time', icon: 'schedule', admin: false },
]

export const DEFAULT_MODE = 'my-work'

const LEGACY_REMAP = { people: 'team', teams: 'team', capacity: 'team' }

export function resolveMode(queryMode) {
  const remapped = LEGACY_REMAP[queryMode] || queryMode
  const valid = WORK_HUB_MODES.find(m => m.key === remapped)
  return valid ? remapped : DEFAULT_MODE
}
