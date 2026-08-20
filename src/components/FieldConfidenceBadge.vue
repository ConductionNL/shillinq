<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 FieldConfidenceBadge — receipt-extraction-consume (REQ-RXC-002 / REQ-RXC-003).

 Renders one extracted field's confidence as a percentage PLUS a text label
 ("Needs review" / "Corrected" / "Extracted") — never colour-only (NL Design
 System / WCAG 2.1 AA, design.md). `corrected` takes priority over the
 confidence-derived label since a human-entered value is authoritative
 regardless of the original extraction confidence (REQ-RXC-004).

 @spec openspec/specs/receipt-extraction-consume/spec.md
-->

<template>
	<span class="fcb" :class="badgeClass" :data-testid="testId" :title="label">
		<span class="fcb__pct">{{ percentLabel }}</span>
		<span class="fcb__label">{{ label }}</span>
	</span>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'FieldConfidenceBadge',
	props: {
		/**
		 * Field confidence in [0..1]; null/undefined when the field was not
		 * populated by an extraction (e.g. an operator-only field).
		 */
		confidence: {
			type: Number,
			default: null,
		},

		/**
		 * Below this threshold the field is flagged "needs review" (REQ-RXC-002).
		 */
		reviewThreshold: {
			type: Number,
			default: 0.8,
		},

		/**
		 * Whether the operator has already corrected this field (REQ-RXC-004);
		 * takes priority over the confidence-derived label.
		 */
		corrected: {
			type: Boolean,
			default: false,
		},

		/** Field name, used to build a stable data-testid. */
		field: {
			type: String,
			default: '',
		},
	},

	computed: {
		hasConfidence() {
			return (
				typeof this.confidence === 'number'
				&& Number.isFinite(this.confidence)
			)
		},

		needsReview() {
			return this.hasConfidence && this.confidence < this.reviewThreshold
		},

		percentLabel() {
			if (!this.hasConfidence) {
				return '—'
			}
			return `${Math.round(this.confidence * 100)}%`
		},

		label() {
			if (this.corrected) {
				return t('shillinq', 'Corrected')
			}
			if (!this.hasConfidence) {
				return t('shillinq', 'Manual')
			}
			if (this.needsReview) {
				return t('shillinq', 'Needs review')
			}
			return t('shillinq', 'Extracted')
		},

		badgeClass() {
			if (this.corrected) {
				return 'fcb--corrected'
			}
			if (this.needsReview) {
				return 'fcb--review'
			}
			return 'fcb--ok'
		},

		testId() {
			return this.field ? `fcb-${this.field}` : 'fcb'
		},
	},
}
</script>

<style scoped>
.fcb {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 1px 8px;
	border-radius: var(--border-radius-pill, 16px);
	font-size: 0.8em;
	font-weight: 600;
	border: 1px solid var(--color-border);
	white-space: nowrap;
}

.fcb__pct {
	font-variant-numeric: tabular-nums;
}

.fcb--ok {
	color: var(--color-success-text, var(--color-success));
	border-color: var(--color-success, var(--color-border));
}

.fcb--review {
	color: var(--color-warning-text, var(--color-main-text));
	border-color: var(--color-warning, var(--color-border));
	background: var(--color-warning, transparent);
}

.fcb--corrected {
	color: var(--color-primary-element-text, var(--color-main-text));
	border-color: var(--color-primary-element, var(--color-border));
}
</style>
