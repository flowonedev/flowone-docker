// Module-scoped state to avoid circular imports

export let isQuitting = false

export function setQuitting(value: boolean) {
  isQuitting = value
}

