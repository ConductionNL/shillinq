<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 RecurringInvoiceProfileModal — recurring-invoicing (REQ-RIN-008, Task 15).

 The create/edit modal for a RecurringInvoiceProfile, launched from the
 Recurring Invoices index page's "New recurring profile" action (declared
 in src/manifest.d/recurring-invoicing.json) and registered as a
 kind:"modal" in src/registry.js. Modal-isolated in its own file under
 src/modals/ (hydra gate-13).

 The profile is persisted directly through the OpenRegister object API
 (ADR-022 — profiles are OR objects, no app-local CRUD controller). New
 profiles are created in status `draft`; activation happens through the
 declared lifecycle, not here. A next-invoice preview renders the
 would-be first line descriptions with the period tokens expanded so the
 operator can confirm before saving (REQ-RIN-008). Every NcSelect carries
 an inputLabel (hydra gate-12).

 @spec openspec/specs/recurring-invoicing/spec.md
-->

<template>
	<NcDialog
		v-if="open"
		:name="t('shillinq', 'Recurring invoice profile')"
		size="large"
		data-testid="recurring-profile-modal"
		@closing="onClose">
		<div class="rip">
			<div class="rip__row">
				<div class="rip__field">
					<label class="rip__label" for="rip-name">{{
						t('shillinq', 'Profile name')
					}}</label>
					<input
						id="rip-name"
						v-model="form.name"
						type="text"
						class="rip__input"
						data-testid="rip-name"
						:placeholder="t('shillinq', 'e.g. Hosting Acme')" />
				</div>
				<div class="rip__field">
					<label class="rip__label" for="rip-customer">{{
						t('shillinq', 'Customer')
					}}</label>
					<input
						id="rip-customer"
						v-model="form.customerReference"
						type="text"
						class="rip__input"
						data-testid="rip-customer"
						:placeholder="
							t('shillinq', 'Nextcloud contact reference')
						" />
				</div>
			</div>

			<div class="rip__row">
				<div class="rip__field">
					<NcSelect
						:modelValue="frequencyOption"
						:options="frequencyOptions"
						:inputLabel="t('shillinq', 'Frequency')"
						:clearable="false"
						label="display"
						trackBy="value"
						data-testid="rip-frequency"
						@update:modelValue="(o) => onSelect('frequency', o)" />
				</div>
				<div class="rip__field">
					<label class="rip__label" for="rip-interval">{{
						t('shillinq', 'Interval')
					}}</label>
					<input
						id="rip-interval"
						v-model.number="form.interval"
						type="number"
						min="1"
						class="rip__input"
						data-testid="rip-interval" />
				</div>
				<div class="rip__field">
					<label class="rip__label" for="rip-invoice-day">{{
						t('shillinq', 'Invoice day')
					}}</label>
					<input
						id="rip-invoice-day"
						v-model.number="form.invoiceDay"
						type="number"
						min="1"
						max="31"
						class="rip__input"
						data-testid="rip-invoice-day" />
				</div>
			</div>

			<div class="rip__row">
				<div class="rip__field">
					<label class="rip__label" for="rip-start-date">{{
						t('shillinq', 'Start date')
					}}</label>
					<input
						id="rip-start-date"
						v-model="form.startDate"
						type="date"
						class="rip__input"
						data-testid="rip-start-date" />
				</div>
				<div class="rip__field">
					<label class="rip__label" for="rip-payment-terms">{{
						t('shillinq', 'Payment terms (days)')
					}}</label>
					<input
						id="rip-payment-terms"
						v-model.number="form.paymentTermsDays"
						type="number"
						min="0"
						class="rip__input"
						data-testid="rip-payment-terms" />
				</div>
				<div class="rip__field">
					<NcSelect
						:modelValue="issueModeOption"
						:options="issueModeOptions"
						:inputLabel="t('shillinq', 'Issue mode')"
						:clearable="false"
						label="display"
						trackBy="value"
						data-testid="rip-issue-mode"
						@update:modelValue="(o) => onSelect('issueMode', o)" />
				</div>
			</div>

			<!-- Lines -->
			<div class="rip__lines">
				<span class="rip__label">{{ t('shillinq', 'Line items') }}</span>
				<p class="rip__hint">
					{{
						t(
							'shillinq',
							'Use {period}, {month} and {year} tokens in the description — they expand per generated period.',
						)
					}}
				</p>
				<div
					v-for="(line, idx) in form.lines"
					:key="idx"
					class="rip__line"
					data-testid="rip-line">
					<input
						v-model="line.description"
						type="text"
						class="rip__input rip__input--desc"
						:aria-label="t('shillinq', 'Line description')"
						:placeholder="
							t(
								'shillinq',
								'Description (e.g. Hosting {month} {year})',
							)
						"
						data-testid="rip-line-description" />
					<input
						v-model.number="line.quantity"
						type="number"
						min="0"
						step="0.01"
						class="rip__input rip__input--qty"
						:aria-label="t('shillinq', 'Line quantity')"
						:placeholder="t('shillinq', 'Qty')"
						data-testid="rip-line-quantity" />
					<input
						v-model.number="line.unitPrice"
						type="number"
						min="0"
						step="0.01"
						class="rip__input rip__input--price"
						:aria-label="t('shillinq', 'Line unit price')"
						:placeholder="t('shillinq', 'Unit price')"
						data-testid="rip-line-unit-price" />
					<NcSelect
						:modelValue="vatOptionFor(line)"
						:options="vatOptions"
						:inputLabel="t('shillinq', 'VAT')"
						:clearable="false"
						label="display"
						trackBy="value"
						class="rip__input--vat"
						data-testid="rip-line-vat"
						@update:modelValue="(o) => onVatSelected(line, o)" />
					<button
						type="button"
						class="rip__line-remove"
						:disabled="form.lines.length <= 1"
						:aria-label="t('shillinq', 'Remove line')"
						data-testid="rip-line-remove"
						@click="removeLine(idx)">
						×
					</button>
				</div>
				<button
					type="button"
					class="rip__add-line"
					data-testid="rip-add-line"
					@click="addLine">
					+ {{ t('shillinq', 'Add line') }}
				</button>
			</div>

			<!-- Next-invoice preview (REQ-RIN-008) -->
			<div class="rip__preview" data-testid="rip-preview">
				<span class="rip__label">{{
					t('shillinq', 'Next invoice preview')
				}}</span>
				<ul class="rip__preview-list">
					<li v-for="(desc, i) in previewDescriptions" :key="i">
						{{ desc }}
					</li>
				</ul>
				<div class="rip__preview-total">
					{{ t('shillinq', 'Per period (net)') }}:
					{{ formatEuro(perPeriodNetAmount) }}
				</div>
			</div>

			<ul v-if="errors.length" class="rip__errors" data-testid="rip-errors">
				<li v-for="(err, i) in errors" :key="i">{{ err }}</li>
			</ul>
		</div>

		<template #actions>
			<NcButton :disabled="saving" data-testid="rip-cancel" @click="onClose">
				{{ t('shillinq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="saving"
				data-testid="rip-save"
				@click="onSave">
				{{
					saving ? t('shillinq', 'Saving…') : t('shillinq', 'Save profile')
				}}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcSelect } from '@nextcloud/vue'
import {
	buildProfilePayload,
	defaultRecurringLine,
	perPeriodNet,
	validateProfile,
} from './recurringInvoiceProfile.js'

const REGISTER_SLUG = 'shillinq'
const SCHEMA_SLUG = 'RecurringInvoiceProfile'

const MONTHS = [
	'January',
	'February',
	'March',
	'April',
	'May',
	'June',
	'July',
	'August',
	'September',
	'October',
	'November',
	'December',
]

export default {
	name: 'RecurringInvoiceProfileModal',
	components: { NcDialog, NcButton, NcSelect },
	props: {
		open: {
			type: Boolean,
			default: false,
		},

		recordId: {
			type: String,
			default: '',
		},
	},

	emits: ['close', 'saved'],
	data() {
		return {
			saving: false,
			errors: [],
			frequencyOptions: [
				{ value: 'weekly', display: t('shillinq', 'Weekly') },
				{ value: 'monthly', display: t('shillinq', 'Monthly') },
				{ value: 'quarterly', display: t('shillinq', 'Quarterly') },
				{ value: 'semi-annually', display: t('shillinq', 'Semi-annually') },
				{ value: 'annually', display: t('shillinq', 'Annually') },
			],

			issueModeOptions: [
				{
					value: 'draft-for-review',
					display: t('shillinq', 'Draft for review'),
				},
				{ value: 'auto-issue', display: t('shillinq', 'Auto-issue') },
			],

			vatOptions: [
				{ value: 21, display: '21%' },
				{ value: 9, display: '9%' },
				{ value: 0, display: '0%' },
			],

			form: this.blankForm(),
		}
	},

	computed: {
		/** @spec openspec/specs/recurring-invoicing/spec.md */
		frequencyOption() {
			return (
				this.frequencyOptions.find((o) => o.value === this.form.frequency)
				|| this.frequencyOptions[1]
			)
		},

		/** @spec openspec/specs/recurring-invoicing/spec.md */
		issueModeOption() {
			return (
				this.issueModeOptions.find((o) => o.value === this.form.issueMode)
				|| this.issueModeOptions[0]
			)
		},

		/** @spec openspec/specs/recurring-invoicing/spec.md */
		perPeriodNetAmount() {
			return perPeriodNet(this.form.lines)
		},

		/** @spec openspec/specs/recurring-invoicing/spec.md */
		previewDescriptions() {
			const month = MONTHS[new Date().getMonth()]
			const year = String(new Date().getFullYear())
			return this.form.lines
				.filter((l) => (l.description || '').trim().length > 0)
				.map((l) =>
					l.description
						.replace(/\{period\}/g, `${month} ${year}`)
						.replace(/\{month\}/g, month)
						.replace(/\{year\}/g, year),
				)
		},
	},

	watch: {
		/**
		 * @param next
		 * @spec openspec/specs/recurring-invoicing/spec.md
		 */
		open(next) {
			if (next === true) {
				this.errors = []
				this.form = this.blankForm()
				if (this.recordId) {
					this.fetchProfile()
				}
			}
		},
	},

	methods: {
		t,
		/** @spec openspec/specs/recurring-invoicing/spec.md */
		blankForm() {
			return {
				name: '',
				customerReference: '',
				frequency: 'monthly',
				interval: 1,
				invoiceDay: 1,
				startDate: new Date().toISOString().slice(0, 10),
				issueMode: 'draft-for-review',
				deliveryChannel: 'email',
				paymentTermsDays: 30,
				indexationPercent: '',
				currency: 'EUR',
				lines: [defaultRecurringLine()],
			}
		},

		/**
		 * @param amount
		 * @spec openspec/specs/recurring-invoicing/spec.md
		 */
		formatEuro(amount) {
			return new Intl.NumberFormat('nl-NL', {
				style: 'currency',
				currency: 'EUR',
			}).format(Number(amount) || 0)
		},

		/**
		 * @param line
		 * @spec openspec/specs/recurring-invoicing/spec.md
		 */
		vatOptionFor(line) {
			return (
				this.vatOptions.find((o) => o.value === Number(line.vatCode))
				|| this.vatOptions[0]
			)
		},

		/**
		 * @param field
		 * @param option
		 * @spec openspec/specs/recurring-invoicing/spec.md
		 */
		onSelect(field, option) {
			this.form[field] = option ? option.value : this.form[field]
		},

		/**
		 * @param line
		 * @param option
		 * @spec openspec/specs/recurring-invoicing/spec.md
		 */
		onVatSelected(line, option) {
			line.vatCode = option ? Number(option.value) : 21
		},

		/** @spec openspec/specs/recurring-invoicing/spec.md */
		addLine() {
			this.form.lines.push(defaultRecurringLine())
		},

		/**
		 * @param idx
		 * @spec openspec/specs/recurring-invoicing/spec.md
		 */
		removeLine(idx) {
			if (this.form.lines.length <= 1) return
			this.form.lines.splice(idx, 1)
		},

		/** @spec openspec/specs/recurring-invoicing/spec.md */
		async fetchProfile() {
			try {
				const response = await axios.get(
					generateUrl(
						`/apps/openregister/api/objects/${REGISTER_SLUG}/${SCHEMA_SLUG}/${this.recordId}`,
					),
				)
				const obj = response.data?.object ?? response.data ?? {}
				this.form = {
					...this.blankForm(),
					...obj,
					lines:
						obj.lines && obj.lines.length
							? obj.lines
							: [defaultRecurringLine()],
				}
			} catch (e) {
				showError(t('shillinq', 'Failed to load recurring profile.'))
			}
		},

		/** @spec openspec/specs/recurring-invoicing/spec.md */
		onClose() {
			if (this.saving) return
			this.$emit('close')
		},

		/** @spec openspec/specs/recurring-invoicing/spec.md */
		async onSave() {
			this.errors = validateProfile(this.form)
			if (this.errors.length) return
			this.saving = true
			try {
				const payload = buildProfilePayload(this.form)
				if (this.recordId) {
					await axios.put(
						generateUrl(
							`/apps/openregister/api/objects/${REGISTER_SLUG}/${SCHEMA_SLUG}/${this.recordId}`,
						),
						payload,
					)
					showSuccess(t('shillinq', 'Recurring profile updated.'))
				} else {
					await axios.post(
						generateUrl(
							`/apps/openregister/api/objects/${REGISTER_SLUG}/${SCHEMA_SLUG}`,
						),
						payload,
					)
					showSuccess(t('shillinq', 'Recurring profile created.'))
				}
				this.$emit('saved')
				this.$emit('close')
			} catch (e) {
				const msg =
					e?.response?.data?.error
					|| e?.message
					|| t('shillinq', 'Failed to save recurring profile.')
				this.errors = [msg]
				showError(msg)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.rip {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 2px 8px;
	min-width: min(680px, 86vw);
}

.rip__row {
	display: flex;
	gap: 12px;
}

.rip__field {
	display: flex;
	flex-direction: column;
	flex: 1;
	gap: 4px;
}

.rip__label {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.rip__hint {
	margin: 2px 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.rip__input {
	box-sizing: border-box;
	width: 100%;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 6px 8px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-family: inherit;
}

.rip__lines,
.rip__preview {
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding-top: 6px;
	border-top: 1px solid var(--color-border);
}

.rip__line {
	display: flex;
	gap: 6px;
	align-items: center;
}

.rip__input--desc {
	flex: 3;
}

.rip__input--qty {
	flex: 1;
	max-width: 70px;
}

.rip__input--price {
	flex: 1;
	max-width: 110px;
}

.rip__input--vat {
	flex: 1;
	min-width: 90px;
}

.rip__line-remove {
	border: none;
	background: transparent;
	color: var(--color-error);
	font-size: 20px;
	line-height: 1;
	cursor: pointer;
	padding: 4px 8px;
}

.rip__line-remove:disabled {
	opacity: 0.3;
	cursor: not-allowed;
}

.rip__add-line {
	align-self: flex-start;
	border: 1px dashed var(--color-border);
	background: transparent;
	color: var(--color-primary-element);
	border-radius: var(--border-radius);
	padding: 4px 10px;
	cursor: pointer;
}

.rip__preview-list {
	margin: 0;
	padding-left: 18px;
}

.rip__preview-total {
	font-weight: 600;
}

.rip__errors {
	margin: 0;
	padding-left: 18px;
	color: var(--color-error);
}
</style>
