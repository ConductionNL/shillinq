<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Approval panel on a purchase order (purchasing-approval-delegation
 REQ-PAD-001 and REQ-PAD-002).

 Lists the steps of the order's newest OpenRegister approval round with role,
 state, decider and time, and "on behalf of" for a step decided by a
 colleague it was handed to. A user who may decide the pending step gets
 Approve, Reject (with the comment OpenRegister requires) and Hand to a
 colleague (with a mandate). Authority is OpenRegister's: a step is offered
 only when it is in the user's pooled or assigned inbox, and never to the
 order's requester. Submitting the order is the Goedkeuren lifecycle action
 on the page; it opens the steps.

 Used in the PurchaseOrderDetail sidebar (objectData) and in
 PurchaseOrderDetail.vue (purchaseOrder).

 @spec openspec/changes/purchasing-approval-delegation/tasks.md#task-1.2
 @spec openspec/changes/purchasing-approval-delegation/tasks.md#task-2.1
-->
<template>
	<section class="po-approval" data-testid="po-approval-panel">
		<h3>{{ t('shillinq', 'Approval') }}</h3>

		<p v-if="loading">
			{{ t('shillinq', 'Loading approval steps…') }}
		</p>
		<p v-else-if="loadError" class="po-approval__error" role="alert">
			{{ loadError }}
		</p>
		<p v-else-if="steps.length === 0" class="po-approval__empty">
			{{ t('shillinq', 'No approval round yet. Submit the order with Goedkeuren to start it.') }}
		</p>

		<ol v-else class="po-approval__steps">
			<li
				v-for="step in steps"
				:key="step.uuid"
				class="po-approval__step"
				:data-testid="`po-approval-step-${step.sequencePosition}`">
				<div class="po-approval__role">
					<strong>{{ step.sequencePosition }}.</strong>
					{{ roleLabel(step) }}
					<span :class="`po-approval__pill po-approval__pill--${stateOf(step)}`">
						{{ stateLabel(step) }}
					</span>
				</div>

				<p v-if="step.completedBy" class="po-approval__decider">
					<template v-if="step.onBehalfOf">
						{{ t('shillinq', '{decider} on behalf of {original}', { decider: step.completedBy, original: step.onBehalfOf }) }}
					</template>
					<template v-else>
						{{ step.completedBy }}
					</template>
					<span v-if="step.completedAt">, {{ formatTimestamp(step.completedAt) }}</span>
				</p>
				<p v-if="step.mandate" class="po-approval__mandate">
					{{ t('shillinq', 'Mandate: {mandate}', { mandate: step.mandate }) }}
				</p>
				<p v-if="step.comment && stateOf(step) === 'rejected'" class="po-approval__comment">
					{{ step.comment }}
				</p>
				<p v-if="!step.completedBy && step.onBehalfOf" class="po-approval__decider">
					{{ t('shillinq', 'Handed to {delegate} by {original}', { delegate: step.assignee, original: step.onBehalfOf }) }}
				</p>

				<div v-if="canDecide(step)" class="po-approval__actions">
					<NcButton
						variant="primary"
						:disabled="busy"
						data-testid="po-approval-approve"
						@click="onApprove(step)">
						{{ t('shillinq', 'Approve') }}
					</NcButton>
					<NcButton
						:disabled="busy"
						data-testid="po-approval-reject-open"
						@click="openForm('reject')">
						{{ t('shillinq', 'Reject') }}
					</NcButton>
					<NcButton
						:disabled="busy"
						data-testid="po-approval-handover-open"
						@click="openForm('handover')">
						{{ t('shillinq', 'Hand to a colleague') }}
					</NcButton>

					<form
						v-if="form === 'reject'"
						class="po-approval__form"
						@submit.prevent="onReject(step)">
						<label for="po-approval-reject-comment">{{ t('shillinq', 'Reason for rejecting') }}</label>
						<textarea
							id="po-approval-reject-comment"
							v-model="comment"
							required
							rows="3"
							data-testid="po-approval-reject-comment" />
						<NcButton type="submit" :disabled="busy || !comment.trim()" data-testid="po-approval-reject">
							{{ t('shillinq', 'Reject order') }}
						</NcButton>
					</form>

					<form
						v-if="form === 'handover'"
						class="po-approval__form"
						@submit.prevent="onHandOver(step)">
						<label for="po-approval-delegate">{{ t('shillinq', 'Colleague (user name)') }}</label>
						<input
							id="po-approval-delegate"
							v-model="delegate"
							type="text"
							autocomplete="off"
							data-testid="po-approval-delegate">
						<label for="po-approval-mandate">{{ t('shillinq', 'Mandate') }}</label>
						<input
							id="po-approval-mandate"
							v-model="mandate"
							type="text"
							:placeholder="t('shillinq', 'For example: standing in during leave from 30 September to 11 October')"
							data-testid="po-approval-mandate">
						<NcButton type="submit" :disabled="busy" data-testid="po-approval-handover">
							{{ t('shillinq', 'Hand over') }}
						</NcButton>
					</form>
				</div>
			</li>
		</ol>

		<p v-if="actionError" class="po-approval__error" role="alert" data-testid="po-approval-error">
			{{ actionError }}
		</p>
	</section>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import {
	approveStep,
	currentSteps,
	handOverError,
	handOverStep,
	mayDecide,
	rejectStep,
	stepState,
} from '../../utils/purchaseOrderApproval.js'

const TASKS = '/apps/openregister/api/flow-tasks'

const http = {
	post: (url, body) => axios.post(generateUrl(url), body),
}

export default {
	name: 'PurchaseOrderApprovalPanel',
	components: { NcButton },

	props: {
		/** The order, from the detail sidebar's widget binding. */
		objectData: { type: Object, default: null },
		/** The order, from PurchaseOrderDetail.vue. */
		purchaseOrder: { type: Object, default: null },
	},

	emits: ['decided'],

	data() {
		return {
			tasks: [],
			decidable: [],
			loading: false,
			loadError: '',
			actionError: '',
			busy: false,
			form: '',
			comment: '',
			delegate: '',
			mandate: '',
		}
	},

	computed: {
		/** @return {string} The order's OpenRegister uuid. */
		orderUuid() {
			const order = this.purchaseOrder || this.objectData || {}
			return String(order?.['@self']?.id || order.uuid || order.id || '')
		},

		/** @return {Array<object>} The newest round's steps. */
		steps() {
			return currentSteps(this.tasks)
		},

		/** @return {string} The current user. */
		uid() {
			return getCurrentUser()?.uid || ''
		},
	},

	watch: {
		orderUuid: {
			immediate: true,
			handler() {
				this.load()
			},
		},
	},

	methods: {
		/** @spec openspec/changes/purchasing-approval-delegation/tasks.md#task-1.2 */
		async load() {
			if (!this.orderUuid) {
				return
			}
			this.loading = true
			this.loadError = ''
			try {
				const read = (params) => axios.get(generateUrl(TASKS), {
					params: { objectUuid: this.orderUuid, isTerminal: params.isTerminal, scope: params.scope, limit: 100 },
				})
				const [all, pooled, assigned] = await Promise.all([
					read({ scope: 'all' }),
					read({ scope: 'pooled', isTerminal: 'false' }),
					read({ scope: 'assigned', isTerminal: 'false' }),
				])
				this.tasks = all.data?.results || []
				this.decidable = [
					...(pooled.data?.results || []),
					...(assigned.data?.results || []),
				].map((row) => row.uuid)
			} catch {
				this.loadError = this.t('shillinq', 'The approval steps could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {object} step An approval step.
		 * @return {boolean|string}
		 */
		canDecide(step) {
			return mayDecide(step, this.uid, this.decidable)
		},

		/**
		 * @param {object} step An approval step.
		 * @return {boolean|string}
		 */
		stateOf(step) {
			return stepState(step)
		},

		/**
		 * @param {object} step An approval step.
		 * @return {boolean|string}
		 */
		stateLabel(step) {
			return {
				pending: this.t('shillinq', 'Waiting for a decision'),
				waiting: this.t('shillinq', 'Not yet'),
				approved: this.t('shillinq', 'Approved'),
				rejected: this.t('shillinq', 'Rejected'),
				terminated: this.t('shillinq', 'Stopped'),
			}[stepState(step)]
		},

		/**
		 * @param {object} step An approval step.
		 * @return {boolean|string}
		 */
		roleLabel(step) {
			const role = (step.candidateGroups || [])[0] || step.candidateRole || ''
			return {
				teamleider: this.t('shillinq', 'Team leader'),
				facility_manager: this.t('shillinq', 'Facility manager'),
				procurement_manager: this.t('shillinq', 'Procurement manager'),
			}[role] || role
		},

		formatTimestamp(value) {
			const date = new Date(value)
			return Number.isNaN(date.getTime()) ? value : date.toLocaleString()
		},

		openForm(name) {
			this.form = this.form === name ? '' : name
			this.actionError = ''
		},

		/**
		 * @param {object} step An approval step.
		 * @spec openspec/changes/purchasing-approval-delegation/tasks.md#task-1.2
		 */
		async onApprove(step) {
			await this.run(() => approveStep(http, step, this.uid))
		},

		/**
		 * @param {object} step An approval step.
		 * @spec openspec/changes/purchasing-approval-delegation/tasks.md#task-1.2
		 */
		async onReject(step) {
			await this.run(() => rejectStep(http, step, this.uid, this.comment.trim()))
		},

		/**
		 * @param {object} step An approval step.
		 * @spec openspec/changes/purchasing-approval-delegation/tasks.md#task-2.1
		 */
		async onHandOver(step) {
			const missing = handOverError(this.delegate, this.mandate)
			if (missing === 'delegate') {
				this.actionError = this.t('shillinq', 'Name the colleague who takes over this approval.')
				return
			}
			if (missing === 'mandate') {
				this.actionError = this.t('shillinq', 'A hand-over needs a mandate: say on what authority your colleague decides.')
				return
			}
			await this.run(() => handOverStep(http, step, this.uid, this.delegate, this.mandate))
		},

		async run(action) {
			this.busy = true
			this.actionError = ''
			try {
				await action()
				this.form = ''
				this.comment = ''
				this.delegate = ''
				this.mandate = ''
				this.$emit('decided')
				await this.load()
			} catch (e) {
				this.actionError = e?.response?.data?.error
					|| this.t('shillinq', 'OpenRegister refused the decision.')
				await this.load()
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.po-approval__steps {
	list-style: none;
	padding: 0;
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 3);
}

.po-approval__role {
	display: flex;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	align-items: center;
	flex-wrap: wrap;
}

.po-approval__pill {
	border-radius: var(--border-radius-pill, 999px);
	padding: 0 calc(var(--default-grid-baseline, 4px) * 2);
	background: var(--color-background-dark);
	color: var(--color-main-text);
}

.po-approval__pill--approved {
	background: var(--color-success);
	color: var(--color-primary-element-text);
}

.po-approval__pill--rejected {
	background: var(--color-error);
	color: var(--color-primary-element-text);
}

.po-approval__decider,
.po-approval__mandate,
.po-approval__comment {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.po-approval__actions,
.po-approval__form {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	margin-top: calc(var(--default-grid-baseline, 4px) * 2);
}

.po-approval__form {
	flex-direction: column;
	width: 100%;
}

.po-approval__error {
	color: var(--color-error-text, var(--color-error));
}
</style>
