<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Budget BBV Mapping detail page — member 07 of the
 bookkeeping-waterschappen-bbv-variant chain (REQ-BBVW-004).

 Renders a CnDetailPage with the BudgetBBVMapping form:
   GL Account (picker) | BBV Programme (picker) | Allocation %
   Effective From       | Effective To          | Status
   Save / Delete / Cancel

 The page operates in two modes driven by the `:id` route param:
   • "new"               → create mode (POST /api/objects/{register}/{schema})
   • existing object id  → edit mode  (PUT  /api/objects/{register}/{schema}/{id})

 Inline allocation feedback (REQ-BBVW-008 surfacing): as the user edits
 the allocation %, the page queries the existing per-account total for
 (administrationId, glAccountNumber, fiscalYear) — derived from
 effectiveFrom — and warns when the projected sum would exceed 100%
 within the ±0.1% tolerance the slice-03 declarative validation declares.
 Save is blocked client-side while the projection is over, and the
 server-side declarative rule (slice 03) also rejects the write — the
 UI feedback never replaces the schema enforcement (ADR-022).

 Persistence (ADR-005): writes go straight to OpenRegister object
 endpoints carrying the register-level write RBAC + the slice-03
 validation. Delete is gated through the DeleteBudgetMappingDialog
 (modal-isolation per hydra-gate-modal-isolation).

 Registered in src/registry.js as a kind:"page" custom component so the
 manifest router dispatches `customComponent: "BudgetBBVMappingDetail"`
 — the bespoke pickers + per-account sum feedback do not fit the
 built-in `detail` page type slice 04 declared. ADR-036 / ADR-037.

 @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
-->
<template>
	<div class="bbv-budget-mapping-detail-root">
		<CnDetailPage
			data-testid="budget-bbv-mapping-detail"
			:title="pageTitle"
			:description="pageDescription"
			:loading="loading"
			:error="!!loadError"
			:errorMessage="loadError"
			icon="LinkVariant"
			:object="record"
			maxWidth="1100px"
			:sidebar="true"
			:sidebarOpen="false"
			objectType="bbv-budget-mapping"
			:objectId="recordId || ''"
			:sidebarProps="{
				register: 'shillinq',
				schema: 'BudgetBBVMapping',
				title: t('shillinq', 'Mapping audit trail'),
			}">
			<template #actions>
				<button
					type="button"
					class="bbv-mapping-detail__btn"
					data-testid="bbv-mapping-detail-cancel"
					@click="onCancel">
					{{ t('shillinq', 'Cancel') }}
				</button>
				<button
					v-if="!isCreate"
					type="button"
					class="bbv-mapping-detail__btn bbv-mapping-detail__btn--danger"
					data-testid="bbv-mapping-detail-delete"
					:disabled="saving || deleting"
					@click="openDeleteDialog">
					{{ t('shillinq', 'Delete') }}
				</button>
				<button
					type="button"
					class="bbv-mapping-detail__btn bbv-mapping-detail__btn--primary"
					data-testid="bbv-mapping-detail-save"
					:disabled="!canSave"
					@click="onSave">
					{{ saveLabel }}
				</button>
			</template>

			<template #default>
				<form
					class="bbv-mapping-detail__form"
					data-testid="bbv-mapping-detail-form"
					@submit.prevent="onSave">
					<!--
						The row, not the picker, carries `bbv-mapping-detail-gl`.
						Vue 3 attribute fallthrough merges parent attrs onto the
						child's ROOT vnode and the LATER value wins for plain
						attributes, so a `data-testid` set here OVERWROTE the
						picker's own `bbv-gl-account-picker` on the rendered
						element — the picker mounted correctly but wearing the
						parent's name, and nothing in the DOM answered to
						`bbv-gl-account-picker` at all.
					-->
					<div
						class="bbv-mapping-detail__row"
						data-testid="bbv-mapping-detail-gl">
						<GlAccountPicker
							v-model="form.glAccountNumber"
							:administrationId="form.administrationId"
							@selected="onGlAccountSelected" />
						<p
							v-if="selectedAccount"
							class="bbv-mapping-detail__hint"
							data-testid="bbv-mapping-detail-gl-hint">
							{{ glAccountSummary }}
						</p>
					</div>

					<!-- Same fallthrough hazard as the GL picker above. -->
					<div
						class="bbv-mapping-detail__row"
						data-testid="bbv-mapping-detail-programme">
						<BBVProgrammePicker
							v-model="form.programmeCode"
							:administrationId="form.administrationId"
							:fiscalYear="fiscalYearOfMapping"
							@selected="onProgrammeSelected" />
						<p
							v-if="selectedProgramme"
							class="bbv-mapping-detail__hint"
							data-testid="bbv-mapping-detail-programme-hint">
							{{ programmeSummary }}
						</p>
					</div>

					<div
						class="bbv-mapping-detail__row bbv-mapping-detail__row--inline">
						<label class="bbv-mapping-detail__field">
							<span>{{ t('shillinq', 'Allocation (%)') }}</span>
							<input
								v-model.number="form.allocationPercentage"
								type="number"
								min="0"
								max="100"
								step="0.01"
								data-testid="bbv-mapping-detail-allocation"
								class="bbv-mapping-detail__input"
								@input="scheduleAllocationCheck" />
						</label>
						<label class="bbv-mapping-detail__field">
							<span>{{ t('shillinq', 'Effective from') }}</span>
							<input
								v-model="form.effectiveFrom"
								type="date"
								data-testid="bbv-mapping-detail-effective-from"
								class="bbv-mapping-detail__input"
								@change="scheduleAllocationCheck" />
						</label>
						<label class="bbv-mapping-detail__field">
							<span>{{ t('shillinq', 'Effective to') }}</span>
							<input
								v-model="form.effectiveTo"
								type="date"
								data-testid="bbv-mapping-detail-effective-to"
								class="bbv-mapping-detail__input" />
						</label>
						<label class="bbv-mapping-detail__field">
							<span>{{ t('shillinq', 'Status') }}</span>
							<select
								v-model="form.status"
								class="bbv-mapping-detail__input"
								data-testid="bbv-mapping-detail-status">
								<option value="active">
									{{ t('shillinq', 'Active') }}
								</option>
								<option value="archived">
									{{ t('shillinq', 'Archived') }}
								</option>
							</select>
						</label>
					</div>

					<div
						v-if="allocationFeedback.message"
						class="bbv-mapping-detail__alloc"
						:class="
							allocationFeedback.severity === 'error'
								? 'bbv-mapping-detail__alloc--error'
								: 'bbv-mapping-detail__alloc--info'
						"
						data-testid="bbv-mapping-detail-alloc-feedback"
						role="status"
						aria-live="polite">
						{{ allocationFeedback.message }}
					</div>

					<p
						v-if="saveError"
						class="bbv-mapping-detail__error"
						data-testid="bbv-mapping-detail-save-error"
						role="alert">
						{{ saveError }}
					</p>
				</form>
			</template>
		</CnDetailPage>

		<DeleteBudgetMappingDialog
			:open="deleteDialogOpen"
			:mapping="record"
			:deleting="deleting"
			@cancel="closeDeleteDialog"
			@confirm="onDelete" />
	</div>
</template>

<script>
import { CnDetailPage } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import DeleteBudgetMappingDialog from '../../modals/DeleteBudgetMappingDialog.vue'
import BBVProgrammePicker from './BBVProgrammePicker.vue'
import GlAccountPicker from './GlAccountPicker.vue'

const REGISTER_SLUG = 'shillinq'
const SCHEMA_SLUG = 'BudgetBBVMapping'
// ±0.1% tolerance from slice-03 validation: server rejects > 100.1%.
const ALLOCATION_OVER_THRESHOLD = 100.1

export default {
	name: 'BudgetBBVMappingDetail',
	components: {
		CnDetailPage,
		GlAccountPicker,
		BBVProgrammePicker,
		DeleteBudgetMappingDialog,
	},

	props: {
		/**
		 * Object id from the route (`:id`). Pass "new" or leave undefined
		 * to start a fresh create form.
		 */
		id: {
			type: String,
			default: 'new',
		},

		/**
		 * Optional administration scope override. When omitted the page
		 * derives it from the loaded record (edit) or the first GL
		 * account selection (create).
		 */
		administrationId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			loading: false,
			loadError: '',
			saving: false,
			saveError: '',
			deleting: false,
			deleteDialogOpen: false,
			record: null,
			form: {
				glAccountNumber: '',
				programmeCode: '',
				allocationPercentage: 0,
				effectiveFrom: this.defaultEffectiveFrom(),
				effectiveTo: '',
				status: 'active',
				administrationId: this.administrationId || '',
			},

			selectedAccount: null,
			selectedProgramme: null,
			allocationFeedback: { message: '', severity: 'info' },
			allocationCheckTimer: null,
			existingAllocationTotal: 0,
		}
	},

	computed: {
		isCreate() {
			return !this.id || this.id === 'new'
		},

		recordId() {
			return this.isCreate ? '' : String(this.id)
		},

		pageTitle() {
			if (this.isCreate) {
				return this.t('shillinq', 'New Budget Mapping')
			}
			if (this.form.glAccountNumber && this.form.programmeCode) {
				return this.t('shillinq', 'GL {gl} → Programme {code}', {
					gl: this.form.glAccountNumber,
					code: this.form.programmeCode,
				})
			}
			return this.t('shillinq', 'Budget Mapping')
		},

		pageDescription() {
			return this.t(
				'shillinq',
				'Link a GL account to a BBV programme with an allocation share for the selected fiscal-year window.',
			)
		},

		saveLabel() {
			if (this.saving) {
				return this.t('shillinq', 'Saving…')
			}
			return this.isCreate
				? this.t('shillinq', 'Create')
				: this.t('shillinq', 'Save')
		},

		canSave() {
			if (this.saving) {
				return false
			}
			if (!this.form.glAccountNumber || !this.form.programmeCode) {
				return false
			}
			if (
				this.form.allocationPercentage === null
				|| this.form.allocationPercentage === undefined
				|| this.form.allocationPercentage === ''
				|| Number(this.form.allocationPercentage) < 0
				|| Number(this.form.allocationPercentage) > 100
			) {
				return false
			}
			if (!this.form.effectiveFrom) {
				return false
			}
			if (
				this.form.effectiveTo
				&& this.form.effectiveTo < this.form.effectiveFrom
			) {
				return false
			}
			if (this.allocationFeedback.severity === 'error') {
				return false
			}
			return true
		},

		fiscalYearOfMapping() {
			const from = this.form.effectiveFrom
			if (typeof from === 'string' && from.length >= 4) {
				const year = Number.parseInt(from.slice(0, 4), 10)
				if (Number.isFinite(year)) {
					return year
				}
			}
			return new Date().getFullYear()
		},

		glAccountSummary() {
			const a = this.selectedAccount
			if (!a) {
				return ''
			}
			const name = a.accountName || a.name || a.title || ''
			const type = a.accountType || a.type || ''
			const balanceCents = a.balance ?? a.balanceCents
			const balance =
				balanceCents !== null && balanceCents !== undefined
					? this.formatEuro(balanceCents)
					: ''
			const parts = [name, type, balance].filter(Boolean)
			return parts.join(' · ')
		},

		programmeSummary() {
			const p = this.selectedProgramme
			if (!p) {
				return ''
			}
			const parts = [p.programmeCode, p.programmeName].filter(Boolean)
			return parts.join(' · ')
		},
	},

	watch: {
		id: {
			immediate: true,
			async handler() {
				await this.loadRecord()
			},
		},
	},

	beforeUnmount() {
		if (this.allocationCheckTimer) {
			clearTimeout(this.allocationCheckTimer)
		}
	},

	methods: {
		t,
		defaultEffectiveFrom() {
			const year = new Date().getFullYear()
			return `${year}-01-01`
		},

		async loadRecord() {
			if (this.isCreate) {
				this.record = null
				this.loadError = ''
				return
			}
			this.loading = true
			this.loadError = ''
			try {
				const response = await axios.get(
					generateUrl(
						`/apps/openregister/api/objects/${REGISTER_SLUG}/${SCHEMA_SLUG}/${this.recordId}`,
					),
				)
				const body =
					response.data?.object
					?? response.data?.result
					?? response.data
					?? null
				if (!body || typeof body !== 'object') {
					throw new Error('Empty response')
				}
				this.record = body
				this.form.glAccountNumber = body.glAccountNumber ?? ''
				this.form.programmeCode = body.programmeCode ?? ''
				this.form.allocationPercentage = body.allocationPercentage ?? 0
				this.form.effectiveFrom =
					body.effectiveFrom ?? this.defaultEffectiveFrom()
				this.form.effectiveTo = body.effectiveTo ?? ''
				this.form.status = body.status ?? 'active'
				this.form.administrationId =
					body.administrationId ?? this.administrationId ?? ''
				await this.refreshAllocationProjection()
			} catch (e) {
				this.loadError =
					e?.response?.data?.error
					|| this.t('shillinq', 'Failed to load mapping.')
			} finally {
				this.loading = false
			}
		},

		onGlAccountSelected(account) {
			this.selectedAccount = account || null
			if (account?.administrationId && !this.form.administrationId) {
				this.form.administrationId = account.administrationId
			}
			this.scheduleAllocationCheck()
		},

		onProgrammeSelected(programme) {
			this.selectedProgramme = programme || null
			if (programme?.administrationId && !this.form.administrationId) {
				this.form.administrationId = programme.administrationId
			}
		},

		scheduleAllocationCheck() {
			// The 0..100 RANGE check runs IMMEDIATELY; only the cross-mapping
			// projection (which costs a network round-trip) is debounced. They
			// were both behind the 250 ms timer, so an operator typing an
			// out-of-range value saw nothing at all for a quarter second — long
			// enough that the field reads as accepting it, and long enough that
			// an assertion taken right after the keystroke sees no feedback.
			if (this.checkAllocationRange()) {
				return
			}
			if (this.allocationCheckTimer) {
				clearTimeout(this.allocationCheckTimer)
			}
			this.allocationCheckTimer = setTimeout(() => {
				this.refreshAllocationProjection()
			}, 250)
		},

		/**
		 * Enforce the REQ-BBVW-002 bound on the allocation field, synchronously.
		 *
		 * `min="0" max="100"` on a number input is CONSTRAINT VALIDATION only:
		 * the browser neither clamps the value nor blocks the keystroke, so
		 * without this an out-of-range allocation simply sat in the field. The
		 * projection below cannot cover it either — its only error branch is
		 * `projected > 100.1`, which a negative number can never reach, and on
		 * the create route it early-returns before that because no GL account is
		 * selected yet.
		 *
		 * @return {boolean} True when the value is out of range (feedback set).
		 */
		checkAllocationRange() {
			const raw = this.form.allocationPercentage
			if (raw === '' || raw === null || raw === undefined) {
				return false
			}
			const entered = Number(raw)
			if (Number.isFinite(entered) && entered >= 0 && entered <= 100) {
				return false
			}
			this.allocationFeedback = {
				severity: 'error',
				message: this.t(
					'shillinq',
					'Allocation must be between 0 % and 100 %.',
				),
			}
			return true
		},

		async refreshAllocationProjection() {
			const gl = this.form.glAccountNumber
			const fiscalYear = this.fiscalYearOfMapping
			const adminId = this.form.administrationId

			// The 0..100 bound is enforced synchronously by
			// checkAllocationRange() before this debounced projection is ever
			// scheduled; re-check here so a direct call (watcher, mount) is
			// covered too.
			if (this.checkAllocationRange()) {
				return
			}

			if (!gl || !fiscalYear) {
				this.allocationFeedback = { message: '', severity: 'info' }
				this.existingAllocationTotal = 0
				return
			}
			try {
				const params = {
					glAccountNumber: gl,
					status: 'active',
				}
				if (adminId) {
					params.administrationId = adminId
				}
				const response = await axios.get(
					generateUrl(
						`/apps/openregister/api/objects/${REGISTER_SLUG}/${SCHEMA_SLUG}`,
					),
					{ params },
				)
				const rows =
					response.data?.results
					?? response.data?.objects
					?? response.data
					?? []
				const others = Array.isArray(rows) ? rows : []
				const sumOthers = others.reduce((acc, row) => {
					if (!row || typeof row !== 'object') {
						return acc
					}
					if (
						!this.isCreate
						&& this.recordId
						&& String(row.id) === this.recordId
					) {
						return acc
					}
					if (!this.overlapsFiscalYear(row, fiscalYear)) {
						return acc
					}
					const v = Number(row.allocationPercentage ?? 0)
					return acc + (Number.isFinite(v) ? v : 0)
				}, 0)
				this.existingAllocationTotal = sumOthers
				const current = Number(this.form.allocationPercentage ?? 0)
				const projected =
					sumOthers + (Number.isFinite(current) ? current : 0)
				if (projected > ALLOCATION_OVER_THRESHOLD) {
					const over = (projected - 100).toFixed(2)
					this.allocationFeedback = {
						severity: 'error',
						message: this.t(
							'shillinq',
							'GL {gl} total would be {pct} % — {over} % over 100 %. Reduce the allocation before saving.',
							{
								gl,
								pct: projected.toFixed(2),
								over,
							},
						),
					}
				} else {
					const remaining = Math.max(0, 100 - sumOthers)
					this.allocationFeedback = {
						severity: 'info',
						message: this.t(
							'shillinq',
							'GL {gl} total: {sum} % — you can add up to {remaining} %.',
							{
								gl,
								sum: sumOthers.toFixed(2),
								remaining: remaining.toFixed(2),
							},
						),
					}
				}
			} catch (e) {
				// Non-blocking — server-side schema still enforces (ADR-022).
				this.allocationFeedback = { message: '', severity: 'info' }
			}
		},

		overlapsFiscalYear(row, fiscalYear) {
			const yearStart = `${fiscalYear}-01-01`
			const yearEnd = `${fiscalYear}-12-31`
			const from = row.effectiveFrom || ''
			const to = row.effectiveTo || ''
			if (from && from > yearEnd) {
				return false
			}
			if (to && to < yearStart) {
				return false
			}
			return true
		},

		buildPayload() {
			const payload = {
				glAccountNumber: this.form.glAccountNumber,
				programmeCode: this.form.programmeCode,
				allocationPercentage: Number(this.form.allocationPercentage),
				effectiveFrom: this.form.effectiveFrom,
				status: this.form.status || 'active',
			}
			if (this.form.effectiveTo) {
				payload.effectiveTo = this.form.effectiveTo
			}
			if (this.form.administrationId) {
				payload.administrationId = this.form.administrationId
			}
			return payload
		},

		async onSave() {
			if (!this.canSave) {
				return
			}
			this.saving = true
			this.saveError = ''
			const payload = this.buildPayload()
			try {
				if (this.isCreate) {
					await axios.post(
						generateUrl(
							`/apps/openregister/api/objects/${REGISTER_SLUG}/${SCHEMA_SLUG}`,
						),
						payload,
					)
					showSuccess(this.t('shillinq', 'Mapping created.'))
				} else {
					await axios.put(
						generateUrl(
							`/apps/openregister/api/objects/${REGISTER_SLUG}/${SCHEMA_SLUG}/${this.recordId}`,
						),
						payload,
					)
					showSuccess(this.t('shillinq', 'Mapping saved.'))
				}
				this.returnToIndex()
			} catch (e) {
				const responseError = e?.response?.data
				this.saveError =
					(responseError && (responseError.error || responseError.message))
					|| e?.message
					|| this.t('shillinq', 'Failed to save mapping.')
				showError(this.saveError)
			} finally {
				this.saving = false
			}
		},

		openDeleteDialog() {
			if (this.isCreate) {
				return
			}
			this.deleteDialogOpen = true
		},

		closeDeleteDialog() {
			this.deleteDialogOpen = false
		},

		async onDelete() {
			if (this.isCreate || !this.recordId) {
				this.deleteDialogOpen = false
				return
			}
			this.deleting = true
			try {
				await axios.delete(
					generateUrl(
						`/apps/openregister/api/objects/${REGISTER_SLUG}/${SCHEMA_SLUG}/${this.recordId}`,
					),
				)
				showSuccess(this.t('shillinq', 'Mapping deleted.'))
				this.deleteDialogOpen = false
				this.returnToIndex()
			} catch (e) {
				const responseError = e?.response?.data
				const message =
					(responseError && (responseError.error || responseError.message))
					|| e?.message
					|| this.t('shillinq', 'Failed to delete mapping.')
				showError(message)
			} finally {
				this.deleting = false
			}
		},

		onCancel() {
			this.returnToIndex()
		},

		returnToIndex() {
			if (this.$router) {
				try {
					this.$router.push({ name: 'BudgetBBVMappings' })
					return
				} catch (_e) {
					// fall through to emit
				}
			}
			this.$emit('navigate', { name: 'BudgetBBVMappings' })
		},

		formatEuro(cents) {
			const numeric = Number(cents)
			if (!Number.isFinite(numeric)) {
				return ''
			}
			return new Intl.NumberFormat('nl-NL', {
				style: 'currency',
				currency: 'EUR',
				maximumFractionDigits: 0,
			}).format(numeric / 100)
		},
	},
}
</script>

<style scoped>
.bbv-mapping-detail__form {
	display: flex;
	flex-direction: column;
	gap: 1rem;
	padding: 1rem 0;
}

.bbv-mapping-detail__row {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}

.bbv-mapping-detail__row--inline {
	flex-direction: row;
	flex-wrap: wrap;
	gap: 1rem;
}

.bbv-mapping-detail__field {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
	min-width: 12rem;
	flex: 1 1 12rem;
}

.bbv-mapping-detail__input {
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	color: var(--color-main-text);
	padding: 0.25rem 0.5rem;
	border-radius: var(--border-radius);
}

.bbv-mapping-detail__hint {
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.bbv-mapping-detail__alloc {
	padding: 0.5rem 0.75rem;
	border-radius: var(--border-radius);
	font-size: 0.875rem;
}

.bbv-mapping-detail__alloc--info {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}

.bbv-mapping-detail__alloc--error {
	background: var(--color-error);
	color: var(--color-primary-text);
}

.bbv-mapping-detail__error {
	background: var(--color-background-hover);
	color: var(--color-error);
	padding: 0.5rem 0.75rem;
	border-radius: var(--border-radius);
	margin: 0;
}

.bbv-mapping-detail__btn {
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	color: var(--color-main-text);
	padding: 0.25rem 0.75rem;
	border-radius: var(--border-radius);
	cursor: pointer;
	margin-left: 0.5rem;
}

.bbv-mapping-detail__btn:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.bbv-mapping-detail__btn:hover:not(:disabled) {
	background: var(--color-background-hover);
}

.bbv-mapping-detail__btn--primary {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	border-color: var(--color-primary-element);
}

.bbv-mapping-detail__btn--primary:hover:not(:disabled) {
	background: var(--color-primary-element-hover);
}

.bbv-mapping-detail__btn--danger {
	border-color: var(--color-error);
	color: var(--color-error);
}

.bbv-mapping-detail__btn--danger:hover:not(:disabled) {
	background: var(--color-error);
	color: var(--color-primary-text);
}
</style>
