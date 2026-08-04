<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  BbvLinkDialog — the declarative bulk-assignment dialog of the
  Budget-to-Programme Linker (bookkeeping-provincies-bbv-variant,
  REQ-BBL-001 / REQ-BBL-002). Lives in src/modals/ per
  hydra-gate-modal-isolation; the parent BudgetToProgrammeLinker.vue
  imports it and listens to submit / close.

  The form is built from the manifest bulk-action's `dialog.fields[]`, so
  the Target Programme dropdown and the Effective Date picker (defaulting
  to `@today`, capped at `@today`) are declared once in the manifest and
  rendered here. Each control's `data-testid` is derived from the declared
  field key.

  Validation (REQ-BBL-002) is client-side pre-flight only: a required
  field must be filled and the effective date must not be in the future.
  The authoritative rejection is OpenRegister's ProgrammaLinkGuard
  precondition on save — this dialog does not decide whether a link is
  legal, it only stops an obviously invalid submit from making the round
  trip.

  @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
-->

<template>
	<div
		class="bbv-link-dialog"
		role="dialog"
		aria-modal="true"
		aria-labelledby="bbv-link-dialog-title"
		data-testid="bbv-linker-dialog">
		<div class="bbv-link-dialog__panel">
			<header class="bbv-link-dialog__header">
				<h2 id="bbv-link-dialog-title">
					{{ dialog.title || t('shillinq', 'Link to Programme') }}
				</h2>
				<p class="bbv-link-dialog__count">
					{{ n('shillinq', '%n GL line selected', '%n GL lines selected', count) }}
				</p>
			</header>

			<section class="bbv-link-dialog__body">
				<label
					v-for="field in fields"
					:key="field.key"
					class="bbv-link-dialog__field">
					<span class="bbv-link-dialog__label">
						{{ field.label || field.key }}
						<abbr v-if="field.required" :title="t('shillinq', 'Required')">*</abbr>
					</span>
					<select
						v-if="field.type === 'select'"
						v-model="values[field.key]"
						class="bbv-link-dialog__control"
						:required="Boolean(field.required)"
						:aria-label="field.label || field.key"
						:data-testid="`bbv-linker-dialog-${field.key}`">
						<option value="">
							{{ t('shillinq', 'Choose…') }}
						</option>
						<option
							v-for="option in field.options || []"
							:key="option"
							:value="option">
							{{ option }}
						</option>
					</select>
					<input
						v-else
						v-model="values[field.key]"
						class="bbv-link-dialog__control"
						:type="field.type === 'date' ? 'date' : 'text'"
						:required="Boolean(field.required)"
						:max="resolveSentinel(field.maxDate)"
						:aria-label="field.label || field.key"
						:data-testid="`bbv-linker-dialog-${field.key}`">
				</label>

				<p
					v-if="validationError || error"
					class="bbv-link-dialog__error"
					role="alert"
					data-testid="bbv-linker-dialog-error">
					{{ validationError || error }}
				</p>
			</section>

			<footer class="bbv-link-dialog__footer">
				<button
					type="button"
					class="bbv-link-dialog__btn"
					data-testid="bbv-linker-dialog-cancel"
					:disabled="submitting"
					@click="$emit('close')">
					{{ dialog.cancelLabel || t('shillinq', 'Cancel') }}
				</button>
				<button
					type="button"
					class="bbv-link-dialog__btn bbv-link-dialog__btn--primary"
					data-testid="bbv-linker-dialog-submit"
					:disabled="submitting"
					@click="onSubmit">
					{{ dialog.submitLabel || t('shillinq', 'Link') }}
				</button>
			</footer>
		</div>
	</div>
</template>

<script>
import { resolveDefault, todayIso } from '../components/BbvProvincie/bbvProvincieData.js'

export default {
	name: 'BbvLinkDialog',

	props: {
		/** The manifest bulk-action descriptor whose `dialog` block is rendered. */
		action: {
			type: Object,
			required: true,
		},
		/** How many rows the submit will be applied to. */
		count: {
			type: Number,
			default: 0,
		},
		/** True while the parent is writing the selected rows. */
		submitting: {
			type: Boolean,
			default: false,
		},
		/** Server-side / partial-failure message surfaced by the parent. */
		error: {
			type: String,
			default: '',
		},
	},

	emits: ['submit', 'close'],

	data() {
		return {
			values: {},
			validationError: '',
		}
	},

	computed: {
		/**
		 * The declared dialog block.
		 *
		 * @return {object} The `action.dialog` entry.
		 */
		dialog() {
			return this.action?.dialog ?? {}
		},
		/**
		 * The declared dialog fields.
		 *
		 * @return {Array<object>} The field descriptors.
		 */
		fields() {
			return Array.isArray(this.dialog.fields) ? this.dialog.fields : []
		},
	},

	created() {
		this.initValues()
	},

	methods: {
		/**
		 * Seed each field with its declared default, expanding the `@today`
		 * sentinel the Effective Date field uses.
		 *
		 * @return {void}
		 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
		 */
		initValues() {
			const values = {}
			this.fields.forEach((field) => {
				values[field.key] = resolveDefault(field.default) ?? ''
			})
			this.values = values
		},
		/**
		 * Expand a manifest date sentinel (`@today`) to an ISO date so it
		 * can be bound to the input's `max` attribute.
		 *
		 * @param {string|null|undefined} value The declared sentinel or literal.
		 * @return {?string} The resolved value, or null when unset.
		 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
		 */
		resolveSentinel(value) {
			if (!value) {
				return null
			}
			return resolveDefault(value)
		},
		/**
		 * Pre-flight the declared validation rules (REQ-BBL-002) and emit
		 * the values when they pass. The dialog stays open on failure with
		 * the message inline.
		 *
		 * @return {void}
		 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
		 */
		onSubmit() {
			this.validationError = ''
			const missing = this.fields.find((field) => field.required && !this.values[field.key])
			if (missing) {
				this.validationError = this.t(
					'shillinq',
					'{field} is required.',
					{ field: missing.label || missing.key },
				)
				return
			}
			const future = this.fields.find((field) => field.type === 'date'
				&& field.maxDate === '@today'
				&& this.values[field.key] > todayIso())
			if (future) {
				this.validationError = this.t('shillinq', 'Effective date cannot be in the future.')
				return
			}
			this.$emit('submit', { ...this.values })
		},
	},
}
</script>

<style scoped>
.bbv-link-dialog {
	position: fixed;
	inset: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	background: rgba(0, 0, 0, 0.4);
	z-index: 10000;
}

.bbv-link-dialog__panel {
	background: var(--color-main-background);
	color: var(--color-main-text);
	border-radius: var(--border-radius-large, 8px);
	padding: 1rem 1.25rem;
	min-width: 22rem;
	max-width: 90vw;
	box-shadow: 0 2px 12px rgba(0, 0, 0, 0.3);
}

.bbv-link-dialog__header h2 {
	margin: 0;
}

.bbv-link-dialog__count {
	margin: 0.25rem 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.bbv-link-dialog__body {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
	margin: 1rem 0;
}

.bbv-link-dialog__field {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}

.bbv-link-dialog__label {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.bbv-link-dialog__control {
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	color: var(--color-main-text);
	border-radius: var(--border-radius);
	padding: 0.25rem 0.5rem;
}

.bbv-link-dialog__error {
	margin: 0;
	color: var(--color-error);
}

.bbv-link-dialog__footer {
	display: flex;
	justify-content: flex-end;
	gap: 0.5rem;
}

.bbv-link-dialog__btn {
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	color: var(--color-main-text);
	padding: 0.25rem 0.75rem;
	border-radius: var(--border-radius);
	cursor: pointer;
}

.bbv-link-dialog__btn--primary {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}
</style>
