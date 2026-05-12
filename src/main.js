import Vue from 'vue'
import { PiniaVuePlugin } from 'pinia'
import { translate as t, translatePlural as n, loadTranslations } from '@nextcloud/l10n'
import { useAppManifest } from '@conduction/nextcloud-vue'
import pinia from './pinia.js'
import router from './router/index.js'
import App from './App.vue'
import bundledManifest from './manifest.json'
import { initializeStores } from './store/store.js'

// Library CSS — must be explicit import (webpack tree-shakes side-effect imports from aliased packages)
import '@conduction/nextcloud-vue/css/index.css'

// Global (unscoped) app styles
import './assets/app.css'

Vue.mixin({ methods: { t, n } })
Vue.use(PiniaVuePlugin)

// ADR-024 — register the app manifest. shillinq still drives its UI from the
// hand-written vue-router config + App.vue/MainMenu (Tier 1 adoption); this
// call loads, sentinel-resolves and schema-validates `src/manifest.json` so
// the manifest is the single source of truth for the app's pages/menu and is
// available to library consumers (and the future app-builder backend merge).
const { validationErrors } = useAppManifest('shillinq', bundledManifest)
if (validationErrors && validationErrors.value) {
	// Non-fatal — the bundled manifest is kept on validation failure.
	// eslint-disable-next-line no-console
	console.warn('[shillinq] app manifest failed schema validation', validationErrors.value)
}

loadTranslations('shillinq', () => {
	// Create Vue instance to activate Pinia context, then initialize stores.
	const app = new Vue({
		pinia,
		router,
		render: h => h(App),
	})

	// Mount immediately so the App renders (NC32 needs #content to be taken over).
	app.$mount('#content')

	// Initialize stores after mount.
	initializeStores()
})
