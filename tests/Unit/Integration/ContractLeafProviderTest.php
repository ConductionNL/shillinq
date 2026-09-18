<?php

/**
 * Unit tests for ContractLeafProvider.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/fees-payments-and-the-contract-register/specs/fees-payments-and-the-contract-register/spec.md (REQ-FPCR-006)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Integration;

use OCA\Shillinq\Integration\ContractLeafProvider;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Covers the scoping to the host object, the attention statuses, and that a
 * contract the reader may not see leaks nothing at all (REQ-FPCR-006).
 */
final class ContractLeafProviderTest extends TestCase {
	/**
	 * Build the provider over the contracts the CALLER can read.
	 *
	 * @param array<int, array<string, mixed>> $readable Contracts visible to the caller.
	 *
	 * @return ContractLeafProvider The provider.
	 */
	private function makeProvider(array $readable): ContractLeafProvider {
		$double = new class($readable) {
			/**
			 * @param array<int, array<string, mixed>> $readable Readable contracts.
			 */
			public function __construct(private array $readable) {
			}

			public function setRegister(string $register): static {
				return $this;
			}

			public function setSchema(string $schema): static {
				return $this;
			}

			/**
			 * The store answers only what the caller may read, which is how
			 * OpenRegister behaves. A contract the caller cannot see is simply
			 * absent from this list, never present with fields blanked.
			 *
			 * @param array<string, mixed> $params Query params.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $params = []): array {
				return $this->readable;
			}
		};

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		return new ContractLeafProvider(
			objectService: new DuckObjectServiceAdapter(inner: $double),
			appConfig: $appConfig,
		);
	}//end makeProvider()

	/**
	 * A contract linked to one case.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 * @param string $caseId The case it is linked to.
	 *
	 * @return array<string, mixed> The contract.
	 */
	private function contract(array $overrides = [], string $caseId = 'zaak-7'): array {
		return array_merge(
			[
				'id' => 'c-1',
				'contractNumber' => 'OVK-2026-0001',
				'title' => 'Onderhoud gemalen',
				'counterpartyReference' => 'cm-9001',
				'startDate' => '2026-01-01',
				'endDate' => '2026-12-31',
				'status' => 'active',
				'currency' => 'EUR',
				'totalContractValue' => 100000.0,
				'incurredCost' => 1820.5,
				'remainingValue' => 98179.5,
				'incurredCostComputedAt' => '2026-09-18T03:00:00Z',
				'linkedObjects' => [['type' => 'case', 'register' => 'dossiq', 'schema' => 'Zaak', 'id' => $caseId]],
			],
			$overrides
		);
	}//end contract()

	/**
	 * The leaf names itself the same on both halves, which is what gate-24 pairs.
	 *
	 * @return void
	 */
	public function testTheLeafNamesItself(): void {
		$provider = $this->makeProvider([]);

		self::assertSame('shillinq-contracts', $provider->getId());
		self::assertSame('app-local', $provider->getStorageStrategy());
	}//end testTheLeafNamesItself()

	/**
	 * The handler sees the contract behind their case: counterparty, term and
	 * what is left of the value, read from shillinq and not copied into the case.
	 *
	 * @return void
	 */
	public function testAHandlerSeesTheContractBehindTheCase(): void {
		$provider = $this->makeProvider([$this->contract()]);

		$listed = $provider->list('dossiq', 'Zaak', 'zaak-7');

		self::assertCount(1, $listed['items']);
		$contract = $listed['items'][0];
		self::assertSame('cm-9001', $contract['counterpartyReference']);
		self::assertSame('2026-12-31', $contract['endDate']);
		self::assertSame(98179.5, $contract['remainingValue']);
		self::assertSame('2026-09-18T03:00:00Z', $contract['incurredCostComputedAt']);
	}//end testAHandlerSeesTheContractBehindTheCase()

	/**
	 * An expiring contract says so, because that changes what the handler should
	 * do next (REQ-FPCR-006).
	 *
	 * @return void
	 */
	public function testAnExpiringContractIsReportedAsNeedingAttention(): void {
		$provider = $this->makeProvider([$this->contract(['status' => 'expiring'])]);

		$contract = $provider->list('dossiq', 'Zaak', 'zaak-7')['items'][0];

		self::assertSame('expiring', $contract['status']);
		self::assertTrue($contract['needsAttention']);
	}//end testAnExpiringContractIsReportedAsNeedingAttention()

	/**
	 * An active contract is not flagged, so the flag still means something.
	 *
	 * @return void
	 */
	public function testAnActiveContractIsNotFlagged(): void {
		$provider = $this->makeProvider([$this->contract()]);

		self::assertFalse($provider->list('dossiq', 'Zaak', 'zaak-7')['items'][0]['needsAttention']);
	}//end testAnActiveContractIsNotFlagged()

	/**
	 * A contract linked to ANOTHER case is not shown here. The host object is the
	 * scope, and a leaf that ignored it would show every contract in the
	 * administration on every case.
	 *
	 * @return void
	 */
	public function testAContractLinkedToAnotherCaseIsNotShown(): void {
		$provider = $this->makeProvider([$this->contract([], 'zaak-99')]);

		self::assertCount(0, $provider->list('dossiq', 'Zaak', 'zaak-7')['items']);
	}//end testAContractLinkedToAnotherCaseIsNotShown()

	/**
	 * A contract the reader may not see returns NOT FOUND, with no fields at all.
	 *
	 * The store answers only what the caller may read, so an unreadable contract
	 * never reaches the projection. A partial record would tell the reader a
	 * contract exists and roughly who it is with, which is most of what they were
	 * not allowed to know (REQ-FPCR-006).
	 *
	 * @return void
	 */
	public function testAContractTheReaderMayNotSeeIsNotFoundAndLeaksNothing(): void {
		$provider = $this->makeProvider([]);

		try {
			$provider->get('dossiq', 'Zaak', 'zaak-7', 'c-1');
			self::fail('The leaf answered for a contract the reader may not see.');
		} catch (RuntimeException $e) {
			self::assertStringContainsString('404', $e->getMessage());
			self::assertStringNotContainsString('cm-9001', $e->getMessage());
		}
	}//end testAContractTheReaderMayNotSeeIsNotFoundAndLeaksNothing()

	/**
	 * `get` is scoped to the host object too: a readable contract that is not
	 * linked to THIS case cannot be reached through it by guessing the id.
	 *
	 * @return void
	 */
	public function testGetCannotReachAContractLinkedElsewhere(): void {
		$provider = $this->makeProvider([$this->contract([], 'zaak-99')]);

		$this->expectException(RuntimeException::class);

		$provider->get('dossiq', 'Zaak', 'zaak-7', 'c-1');
	}//end testGetCannotReachAContractLinkedElsewhere()

	/**
	 * The leaf reads. A contract is administered in shillinq, where its lifecycle
	 * and audit trail are, and never created or edited sideways from a case.
	 *
	 * @return void
	 */
	public function testTheLeafRefusesToCreateAContract(): void {
		$provider = $this->makeProvider([]);

		$this->expectException(RuntimeException::class);

		$provider->create('dossiq', 'Zaak', 'zaak-7', ['contractNumber' => 'OVK-2026-0002']);
	}//end testTheLeafRefusesToCreateAContract()
}//end class
