<?php

/**
 * Unit tests for the Commitment declarative approval-chain block.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the declarative `x-openregister-approval-chains` block that gates the
 * Commitment `goedkeuren` transition (REQ-VPL-013).
 *
 * The runtime gate itself (block-until-approved, separation of duties, tier
 * routing, auto-advance) is exercised by OpenRegister's own approval-chains tests
 * (OpenRegister REQ-006…010); the declarative block is inert until that release
 * is deployed. These tests prove the block is well-formed against that contract,
 * so the gate enforces rather than silently no-ops once OpenRegister is deployed,
 * and that the imperative mandate-record control is retained (no dead control).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class CommitmentApprovalChainFragmentTest extends TestCase {

	/**
	 * Absolute path to the Commitment register fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-verplichtingenadministratie.json';

	/**
	 * Load the fragment as an array.
	 *
	 * @return array<mixed>
	 */
	private function fragment(): array {
		return json_decode((string)file_get_contents($this->fragmentPath), true);
	}//end fragment()

	/**
	 * Load the Commitment schema definition from the fragment.
	 *
	 * @return array<string, mixed>
	 */
	private function commitment(): array {
		$schemas = ($this->fragment()['components']['schemas'] ?? []);
		self::assertArrayHasKey('Commitment', $schemas, 'Fragment must declare the Commitment schema');
		return $schemas['Commitment'];
	}//end commitment()

	/**
	 * Load the declared approval chain.
	 *
	 * @return array<string, mixed>
	 */
	private function chain(): array {
		$schema = $this->commitment();
		self::assertArrayHasKey('x-openregister-approval-chains', $schema, 'Commitment must declare x-openregister-approval-chains');
		$chains = $schema['x-openregister-approval-chains'];
		self::assertArrayHasKey('commitment-approval', $chains);
		return $chains['commitment-approval'];
	}//end chain()

	/**
	 * The fragment is present and valid JSON.
	 *
	 * @return void
	 */
	public function testFragmentIsValidJson(): void {
		self::assertFileExists($this->fragmentPath);
		$this->fragment();
		self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());

	}//end testFragmentIsValidJson()

	/**
	 * The declared chain names the real `goedkeuren` lifecycle transition
	 * (REQ-VPL-013). ApprovalChainGateListener matches on this exact key.
	 *
	 * @return void
	 */
	public function testChainTargetsGoedkeurenTransition(): void {
		$chain = $this->chain();
		self::assertSame('goedkeuren', $chain['transition']);

		$lifecycle = $this->commitment()['x-openregister-lifecycle'];
		$transitions = ($lifecycle['transitions'] ?? []);
		self::assertArrayHasKey('goedkeuren', $transitions, 'goedkeuren transition must exist for the gate to bind to');
		self::assertSame('in_goedkeuring', $transitions['goedkeuren']['from']);
		self::assertSame('aangegaan', $transitions['goedkeuren']['to']);

	}//end testChainTargetsGoedkeurenTransition()

	/**
	 * The chain routes by a real integer amount field (REQ-VPL-013 / OR REQ-008).
	 *
	 * @return void
	 */
	public function testChainRoutesByRealAmountField(): void {
		$chain = $this->chain();
		self::assertSame('total_amount_excl_vat', $chain['amountField']);

		$properties = ($this->commitment()['properties'] ?? []);
		self::assertArrayHasKey('total_amount_excl_vat', $properties, 'amountField must name a real property');
		self::assertSame('integer', $properties['total_amount_excl_vat']['type']);

	}//end testChainRoutesByRealAmountField()

	/**
	 * Two ordered approver tiers, each carrying role + min + minAmount, routing
	 * from EUR 0 (commitment-administrator) to EUR 250.000 (finance-director).
	 *
	 * @return void
	 */
	public function testChainDeclaresOrderedApproverTiers(): void {
		$approvers = ($this->chain()['approvers'] ?? []);
		self::assertIsArray($approvers);
		self::assertNotEmpty($approvers, 'approvers must be non-empty (OR REQ-006)');

		$previousMin = -1;
		foreach ($approvers as $tier) {
			self::assertArrayHasKey('role', $tier);
			self::assertNotSame('', (string)$tier['role']);
			self::assertArrayHasKey('min', $tier);
			self::assertGreaterThanOrEqual(1, $tier['min']);
			self::assertArrayHasKey('minAmount', $tier);
			self::assertGreaterThan($previousMin, $tier['minAmount'], 'approver tiers must be ordered by ascending minAmount');
			$previousMin = $tier['minAmount'];
		}

		$byAmount = [];
		foreach ($approvers as $tier) {
			$byAmount[(int)$tier['minAmount']] = $tier['role'];
		}

		self::assertSame('commitment-administrator', $byAmount[0] ?? null);
		self::assertSame('finance-director', $byAmount[25000000] ?? null);

	}//end testChainDeclaresOrderedApproverTiers()

	/**
	 * Approver roles are declared in the schema's own RBAC block, so the gate
	 * resolves them against real groups.
	 *
	 * @return void
	 */
	public function testApproverRolesAreDeclaredRbacRoles(): void {
		$roles = ($this->commitment()['x-openregister-rbac']['roles'] ?? []);
		foreach ($this->chain()['approvers'] as $tier) {
			self::assertArrayHasKey($tier['role'], $roles, $tier['role'] . ' must be a declared RBAC role');
		}

	}//end testApproverRolesAreDeclaredRbacRoles()

	/**
	 * Separation of duties and auto-advance are declared (OR REQ-009 / REQ-010).
	 *
	 * @return void
	 */
	public function testChainEnforcesSodAndAutoAdvances(): void {
		$chain = $this->chain();
		self::assertTrue($chain['separationOfDuties'], 'separationOfDuties must be true (approver != requester)');
		self::assertSame('advanceTransition', $chain['onApprove']);

	}//end testChainEnforcesSodAndAutoAdvances()

	/**
	 * No dead control: the imperative mandate-record routing (MandateEnforcer)
	 * is retained and still wired to the indienen transition. This change is
	 * strictly additive; it removes no imperative enforcement.
	 *
	 * @return void
	 */
	public function testMandateEnforcerIsRetained(): void {
		self::assertFileExists(__DIR__ . '/../../../lib/Lifecycle/MandateEnforcer.php');

		$transitions = ($this->commitment()['x-openregister-lifecycle']['transitions'] ?? []);
		self::assertArrayHasKey('indienen', $transitions);
		self::assertSame(
			'OCA\\Shillinq\\Lifecycle\\MandateEnforcer::requiresApproval',
			$transitions['indienen']['requires']
		);

	}//end testMandateEnforcerIsRetained()
}//end class
