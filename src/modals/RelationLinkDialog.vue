<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 RelationLinkDialog: link this customer to the supplier record of the same
 organisation, or remove the link (reporting-relation-both-sides
 REQ-RRBS-001). Suggested suppliers with an equal KvK or VAT number come
 first; any supplier of the administration can be picked by hand. The link
 is written only when the user presses Link.

 Opened from the "Link to a supplier" header action on CustomerDetail (the
 route's :id is the customer). Its own file for hydra gate-13.

 @spec openspec/changes/reporting-relation-both-sides/specs/bookkeeping-reconciliation-reports/spec.md
-->

<template>
	<NcDialog
		v-if="open"
		:name="t('shillinq', 'Link to a supplier')"
		size="normal"
		data-testid="relation-link-dialog"
		@closing="$emit('close')">
		<div class="relation-link">
			<p v-if="linked" data-testid="relation-link-current">
				{{
					t(
						'shillinq',
						'Linked to {supplier}, matched on {matchedOn}, confirmed by {user}.',
						{
							supplier: linked.payeeName,
							matchedOn: matchedOnLabel(linked.matchedOn),
							user: linked.confirmedBy,
						},
					)
				}}
			</p>
			<template v-else>
				<p>
					{{
						t(
							'shillinq',
							'When this customer is also a supplier, link the two records to see the invoices sent and received together.',
						)
					}}
				</p>
				<ul v-if="suggestions.length > 0" class="relation-link__suggestions">
					<li v-for="pair in suggestions" :key="pair.payeeId">
						{{
							t('shillinq', '{supplier}, same {matchedOn}', {
								supplier: pair.payeeName,
								matchedOn: matchedOnLabel(pair.matchedOn),
							})
						}}
						<NcButton
							data-testid="relation-link-suggested"
							@click="link(pair.payeeId, pair.matchedOn)">
							{{ t('shillinq', 'Link') }}
						</NcButton>
					</li>
				</ul>
				<NcSelect
					v-model="picked"
					:options="payees"
					label="name"
					:inputLabel="t('shillinq', 'Supplier')"
					:placeholder="t('shillinq', 'Pick a supplier')" />
			</template>
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
		</div>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('shillinq', 'Close') }}
			</NcButton>
			<NcButton
				v-if="linked"
				:disabled="busy"
				data-testid="relation-unlink"
				@click="unlink">
				{{ t('shillinq', 'Remove link') }}
			</NcButton>
			<NcButton
				v-else
				variant="primary"
				:disabled="busy || !picked"
				data-testid="relation-link-picked"
				@click="link(picked.id, 'manual')">
				{{ t('shillinq', 'Link') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcNoteCard, NcSelect } from '@nextcloud/vue'

export default {
	name: 'RelationLinkDialog',
	components: {
		NcButton,
		NcDialog,
		NcNoteCard,
		NcSelect,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},

		objectId: {
			type: String,
			default: '',
		},
	},

	emits: ['close'],

	data() {
		return {
			linked: null,
			suggestions: [],
			payees: [],
			picked: null,
			busy: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The customer this dialog links.
		 *
		 * @return {string} The customer id.
		 * @spec openspec/changes/reporting-relation-both-sides/specs/bookkeeping-reconciliation-reports/spec.md
		 */
		customerId() {
			return String(this.objectId || this.$route?.params?.id || '')
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * How a link was matched, in words.
		 *
		 * @param {string} matchedOn kvk, vat or manual.
		 * @return {string} The label.
		 * @spec openspec/changes/reporting-relation-both-sides/specs/bookkeeping-reconciliation-reports/spec.md
		 */
		matchedOnLabel(matchedOn) {
			return (
				{
					kvk: this.t('shillinq', 'KvK number'),
					vat: this.t('shillinq', 'VAT number'),
					manual: this.t('shillinq', 'by hand'),
				}[matchedOn] || matchedOn
			)
		},

		/**
		 * Read the current link, the suggestions for this customer and the suppliers to pick from.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/reporting-relation-both-sides/specs/bookkeeping-reconciliation-reports/spec.md
		 */
		async load() {
			this.error = ''
			try {
				const [relation, suggestions, payees] = await Promise.all([
					axios.get(
						generateUrl(
							'/apps/shillinq/api/relations/{customerId}/both-sides',
							{ customerId: this.customerId },
						),
					),
					axios.get(
						generateUrl('/apps/shillinq/api/relations/suggestions'),
					),
					axios.get(
						generateUrl('/apps/openregister/api/objects/shillinq/Payee'),
						{ params: { _limit: 500 } },
					),
				])
				const data = relation.data || {}
				this.linked =
					data.link && data.payee && data.payee.id
						? {
								payeeName: data.payee.name,
								matchedOn: data.link.matchedOn,
								confirmedBy: data.link.confirmedBy,
							}
						: null
				this.suggestions = (
					(suggestions.data && suggestions.data.suggestions)
					|| []
				).filter((pair) => pair.customerId === this.customerId)
				this.payees = ((payees.data && payees.data.results) || []).map(
					(payee) => ({
						id: payee.id || (payee['@self'] && payee['@self'].id),
						name: payee.name || payee.tradingName,
					}),
				)
			} catch {
				this.error = this.t(
					'shillinq',
					'The supplier records could not be loaded.',
				)
			}
		},

		/**
		 * Link this customer to a supplier.
		 *
		 * @param {string} payeeId The supplier.
		 * @param {string} matchedOn kvk, vat or manual.
		 * @return {Promise<void>}
		 * @spec openspec/changes/reporting-relation-both-sides/specs/bookkeeping-reconciliation-reports/spec.md
		 */
		async link(payeeId, matchedOn) {
			await this.write(() =>
				axios.post(generateUrl('/apps/shillinq/api/relations/links'), {
					customerId: this.customerId,
					payeeId,
					matchedOn,
				}),
			)
		},

		/**
		 * Remove this customer's link.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/reporting-relation-both-sides/specs/bookkeeping-reconciliation-reports/spec.md
		 */
		async unlink() {
			await this.write(() =>
				axios.delete(
					generateUrl('/apps/shillinq/api/relations/links/{customerId}', {
						customerId: this.customerId,
					}),
				),
			)
		},

		/**
		 * Send a write, then refresh the page's widgets and close.
		 *
		 * @param {() => Promise<unknown>} request The request.
		 * @return {Promise<void>}
		 * @spec openspec/changes/reporting-relation-both-sides/specs/bookkeeping-reconciliation-reports/spec.md
		 */
		async write(request) {
			this.busy = true
			this.error = ''
			try {
				await request()
				emit('cn:page:refresh', {})
				this.$emit('close')
			} catch (e) {
				this.error =
					(e.response && e.response.data && e.response.data.error)
					|| this.t('shillinq', 'The link could not be saved.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.relation-link {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.relation-link__suggestions li {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 2);
}
</style>
