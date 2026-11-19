<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'

const props = defineProps({
  modelValue: { type: Object, default: () => ({}) },
  label: { type: String, required: true },
  mode: { type: String, required: true },
  error: { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue'])
const countryOptions = ref([])
const options = ref([])
const countryChoice = ref(props.modelValue.country_code || '')
const otherCountryMode = ref(false)
const search = ref(props.modelValue.location_name || props.modelValue.custom_details || '')
const open = ref(false)
const loading = ref(false)
const fetchError = ref('')
const activeIndex = ref(-1)
const modeNotice = ref('')
const searchInput = ref(null)
const popoverStyle = ref({})
let debounceTimer
let requestController

const isOtherCountry = computed(() => otherCountryMode.value)
const hasCountry = computed(() => /^[A-Z]{2}$/.test(props.modelValue.country_code || ''))
const needsOtherCountryDetails = computed(() => isOtherCountry.value || (!!hasCountry.value && !countryOptions.value.some(item => item.code === props.modelValue.country_code)))
const locationTypes = {
  Sea: ['seaport', 'container_terminal'],
  Air: ['airport'],
  Road: ['city', 'logistics_hub', 'delivery_location'],
  Rail: ['city', 'logistics_hub', 'delivery_location'],
}
const placeholder = computed(() => !hasCountry.value ? 'Choose a country first' : props.modelValue.location_id ? 'Selected location' : 'Search ports, airports or places')
const noResultsMessage = computed(() => !hasCountry.value
  ? 'Choose a country to see matching locations.'
  : search.value.trim().length
    ? 'No matching locations. Try another search or specify a location.'
    : 'No reference locations are listed for this country and mode yet.')

function snapshot() { return { ...props.modelValue } }
function update(value) { emit('update:modelValue', value) }
function updatePopoverPosition() {
  const rect = searchInput.value?.getBoundingClientRect()
  if (!rect) return
  const maxHeight = Math.min(280, Math.max(120, window.innerHeight - rect.bottom - 12))
  const opensUp = window.innerHeight - rect.bottom < Math.min(260, window.innerHeight * 0.35) && rect.top > 250
  popoverStyle.value = {
    left: `${Math.max(8, Math.min(rect.left, window.innerWidth - rect.width - 8))}px`,
    width: `${Math.min(rect.width, window.innerWidth - 16)}px`,
    top: opensUp ? 'auto' : `${Math.min(rect.bottom + 6, window.innerHeight - maxHeight - 8)}px`,
    bottom: opensUp ? `${window.innerHeight - rect.top + 6}px` : 'auto',
    maxHeight: `${maxHeight}px`,
  }
}
function openOptions() {
  open.value = true
  updatePopoverPosition()
}
function clearSelectedLocation() {
  const value = snapshot()
  value.location_id = ''
  value.location_type = ''
  value.code_system = ''
  value.location_name = ''
  value.custom_details = ''
  update(value)
}
function selectCountry(event) {
  const next = event.target.value
  countryChoice.value = next
  otherCountryMode.value = next === '__OTHER__'
  const value = {
    country_code: next === '__OTHER__' ? '' : next,
    country_name: countryOptions.value.find(item => item.code === next)?.name || '',
    location_id: '', location_type: '', code_system: '', location_name: '', custom_details: '',
  }
  search.value = ''
  options.value = []
  modeNotice.value = ''
  update(value)
  if (next !== '__OTHER__') openOptions()
}
function updateCustomCountryCode(event) {
  const value = snapshot()
  value.country_code = event.target.value.toUpperCase().replace(/[^A-Z]/g, '').slice(0, 2)
  value.country_name = ''
  value.location_id = ''
  value.location_type = value.custom_details ? 'custom' : ''
  value.code_system = value.custom_details ? 'custom' : ''
  update(value)
}
function updateCustomCountryName(event) {
  const value = snapshot()
  value.country_name = event.target.value.slice(0, 100)
  update(value)
}
function searchLocations(event) {
  const text = event.target.value
  search.value = text
  modeNotice.value = ''
  const value = snapshot()
  if (value.location_id) {
    value.location_id = ''
    value.location_type = ''
    value.code_system = ''
    value.location_name = ''
  }
  value.custom_details = ''
  update(value)
  openOptions()
  window.clearTimeout(debounceTimer)
  debounceTimer = window.setTimeout(loadOptions, 180)
}
function chooseLocation(location) {
  const value = snapshot()
  value.country_code = location.country_code
  value.country_name = location.country_name
  value.location_id = location.id
  value.location_type = location.type
  value.code_system = location.code_system
  value.location_name = location.name
  value.custom_details = ''
  countryChoice.value = location.country_code
  search.value = location.name
  update(value)
  open.value = false
  activeIndex.value = -1
  modeNotice.value = ''
}
function chooseOther() {
  const value = snapshot()
  value.location_id = ''
  value.location_type = 'custom'
  value.code_system = 'custom'
  value.location_name = value.custom_details || ''
  update(value)
  search.value = ''
  open.value = false
  modeNotice.value = ''
}
function updateCustomDetails(event) {
  const value = snapshot()
  value.custom_details = event.target.value.slice(0, 180)
  value.location_name = value.custom_details
  value.location_id = ''
  value.location_type = value.custom_details ? 'custom' : ''
  value.code_system = value.custom_details ? 'custom' : ''
  update(value)
}
function onKeydown(event) {
  if (event.key === 'Escape') { open.value = false; return }
  if (event.key === 'ArrowDown') {
    event.preventDefault()
    if (!open.value) openOptions()
    activeIndex.value = Math.min(activeIndex.value + 1, options.value.length - 1)
  } else if (event.key === 'ArrowUp') {
    event.preventDefault()
    activeIndex.value = Math.max(0, activeIndex.value - 1)
  } else if (event.key === 'Enter' && open.value && activeIndex.value >= 0 && options.value[activeIndex.value]) {
    event.preventDefault()
    chooseLocation(options.value[activeIndex.value])
  }
}
async function loadOptions() {
  if (requestController) requestController.abort()
  requestController = new AbortController()
  loading.value = true
  fetchError.value = ''
  try {
    const query = new URLSearchParams({ mode: props.mode })
    if (hasCountry.value) query.set('country', props.modelValue.country_code)
    if (search.value.trim()) query.set('search', search.value.trim())
    const response = await fetch(`/api/sales/location-options?${query}`, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      signal: requestController.signal,
    })
    if (!response.ok) throw new Error(response.status === 403 ? 'Your role cannot access the location directory.' : `Location directory unavailable (${response.status}).`)
    const payload = await response.json()
    if (!countryOptions.value.length) countryOptions.value = payload.countries || []
    options.value = payload.data || []
    activeIndex.value = -1
    updatePopoverPosition()
  } catch (error) {
    if (error.name !== 'AbortError') fetchError.value = error.message || 'Location directory unavailable.'
  } finally {
    loading.value = false
  }
}
function onDocumentClick(event) {
  if (!event.target.closest?.(`[data-location-selector="${props.label}"]`) && !event.target.closest?.('.location-selector-popover')) open.value = false
}
function typeLabel(type) {
  return ({ seaport: 'Seaport', container_terminal: 'Container terminal', airport: 'Airport', city: 'City', logistics_hub: 'Logistics hub', delivery_location: 'Delivery location' })[type] || type
}

watch(() => props.modelValue, (value) => {
  if (value.country_code) {
    otherCountryMode.value = !countryOptions.value.some(item => item.code === value.country_code)
    countryChoice.value = otherCountryMode.value ? '__OTHER__' : value.country_code
  } else {
    countryChoice.value = otherCountryMode.value ? '__OTHER__' : ''
  }
  search.value = value.location_id ? (value.location_name || '') : ''
}, { deep: true })
watch(() => props.mode, (nextMode, previousMode) => {
  const selectedType = props.modelValue.location_type
  if (selectedType && selectedType !== 'custom' && !(locationTypes[nextMode] || []).includes(selectedType)) {
    clearSelectedLocation()
    search.value = ''
    modeNotice.value = `${previousMode} locations cleared because they are not compatible with ${nextMode} transport.`
  }
  options.value = []
  if (open.value) loadOptions()
})
watch(() => [props.modelValue.country_code, props.mode], () => {
  if (open.value) {
    window.clearTimeout(debounceTimer)
    debounceTimer = window.setTimeout(loadOptions, 0)
  }
})
onMounted(async () => {
  document.addEventListener('click', onDocumentClick)
  window.addEventListener('resize', updatePopoverPosition)
  window.addEventListener('scroll', updatePopoverPosition, true)
  await loadOptions()
  if (props.modelValue.country_code) {
    otherCountryMode.value = !countryOptions.value.some(item => item.code === props.modelValue.country_code)
    countryChoice.value = otherCountryMode.value ? '__OTHER__' : props.modelValue.country_code
  }
})
onBeforeUnmount(() => {
  window.clearTimeout(debounceTimer)
  requestController?.abort()
  document.removeEventListener('click', onDocumentClick)
  window.removeEventListener('resize', updatePopoverPosition)
  window.removeEventListener('scroll', updatePopoverPosition, true)
})
</script>

<template>
  <div class="location-selector" :data-location-selector="label">
    <span class="location-selector-label">{{ label }} <small>{{ mode === 'Sea' ? 'Sea freight' : mode === 'Air' ? 'Air freight' : `${mode} freight` }}</small></span>
    <select :value="countryChoice" :aria-label="`${label} country`" :aria-invalid="error ? 'true' : 'false'" @change="selectCountry">
      <option value="" disabled>Select country</option>
      <option v-for="country in countryOptions" :key="country.code" :value="country.code">{{ country.name }}</option>
      <option value="__OTHER__">Other country / territory</option>
    </select>
    <div v-if="needsOtherCountryDetails" class="location-selector-other-country">
      <label>ISO country code<input :value="modelValue.country_code" maxlength="2" pattern="[A-Za-z]{2}" autocomplete="country" placeholder="e.g. PH" @input="updateCustomCountryCode"></label>
      <label>Country name<input :value="modelValue.country_name" maxlength="100" autocomplete="country-name" placeholder="Country or territory" @input="updateCustomCountryName"></label>
    </div>
    <div class="location-selector-search-wrap">
      <input
        ref="searchInput"
        role="combobox"
        aria-autocomplete="list"
        :aria-expanded="open ? 'true' : 'false'"
        :aria-controls="`${label}-location-options`"
        :aria-label="`${label} port, airport or place`"
        :aria-invalid="error ? 'true' : 'false'"
        :value="search"
        :placeholder="placeholder"
        :disabled="!hasCountry"
        autocomplete="off"
        @focus="openOptions"
        @click="openOptions"
        @input="searchLocations"
        @keydown="onKeydown"
      >
      <span v-if="loading" class="location-selector-spinner" aria-hidden="true"></span>
      <button v-if="open" class="location-selector-dismiss" type="button" aria-label="Close location results" @click="open = false">×</button>
      <div v-if="open" :id="`${label}-location-options`" class="location-selector-popover" role="listbox" :style="popoverStyle">
        <p v-if="loading" class="location-selector-empty" role="status">Searching reference locations…</p>
        <p v-else-if="fetchError" class="location-selector-empty location-selector-error" role="alert">{{ fetchError }}</p>
        <template v-else>
          <button v-for="(location, index) in options" :key="`${location.id}-${location.type}`" type="button" role="option" :aria-selected="activeIndex === index ? 'true' : 'false'" :class="{ active: activeIndex === index }" @mouseenter="activeIndex = index" @click="chooseLocation(location)">
            <span><b>{{ location.name }}</b><small>{{ location.country_name }} · {{ typeLabel(location.type) }}</small></span>
            <code>{{ location.code }}</code>
          </button>
          <p v-if="!options.length" class="location-selector-empty">{{ noResultsMessage }}</p>
          <button type="button" class="location-selector-specify" role="option" aria-selected="false" @click="chooseOther">Other / Specify location</button>
        </template>
      </div>
    </div>
    <label v-if="modelValue.location_type === 'custom' || (!modelValue.location_id && modelValue.custom_details)" class="location-selector-custom">Specify location<input :value="modelValue.custom_details" maxlength="180" :aria-label="`${label} custom location`" placeholder="Enter a terminal, city or delivery point" @input="updateCustomDetails"></label>
    <p v-if="modeNotice" class="location-selector-note" role="status">{{ modeNotice }}</p>
    <p v-else-if="modelValue.location_id" class="location-selector-note">{{ typeLabel(modelValue.location_type) }} · {{ modelValue.code_system === 'iata' ? 'IATA' : 'UN/LOCODE' }} {{ modelValue.location_id?.split(':').at(-1) }}</p>
    <p v-else-if="!hasCountry" class="location-selector-note">Choose a country, then search the available locations.</p>
    <p v-if="error" class="location-selector-validation" role="alert">{{ error }}</p>
  </div>
</template>
