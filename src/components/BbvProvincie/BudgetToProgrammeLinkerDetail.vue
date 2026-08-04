<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Budget-to-Programme Linker — single GL-line edit
 (bookkeeping-provincies-bbv-variant, REQ-BBL-003).

 One general-ledger line in its programme-assignment role. The ledger
 facts (account, description, amount, side, period) are read-only; the
 two fields this screen exists for — the BBV programme and the date the
 assignment took effect — are editable. Saving writes through
 OpenRegister's object API, whose audit trail is what records the
 before/after programme (REQ-BBL-003); nothing is journalled here.

 The form is generated from the manifest `config.fields[]`, so the field
 set, its labels and the programme enum are declared once in the manifest.
 Each control's `data-testid` is derived from the declared field key.

 The `:id` route also serves the create/unknown case: when the id does not
 resolve to a stored line the form still renders its declared fields with
 an inline notice, and Save stays disabled — an edit form's field set comes
 from the schema, not from the row, but there is nothing to write back to.

 Registered in src/registry.js as a kind:"page" component so the manifest
 router can dispatch `component: "BudgetToProgrammeLinkerDetail"`.
 ADR-036 / ADR-037.

 @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
-->
<template>
	<div class="bbv-linker-detail" data-testid="bbv-linker-detail">
		<header class="bbv-linker-detail__header">
			<button
				v-if="indexRoute"
				type="button"
				class="bbv-linker-detail__back"
				data-testid="bbv-linker-detail-back"
				@click="goBack">
				{{ t('shillinq', 'Back to Budget Links') }}
			</button>
			<h2 class="bbv-linker-detail__title">
				{{ title }}
			</h2>
		</header>

		<p
			v-if="notice"
			class="bbv-linker-detail__notice"
			role="status"
			data-testid="bbv-linker-detail-notice">
			{{ notice }}
		</p>

		<form class="bbv-linker-detail__form" @submit.prevent="save">
			<label
				v-for="field in fields"
				:key="field.key"
				class="bbv-linker-detail__field">
				<span class="bbv-linker-detail__label">{{ field.label || field.key }}</span>
				<select
					v-if="field.type === 'enum'"
					v-model="values[field.key]"
					class="bbv-linker-detail__control"
					:aria-label="field.label || field.key"
					:data-testid="`bbv-linker-detail-${field.key}`">
					<option value="">
						{{ t('shillinq', 'Unmapped') }}
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
					class="bbv-linker-detail__control"
					:type="inputType(field)"
					:readonly="isReadOnly(field)"
					:aria-label="field.label || field.key"
					:data-testid="`bbv-linker-detail-${field.key}`">
			</label>

			<div class="bbv-linker-detail__actions">
				<button
					type="submit"
					class="bbv-linker-detail__save"
					data-testid="bbv-linker-detail-save"
					:disabled="!object || saving">
					{{ t('shillinq', 'Save') }}
				</button>
			</div>
		</form>

		<p
			v-if="error"
			class="bbv-linker-detail__error"
			role="alert"
			data-testid="bbv-linker-detail-error">
			{{ error }}
		</p>
	</div>
</template>

<script>
import { fetchObject, saveAssignment } from './bbvProvincieData.js'

/** Fields this screen may edit; every other declared field is ledger truth. */
const EDITABLE_FIELDS = ['programmaStructure', 'programmaAssignedAt']

export default {
	name: 'BudgetToProgrammeLinkerDetail',

	/**
	 * See BbvProvincieComplianceDashboard — keeps the manifest's `_note`
	 * documentation string (and any other undeclared config key) off the
	 * root element.
	 */
	inheritAttrs: false,

	props: {
		/** Page title, lifted out of the manifest page entry. */
		title: {
			type: String,
			default: 'GL line',
		},
		/** Register slug the GLLine schema lives in. */
		register: {
			type: String,
			default: 'shillinq',
		},
		/** Schema slug of the object being edited. */
		schema: {
			type: String,
			default: 'GLLine',
		},
		/** Declared field descriptors (`config.fields`). */
		fields: {
			type: Array,
			default: () => [],
		},
		/** Route name of the owning index page. */
		indexRoute: {
			type: String,
			default: '',
		},
		/** Object id, bound from the `:id` route param by CnPageRenderer. */
		id: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			object: null,
			values: {},
			loading: true,
			saving: false,
			error: '',
			notice: '',
		}
	},

	watch: {
		id: {
			immediate: true,
			handler() {
				this.load()
			},
		},
	},

	methods: {
		/**
		 * Load the GL line named by the route. An id that does not resolve
		 * leaves the declared form in place with a notice rather than
		 * blanking the page, and keeps Save disabled.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
		 */
		async load() {
			this.loading = true
			this.error = ''
			this.notice = ''
			this.seedValues(null)
			if (!this.id) {
				this.loading = false
				return
			}
			try {
				const object = await fetchObject(this.register, this.schema, this.id)
				if (object && Object.keys(object).length) {
					this.object = object
					this.seedValues(object)
				} else {
					this.notice = this.t('shillinq', 'This GL line could not be found.')
				}
			} catch (e) {
				this.notice = this.t('shillinq', 'This GL line could not be found.')
			} finally {
				this.loading = false
			}
		},
		/**
		 * Fill the form model from a loaded object (or blank it out).
		 *
		 * @param {?object} object The loaded GL line.
		 * @return {void}
		 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
		 */
		seedValues(object) {
			if (!object) {
				this.object = null
			}
			const values = {}
			this.fields.forEach((field) => {
				values[field.key] = object?.[field.key] ?? ''
			})
			this.values = values
		},
		/**
		 * HTML input type for a declared field.
		 *
		 * @param {object} field The manifest field descriptor.
		 * @return {string} The input type.
		 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
		 */
		inputType(field) {
			if (field.key === 'programmaAssignedAt') {
				return 'date'
			}
			return field.type === 'number' ? 'number' : 'text'
		},
		/**
		 * Whether a declared field is ledger truth rather than an editable
		 * assignment. Re-assigning a line shifts spend between BBV
		 * programmes; rewriting its amount or account would be a posting,
		 * which this screen is not.
		 *
		 * @param {object} field The manifest field descriptor.
		 * @return {boolean} True when read-only.
		 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
		 */
		isReadOnly(field) {
			return !EDITABLE_FIELDS.includes(field.key)
		},
		/**
		 * Persist the programme assignment. Only the two editable fields are
		 * sent as the patch; the rest of the row is carried forward
		 * unchanged because OpenRegister's save is PUT-semantic.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
		 */
		async save() {
			if (!this.object) {
				return
			}
			this.saving = true
			this.error = ''
			const patch = {}
			EDITABLE_FIELDS.forEach((key) => {
				if (this.values[key] !== undefined && this.values[key] !== '') {
					patch[key] = this.values[key]
				}
			})
			try {
				this.object = await saveAssignment(this.register, this.schema, this.object, patch)
				this.seedValues(this.object)
			} catch (e) {
				this.error = e?.response?.data?.error
					|| this.t('shillinq', 'Failed to save the programme assignment.')
			} finally {
				this.saving = false
			}
		},
		/**
		 * Return to the owning index page.
		 *
		 * @return {void}
		 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
		 */
		goBack() {
			if (!this.$router || !this.indexRoute) {
				return
			}
			this.$router.push({ name: this.indexRoute }).catch(() => {})
		},
	},
}
</script>

<style scoped>
.bbv-linker-detail {
	width: 100%;
	min-height: 100%;
	padding: calc(var(--default-grid-baseline, 4px) * 4);
	padding-inline-start: 56px;
	box-sizing: border-box;
}

.bbv-linker-detail__header {
	display: flex;
	align-items: center;
	gap: 1rem;
	flex-wrap: wrap;
}

.bbv-linker-detail__title {
	margin: 0;
}

.bbv-linker-detail__back,
.bbv-linker-detail__save {
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	color: var(--color-main-text);
	padding: 0.25rem 0.75rem;
	border-radius: var(--border-radius);
	cursor: pointer;
}

.bbv-linker-detail__save {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.bbv-linker-detail__save:disabled {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	cursor: not-allowed;
}

.bbv-linker-detail__notice {
	margin: 1rem 0 0;
	color: var(--color-text-maxcontrast);
}

.bbv-linker-detail__form {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr));
	gap: 1rem;
	margin-top: 1rem;
	max-width: 48rem;
}

.bbv-linker-detail__field {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}

.bbv-linker-detail__label {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.bbv-linker-detail__control {
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	color: var(--color-main-text);
	border-radius: var(--border-radius);
	padding: 0.25rem 0.5rem;
}

.bbv-linker-detail__control[readonly] {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.bbv-linker-detail__actions {
	grid-column: 1 / -1;
	display: flex;
	justify-content: flex-end;
}

.bbv-linker-detail__error {
	margin-top: 1rem;
	color: var(--color-error);
}
</style>
