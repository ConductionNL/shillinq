<?php

/**
 * Unit tests for the bookkeeping-bcf-vat-compensation register fragment.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-bcf-vat-compensation/specs.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies the BCF fragment is valid JSON, declares the BcfClaim schema with its
 * lifecycle (draft -> submitted -> accepted -> settled) gated by BcfClaimGuard,
 * documents the compensable-VAT aggregation (ADR-037 / ADR-031), re-asserts the
 * BbvAccountMapping BCF flags, merges additively onto the monolith without
 * disturbing existing schemas, and ships seed claims whose breakdown weights are
 * internally consistent (amount × percentage / 100, REQ-BCF-002).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BcfClaimFragmentTest extends TestCase {

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-bcf-vat-compensation.json';

	/**
	 * Absolute path to the monolith register file.
	 *
	 * @var string
	 */
	private string $registerPath = __DIR__ . '/../../../lib/Settings/shillinq_register.json';

	/**
	 * Invoke the private static SettingsService::deepMergeConfig().
	 *
	 * @param array<mixed> $base Base config.
	 * @param array<mixed> $overlay Fragment.
	 *
	 * @return array<mixed> Merged config.
	 */
	private function merge(array $base, array $overlay): array {
		$m = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$m->setAccessible(true);
		return $m->invoke(null, $base, $overlay);
	}//end merge()

	/**
	 * Load the fragment as an array.
	 *
	 * @return array<mixed>
	 */
	private function fragment(): array {
		return json_decode((string)file_get_contents($this->fragmentPath), true);
	}//end fragment()

	/**
	 * The fragment is present and valid JSON with the expected sections.
	 *
	 * @return void
	 */
	public function testFragmentIsValidJson(): void {
		self::assertFileExists($this->fragmentPath);
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
		self::assertArrayHasKey('schemas', $data['components']);
		self::assertArrayHasKey('objects', $data['components']);

	}//end testFragmentIsValidJson()

	/**
	 * The fragment declares the BcfClaim schema with its required properties (REQ-BCF-001).
	 *
	 * @return void
	 */
	public function testDeclaresBcfClaimSchema(): void {
		$schema = $this->fragment()['components']['schemas']['BcfClaim'];
		self::assertSame('BcfClaim', $schema['slug']);

		$expected = [
			'claimQuarter',
			'administrationId',
			'totalCompensableAmount',
			'breakdown',
			'state',
			'submittedOn',
			'acceptedOn',
			'settledOn',
			'attachmentUri',
			'notes',
		];
		foreach ($expected as $field) {
			self::assertArrayHasKey($field, $schema['properties'], "BcfClaim must declare $field");
		}

		self::assertSame(['draft', 'submitted', 'accepted', 'settled'], $schema['properties']['state']['enum']);

	}//end testDeclaresBcfClaimSchema()

	/**
	 * The lifecycle gates draft -> submitted with the BcfClaimGuard (REQ-BCF-003).
	 *
	 * @return void
	 */
	public function testDeclaresLifecycleWithSubmitGuard(): void {
		$lifecycle = $this->fragment()['components']['schemas']['BcfClaim']['x-openregister-lifecycle'];
		self::assertSame('state', $lifecycle['field']);
		self::assertSame('draft', $lifecycle['initialState']);

		$submit = $lifecycle['transitions']['submit'];
		self::assertSame('draft', $submit['from']);
		self::assertSame('submitted', $submit['to']);
		self::assertSame('OCA\\Shillinq\\Lifecycle\\BcfClaimGuard::canSubmit', $submit['requires']);

	}//end testDeclaresLifecycleWithSubmitGuard()

	/**
	 * The compensable-VAT roll-up is declared as an aggregation (ADR-031, REQ-BCF-002).
	 *
	 * @return void
	 */
	public function testDeclaresAggregation(): void {
		$schema = $this->fragment()['components']['schemas']['BcfClaim'];
		self::assertArrayHasKey('x-openregister-aggregations', $schema);
		self::assertArrayHasKey('compensableVatBreakdown', $schema['x-openregister-aggregations']);

	}//end testDeclaresAggregation()

	/**
	 * The fragment re-asserts the BbvAccountMapping BCF flags (REQ-BCF-004).
	 *
	 * @return void
	 */
	public function testReAssertsBbvCompensableFlags(): void {
		$bbv = $this->fragment()['components']['schemas']['BbvAccountMapping']['properties'];
		self::assertArrayHasKey('bcfCompensable', $bbv);
		self::assertArrayHasKey('compensablePercentage', $bbv);
		self::assertFalse($bbv['bcfCompensable']['default']);
		self::assertSame(100, $bbv['compensablePercentage']['default']);

	}//end testReAssertsBbvCompensableFlags()

	/**
	 * Merging the fragment over the full register.d chain adds BcfClaim without
	 * dropping existing schemas and keeps BbvAccountMapping's required keys
	 * (ADR-037 disjoint union). BbvAccountMapping is owned by the operations
	 * fragment, so the realistic merge folds every register.d/*.json in order.
	 *
	 * @return void
	 */
	public function testFragmentMergesAdditivelyOntoMonolith(): void {
		$merged = json_decode((string)file_get_contents($this->registerPath), true);

		// Fold every register.d fragment in sorted order, as SettingsService does.
		$fragments = glob(__DIR__ . '/../../../lib/Settings/register.d/*.json');
		sort($fragments);
		foreach ($fragments as $fragmentFile) {
			$merged = $this->merge($merged, json_decode((string)file_get_contents($fragmentFile), true));
		}

		$schemas = $merged['components']['schemas'];

		self::assertArrayHasKey('BcfClaim', $schemas);
		// The pre-existing BbvAccountMapping schema survives with its required keys.
		self::assertArrayHasKey('BbvAccountMapping', $schemas);
		self::assertContains('accountNumber', $schemas['BbvAccountMapping']['required']);
		// And it carries the BCF flags after the merge.
		self::assertArrayHasKey('bcfCompensable', $schemas['BbvAccountMapping']['properties']);
		// The trial-balance schema from a sibling fragment also survives.
		self::assertArrayHasKey('TrialBalanceLine', $schemas);

	}//end testFragmentMergesAdditivelyOntoMonolith()

	/**
	 * Seed claims target only declared schemas and are internally consistent (REQ-BCF-002).
	 *
	 * @return void
	 */
	public function testSeedClaimsAreConsistent(): void {
		$frag = $this->fragment();
		$schemas = $frag['components']['schemas'];
		$objects = $frag['components']['objects'];

		self::assertNotEmpty($objects);
		foreach ($objects as $object) {
			self::assertArrayHasKey('@self', $object);
			self::assertSame('shillinq', $object['@self']['register']);
			self::assertArrayHasKey($object['@self']['schema'], $schemas);

			// Total equals the sum of breakdown compensableAmount (in cents, REQ-BCF-002).
			$totalCents = (int)round(((float)($object['totalCompensableAmount'] ?? 0)) * 100);
			$sumCents = 0;
			foreach (($object['breakdown'] ?? []) as $row) {
				// Each row: compensableAmount = amount × percentage / 100.
				$weighted = (int)round((((float)$row['amount']) * ((int)$row['compensablePercentage'])) / 100 * 100);
				self::assertSame(
					$weighted,
					(int)round(((float)$row['compensableAmount']) * 100),
					'Breakdown row for ' . $object['@self']['slug'] . ' account ' . $row['accountNumber'] . ' must weight correctly'
				);
				$sumCents += (int)round(((float)$row['compensableAmount']) * 100);
			}

			self::assertSame(
				$totalCents,
				$sumCents,
				'Seed ' . $object['@self']['slug'] . ' total must equal the sum of its breakdown'
			);
		}//end foreach

	}//end testSeedClaimsAreConsistent()

	/**
	 * The approval-chain seam gates the submit transition for the bcf-administrator role (REQ-BCF-006, Task 4.3).
	 *
	 * Integration-shape test: instead of a live OR ApprovalWorkflow run (which
	 * needs a Nextcloud container and is deferred per the build note), this test
	 * verifies the declarative contract OR consumes: the chain is bound to the
	 * `submit` transition; exactly one approver with role `bcf-administrator` is
	 * required; the configured timeout matches REQ-BCF-006 (7 days); the
	 * `onApprove` / `onReject` actions match the spec (advance vs. notify); and
	 * the audit-event taxonomy is complete so the audit trail can reconstruct
	 * the approval timeline per REQ-BCF-009.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-bcf-vat-compensation/spec.md#req-bcf-009
	 */
	public function testApprovalChainGatesSubmitTransition(): void {
		$schema = $this->fragment()['components']['schemas']['BcfClaim'];

		self::assertArrayHasKey(
			'x-openregister-approval-chains',
			$schema,
			'BcfClaim must declare an approval-chain block (REQ-BCF-006).'
		);

		$chains = $schema['x-openregister-approval-chains'];
		self::assertArrayHasKey(
			'bcf-claim-submit-approval',
			$chains,
			'The bcf-claim-submit-approval chain must be declared (REQ-BCF-006).'
		);

		$chain = $chains['bcf-claim-submit-approval'];
		self::assertSame('submit', $chain['transition'], 'Chain must gate the submit transition.');
		self::assertSame(7, $chain['timeoutDays'], 'REQ-BCF-006 mandates a 7-day approval timeout.');
		self::assertSame('advanceTransition', $chain['onApprove']);
		self::assertSame('notifyOperator', $chain['onReject']);

		self::assertCount(1, $chain['approvers'], 'Exactly one approver step (REQ-BCF-006).');
		self::assertSame('bcf-administrator', $chain['approvers'][0]['role']);
		self::assertSame(1, $chain['approvers'][0]['min']);

		foreach (['task.created', 'task.approved', 'task.rejected', 'task.timeout'] as $event) {
			self::assertContains(
				$event,
				$chain['auditEvents'],
				'Approval audit-event taxonomy must include ' . $event . ' (REQ-BCF-009).'
			);
		}

		// The transition the chain references must actually exist on the
		// lifecycle so OR has a valid binding target.
		$lifecycle = $schema['x-openregister-lifecycle'];
		self::assertArrayHasKey(
			'submit',
			$lifecycle['transitions'],
			'submit transition must exist on the lifecycle (Task 4.3 integration shape).'
		);
		self::assertSame('draft', $lifecycle['transitions']['submit']['from']);
		self::assertSame('submitted', $lifecycle['transitions']['submit']['to']);

	}//end testApprovalChainGatesSubmitTransition()

	/**
	 * The settlement-webhook seam routes CloudEvents to the settle transition (REQ-BCF-007, Task 4.4).
	 *
	 * Integration-shape test: instead of POSTing a real CloudEvent into a
	 * running OR webhook endpoint (which needs a NC instance and is deferred
	 * per the build note), this test verifies OR's contract: the event type
	 * matches the canonical `nl.conduction.bcf-claim-settled`, the source is
	 * the OpenConnector digikoppeling-bcf integration, the bound transition is
	 * `settle`, the target updates cover `state`, `settledOn` and
	 * `settledAmount`, and the audit-event taxonomy includes received/applied/
	 * rejected so the audit trail captures lost-webhook fallbacks per
	 * REQ-BCF-007.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-bcf-vat-compensation/spec.md#req-bcf-007
	 */
	public function testSettlementWebhookRoutesToSettleTransition(): void {
		$schema = $this->fragment()['components']['schemas']['BcfClaim'];

		self::assertArrayHasKey(
			'x-openregister-webhooks',
			$schema,
			'BcfClaim must declare a webhook-routing block (REQ-BCF-007).'
		);

		$webhooks = $schema['x-openregister-webhooks'];
		self::assertArrayHasKey(
			'bcf-claim-settled',
			$webhooks,
			'The bcf-claim-settled webhook must be declared (REQ-BCF-007).'
		);

		$webhook = $webhooks['bcf-claim-settled'];
		self::assertSame('nl.conduction.bcf-claim-settled', $webhook['eventType']);
		self::assertSame('openconnector:digikoppeling-bcf', $webhook['source']);
		self::assertSame('settle', $webhook['transition']);

		foreach (['state', 'settledOn', 'settledAmount'] as $field) {
			self::assertArrayHasKey(
				$field,
				$webhook['targetUpdates'],
				'Webhook must update ' . $field . ' on apply (REQ-BCF-007).'
			);
		}

		foreach (['webhook.received', 'webhook.applied', 'webhook.rejected'] as $event) {
			self::assertContains(
				$event,
				$webhook['auditEvents'],
				'Webhook audit-event taxonomy must include ' . $event . ' (REQ-BCF-009).'
			);
		}

		// The settle transition the webhook references must exist on the
		// lifecycle so the routing has a valid binding target.
		$lifecycle = $schema['x-openregister-lifecycle'];
		self::assertArrayHasKey(
			'settle',
			$lifecycle['transitions'],
			'settle transition must exist on the lifecycle (Task 4.4 integration shape).'
		);
		self::assertSame('accepted', $lifecycle['transitions']['settle']['from']);
		self::assertSame('settled', $lifecycle['transitions']['settle']['to']);

		// The webhook updates the same fields the schema declares — guard
		// against drift between the webhook contract and the data model.
		foreach (array_keys($webhook['targetUpdates']) as $field) {
			self::assertArrayHasKey(
				$field,
				$schema['properties'],
				'Webhook targetUpdate field ' . $field . ' must be declared on BcfClaim.'
			);
		}

	}//end testSettlementWebhookRoutesToSettleTransition()

	/**
	 * Seed claims cover all four lifecycle states across multiple administrations (REQ-BCF-003).
	 *
	 * @return void
	 */
	public function testSeedClaimsCoverLifecycleStates(): void {
		$objects = $this->fragment()['components']['objects'];
		$states = [];
		$admins = [];
		foreach ($objects as $object) {
			$states[$object['state']] = true;
			$admins[$object['administrationId']] = true;
		}

		foreach (['draft', 'submitted', 'accepted', 'settled'] as $state) {
			self::assertArrayHasKey($state, $states, "Expect a seed claim in $state state");
		}

		self::assertGreaterThanOrEqual(2, count($admins), 'Expect >= 2 distinct administrations');

	}//end testSeedClaimsCoverLifecycleStates()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
