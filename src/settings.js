import {
	loadTranslations,
	translatePlural as n,
	translate as t,
} from '@nextcloud/l10n'
import { createApp, h } from 'vue'
import AdminRoot from './views/settings/AdminRoot.vue'
import pinia from './pinia.js'

// Must stay first: sets __webpack_public_path__ / __webpack_nonce__ before any
// other module evaluates — see src/setPublicPath.js.
import './setPublicPath.js'

// Mount OUTSIDE the loadTranslations callback.
//
// `loadTranslations` REJECTS on a 404, and many installs (including this
// repo's dev container) serve /custom_apps/<app>/l10n/<locale>.json through an
// Apache rewrite that only allows a JS/CSS allowlist — so the fetch 404s.
// With the mount inside the callback that produced a completely blank admin
// panel and no console error. main.js already guards this the same way;
// settings.js was the one entry point that still had the scaffold's version.
// Strings fall back to their English source on a miss.
const app = createApp({
	render: () => h(AdminRoot),
})

// Vue 3 has no global `Vue.mixin` / `Vue.use` — everything is per-app.
app.mixin({ methods: { t, n } })
app.use(pinia)
app.mount('#shillinq-settings')

try {
	const result = loadTranslations('shillinq', () => {})
	if (result && typeof result.then === 'function') {
		result.then(
			() => {},
			() => {},
		)
	}
} catch {
	// no-op — English source strings are the fallback.
}
