/**
 * WMO weather-code -> Meteocon SVG name and human-readable label.
 * Pure, used by WeatherChip.vue + WeatherForecastPopover.vue (no Vue/DOM deps).
 */

const ICON_URLS = import.meta.glob('../assets/meteocons/*.svg', {
  eager: true,
  import: 'default',
})

function urlForName(name) {
  const match = Object.entries(ICON_URLS).find(([path]) => path.endsWith(`/${name}.svg`))
  return match ? match[1] : null
}

// Each entry: [dayVariant, nightVariant]
const WMO_TO_ICON = {
  0: ['clear-day', 'clear-night'],
  1: ['partly-cloudy-day', 'partly-cloudy-night'],
  2: ['partly-cloudy-day', 'partly-cloudy-night'],
  3: ['overcast-day', 'overcast-night'],
  45: ['fog-day', 'fog-night'],
  48: ['fog-day', 'fog-night'],
  51: ['drizzle', 'drizzle'],
  53: ['drizzle', 'drizzle'],
  55: ['drizzle', 'drizzle'],
  56: ['drizzle', 'drizzle'],
  57: ['drizzle', 'drizzle'],
  61: ['rain', 'rain'],
  63: ['rain', 'rain'],
  65: ['rain', 'rain'],
  66: ['rain', 'rain'],
  67: ['rain', 'rain'],
  71: ['snow', 'snow'],
  73: ['snow', 'snow'],
  75: ['snow', 'snow'],
  77: ['snow', 'snow'],
  80: ['rain', 'rain'],
  81: ['rain', 'rain'],
  82: ['rain', 'rain'],
  85: ['snow', 'snow'],
  86: ['snow', 'snow'],
  95: ['thunderstorms-rain', 'thunderstorms-rain'],
  96: ['thunderstorms-rain', 'thunderstorms-rain'],
  99: ['thunderstorms-rain', 'thunderstorms-rain'],
}

const WMO_TO_LABEL = {
  0: 'Clear',
  1: 'Mainly clear',
  2: 'Partly cloudy',
  3: 'Overcast',
  45: 'Fog',
  48: 'Rime fog',
  51: 'Light drizzle',
  53: 'Drizzle',
  55: 'Heavy drizzle',
  56: 'Freezing drizzle',
  57: 'Freezing drizzle',
  61: 'Light rain',
  63: 'Rain',
  65: 'Heavy rain',
  66: 'Freezing rain',
  67: 'Freezing rain',
  71: 'Light snow',
  73: 'Snow',
  75: 'Heavy snow',
  77: 'Snow grains',
  80: 'Showers',
  81: 'Heavy showers',
  82: 'Violent showers',
  85: 'Snow showers',
  86: 'Heavy snow showers',
  95: 'Thunderstorm',
  96: 'Thunderstorm with hail',
  99: 'Severe thunderstorm',
}

/**
 * Resolve a built bundle URL for the icon matching this WMO code + day/night.
 * Falls back to the not-available icon on any miss.
 */
export function iconUrlFor(code, isDay = 1) {
  if (code === null || code === undefined) {
    return urlForName('not-available')
  }
  const pair = WMO_TO_ICON[code]
  if (!pair) return urlForName('not-available')
  const name = isDay ? pair[0] : pair[1]
  return urlForName(name) || urlForName('not-available')
}

export function labelFor(code) {
  if (code === null || code === undefined) return 'Weather'
  return WMO_TO_LABEL[code] ?? 'Weather'
}
