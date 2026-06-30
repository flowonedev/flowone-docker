import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'

/**
 * Returns a reactive greeting (time-of-day phrase + first name) for the
 * authenticated user. The phrase key (`morning`, `afternoon`, `evening`) is
 * exposed separately so the consumer can resolve it via i18n.
 */
export function useGreeting() {
  const auth = useAuthStore()

  const firstName = computed(() => {
    const name = auth.displayName || ''
    // Strip parentheses suffixes and pick the first whitespace-delimited token.
    return name.split(/[\s(]/)[0] || ''
  })

  const periodKey = computed(() => {
    const hour = new Date().getHours()
    if (hour < 5) return 'evening'
    if (hour < 12) return 'morning'
    if (hour < 18) return 'afternoon'
    return 'evening'
  })

  return { firstName, periodKey }
}
