<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 InvoiceLayoutPreview: how an administration's invoices will look
 (sales-invoice-document REQ-SID-001). Shows the logo, the primary and accent
 colour and the layout variant on a sample invoice head, and says whether
 the primary colour reaches 4.5:1 against white. The colours are the
 layout's own data, so they are bound inline; the panel chrome uses
 Nextcloud's CSS variables.
-->
<template>
	<div class="invoice-layout-preview" data-testid="invoice-layout-preview">
		<p v-if="loading" class="invoice-layout-preview__state">
			{{ t('shillinq', 'Loading preview…') }}
		</p>
		<p v-else-if="error" class="invoice-layout-preview__state" role="alert">
			{{ error }}
		</p>
		<template v-else>
			<div
class="invoice-layout-preview__sheet" :class="['invoice-layout-preview__sheet--' + variant]"
				:style="{ borderTopColor: accentColor }"
				:aria-label="t('shillinq', 'Invoice preview')"
				role="img">
				<img
v-if="logoUrl"
					:src="logoUrl"
					:alt="t('shillinq', 'Logo')"
					class="invoice-layout-preview__logo">
				<h3 class="invoice-layout-preview__title" :style="{ color: primaryColor }">
					{{ t('shillinq', 'Invoice 2026-0042') }}
				</h3>
				<div class="invoice-layout-preview__total" :style="{ color: primaryColor, borderTopColor: accentColor }">
					{{ t('shillinq', 'Total') }}
				</div>
				<p v-if="layout.footerText" class="invoice-layout-preview__footer">
					{{ layout.footerText }}
				</p>
			</div>
			<p
class="invoice-layout-preview__contrast" :class="[verdict.ok ? 'is-ok' : 'is-refused']"
				data-testid="invoice-layout-contrast"
				role="status">
				{{ contrastMessage }}
			</p>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { checkPrimaryColor, MIN_CONTRAST } from '../../utils/invoiceLayoutContrast.js'

export default {
	name: 'InvoiceLayoutPreview',

	props: {
		/** The InvoiceLayout's id, bound from the route by CnDetailPage. */
		objectId: {
			type: [String, Number],
			default: '',
		},
	},

	data() {
		return {
			loading: true,
			error: '',
			layout: {},
		}
	},

	computed: {
		primaryColor() {
			return this.layout.primaryColor || '#1A2A6C'
		},

		accentColor() {
			return this.layout.accentColor || '#6B7280'
		},

		variant() {
			return ['classic', 'modern', 'compact'].includes(this.layout.variant) ? this.layout.variant : 'classic'
		},

		logoUrl() {
			const id = String(this.layout.logoFileId ?? '')
			return /^\d+$/.test(id) ? generateUrl('/core/preview?fileId={id}&x=220&y=60&a=1', { id }) : ''
		},

		verdict() {
			return checkPrimaryColor(this.primaryColor)
		},

		contrastMessage() {
			if (this.verdict.ratio === null) {
				return t('shillinq', 'The primary colour is not a valid colour; use #RRGGBB.')
			}
			const ratio = this.verdict.ratio.toFixed(2)
			if (this.verdict.ok) {
				return t('shillinq', 'Contrast against white is {ratio}:1, enough for text.', { ratio })
			}
			return t('shillinq', 'Contrast against white is {ratio}:1, below {minimum}:1. Choose a darker primary colour: text printed in this one is hard to read.', { ratio, minimum: MIN_CONTRAST })
		},
	},

	watch: {
		objectId: {
			immediate: true,
			handler() {
				this.load()
			},
		},
	},

	methods: {
		t,

		async load() {
			if (!this.objectId) {
				this.loading = false
				return
			}
			this.loading = true
			this.error = ''
			try {
				const { data } = await axios.get(generateUrl('/apps/openregister/api/objects/shillinq/InvoiceLayout/{id}', { id: this.objectId }))
				this.layout = data ?? {}
			} catch {
				this.error = t('shillinq', 'The invoice layout could not be loaded.')
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.invoice-layout-preview__sheet {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-top: 4px solid;
	border-radius: var(--border-radius-large);
	padding: calc(var(--default-grid-baseline) * 4);
	max-width: 420px;
}

.invoice-layout-preview__sheet--modern {
	border-top-width: 12px;
}

.invoice-layout-preview__sheet--compact {
	padding: calc(var(--default-grid-baseline) * 2);
	font-size: var(--font-size-small, 13px);
}

.invoice-layout-preview__logo {
	max-height: 60px;
	max-width: 220px;
}

.invoice-layout-preview__total {
	border-top: 2px solid;
	margin-top: calc(var(--default-grid-baseline) * 2);
	padding-top: var(--default-grid-baseline);
	font-weight: bold;
}

.invoice-layout-preview__footer,
.invoice-layout-preview__state {
	color: var(--color-text-maxcontrast);
}

.invoice-layout-preview__contrast.is-ok {
	color: var(--color-success-text, var(--color-main-text));
}

.invoice-layout-preview__contrast.is-refused {
	color: var(--color-error-text, var(--color-error));
	font-weight: bold;
}
</style>
