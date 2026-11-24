<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 InvoiceLayoutPreview: the Preview tab on an invoice layout
 (sales-invoice-document REQ-SID-001, task 4.1).

 Shows the saved layout on a small sample page: the logo, headings and the
 table header in the primary colour, the total in the accent colour, the
 variant's spacing, the footer text and where the QR codes go. Below it, the
 contrast of both colours against white is written out; the server refuses a
 primary colour below 4.5:1 on save.

 Rendered in InvoiceLayoutDetail's sidebar tab "Preview" (sidebar widgets get
 the loaded layout as `objectData`).

 @spec openspec/changes/archive/2026-11-24-sales-invoice-document/tasks.md#task-4.1
-->

<template>
	<section class="ilp" data-testid="invoice-layout-preview">
		<div
			class="ilp__page"
			:class="'ilp__page--' + variant"
			:style="pageStyle"
			data-testid="invoice-layout-preview-page">
			<header class="ilp__header">
				<img
					v-if="logoUrl"
					class="ilp__logo"
					:src="logoUrl"
					:alt="t('shillinq', 'Logo')"
					data-testid="invoice-layout-preview-logo">
				<span v-else class="ilp__no-logo">{{ t('shillinq', 'No logo') }}</span>
				<h3 class="ilp__title">
					{{ t('shillinq', 'Invoice') }}
				</h3>
			</header>
			<table class="ilp__lines">
				<thead>
					<tr>
						<th scope="col">
							{{ t('shillinq', 'Description') }}
						</th>
						<th scope="col" class="ilp__amount">
							{{ t('shillinq', 'Amount') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td>{{ t('shillinq', 'Advice, October') }}</td>
						<td class="ilp__amount">
							1.000,00
						</td>
					</tr>
				</tbody>
			</table>
			<p class="ilp__total">
				{{ t('shillinq', 'Total') }} 1.210,00
			</p>
			<p v-if="showQr" class="ilp__qr" data-testid="invoice-layout-preview-qr">
				{{ t('shillinq', 'QR codes to pay are printed here.') }}
			</p>
			<footer v-if="footerText" class="ilp__footer">
				{{ footerText }}
			</footer>
		</div>
		<p class="ilp__variant">
			{{ t('shillinq', 'Variant: {variant}', { variant: variantLabel }) }}
		</p>
		<ul class="ilp__checks">
			<li
				v-for="check in checks"
				:key="check.id"
				:class="check.passes ? 'ilp__check--pass' : 'ilp__check--fail'"
				:role="check.passes ? null : 'alert'"
				:data-testid="'invoice-layout-contrast-' + check.id">
				{{ check.text }}
			</li>
		</ul>
	</section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { contrastVerdict } from '../../utils/colourContrast.js'

/** The schema defaults (zz-sales-invoice-document.json). */
const DEFAULT_PRIMARY = '#0F4C81'
const DEFAULT_ACCENT = '#B8461B'
const VARIANTS = ['classic', 'modern', 'compact']

export default {
	name: 'InvoiceLayoutPreview',

	props: {
		/** The loaded InvoiceLayout, supplied by the sidebar's widget binding. */
		objectData: { type: Object, default: null },
	},

	computed: {
		/**
		 * The primary colour, or the default when none is set.
		 *
		 * @return {string}
		 * @spec openspec/changes/archive/2026-11-24-sales-invoice-document/tasks.md#task-4.1
		 */
		primaryColor() {
			return this.objectData?.primaryColor || DEFAULT_PRIMARY
		},

		/**
		 * The accent colour, or the default when none is set.
		 *
		 * @return {string}
		 * @spec openspec/changes/archive/2026-11-24-sales-invoice-document/tasks.md#task-4.1
		 */
		accentColor() {
			return this.objectData?.accentColor || DEFAULT_ACCENT
		},

		/**
		 * The template variant, classic when unknown.
		 *
		 * @return {string}
		 * @spec openspec/changes/archive/2026-11-24-sales-invoice-document/tasks.md#task-4.1
		 */
		variant() {
			const variant = this.objectData?.variant
			return VARIANTS.includes(variant) ? variant : 'classic'
		},

		/**
		 * The variant's name.
		 *
		 * @return {string}
		 * @spec openspec/changes/archive/2026-11-24-sales-invoice-document/tasks.md#task-4.1
		 */
		variantLabel() {
			return {
				classic: t('shillinq', 'Classic'),
				modern: t('shillinq', 'Modern'),
				compact: t('shillinq', 'Compact'),
			}[this.variant]
		},

		/**
		 * The colours as CSS variables of the sample page.
		 *
		 * @return {object}
		 * @spec openspec/changes/archive/2026-11-24-sales-invoice-document/tasks.md#task-4.1
		 */
		pageStyle() {
			return {
				'--ilp-primary': this.primaryColor,
				'--ilp-accent': this.accentColor,
			}
		},

		/**
		 * The logo's preview URL, or '' when the layout has no logo.
		 *
		 * @return {string}
		 * @spec openspec/changes/archive/2026-11-24-sales-invoice-document/tasks.md#task-4.1
		 */
		logoUrl() {
			const fileId = String(this.objectData?.logoFileId ?? '').trim()
			if (fileId === '') {
				return ''
			}
			return generateUrl('/core/preview?fileId={fileId}&x=240&y=80&a=1', {
				fileId,
			})
		},

		/**
		 * The footer text, '' when none.
		 *
		 * @return {string}
		 * @spec openspec/changes/archive/2026-11-24-sales-invoice-document/tasks.md#task-4.1
		 */
		footerText() {
			return String(this.objectData?.footerText ?? '')
		},

		/**
		 * Whether the QR codes are printed (on unless switched off).
		 *
		 * @return {boolean}
		 * @spec openspec/changes/archive/2026-11-24-sales-invoice-document/tasks.md#task-4.1
		 */
		showQr() {
			return this.objectData?.showQr !== false
		},

		/**
		 * The contrast verdicts on both colours.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/archive/2026-11-24-sales-invoice-document/tasks.md#task-4.1
		 */
		checks() {
			return [
				{
					id: 'primary',
					...contrastVerdict(t('shillinq', 'Primary colour'), this.primaryColor),
				},
				{
					id: 'accent',
					...contrastVerdict(t('shillinq', 'Accent colour'), this.accentColor),
				},
			]
		},
	},

	methods: {
		t,
	},
}
</script>

<style scoped>
.ilp__page {
	padding: 16px;
	border: 1px solid var(--color-border);
	background: #ffffff;
	color: #222222;
}

.ilp__page--compact {
	padding: 8px;
	font-size: 0.9em;
}

.ilp__page--modern .ilp__title {
	text-transform: uppercase;
	letter-spacing: 0.05em;
}

.ilp__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.ilp__logo {
	max-width: 120px;
	max-height: 40px;
}

.ilp__title {
	margin: 0;
	color: var(--ilp-primary);
}

.ilp__lines {
	width: 100%;
	margin-top: 12px;
	border-collapse: collapse;
}

.ilp__lines th {
	background: var(--ilp-primary);
	color: #ffffff;
	text-align: start;
	padding: 4px;
}

.ilp__lines td {
	padding: 4px;
}

.ilp__amount {
	text-align: end;
}

.ilp__total {
	text-align: end;
	font-weight: bold;
	color: var(--ilp-accent);
}

.ilp__checks {
	margin: 8px 0 0;
	padding: 0;
	list-style: none;
}

.ilp__check--fail {
	color: var(--color-error-text);
	font-weight: bold;
}
</style>
