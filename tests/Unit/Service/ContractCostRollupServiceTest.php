<?php

/**
 * Unit tests for ContractCostRollupService.
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
 * @spec openspec/changes/fees-payments-and-the-contract-register/specs/fees-payments-and-the-contract-register/spec.md (REQ-FPCR-005)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\ContractCostRollupService;
use OCA\Shillinq\Service\SubjectCostService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Covers the roll-up, its timestamp, the unlink and, above all, that the unlink
 * leaves the linked object alone (REQ-FPCR-005).
 */
final class ContractCostRollupServiceTest extends TestCase {
	/**
	 * Objects written during the test.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Build the service with a cost per linked object id, in cents.
	 *
	 * @param array<string, int|null> $costsById Cost in cents per subject id; null withholds it.
	 *
	 * @return ContractCostRollupService The service.
	 */
	private function service(array $costsById): ContractCostRollupService {
		$saved = &$this->saved;
		$double = new class($saved) {
			/**
			 * @param array<int, array<string, mixed>> $saved Sink.
			 */
			public function __construct(private array &$saved) {
			}

			public function setRegister(string $register): static {
				return $this;
			}

			public function setSchema(string $schema): static {
				return $this;
			}

			/**
			 * @param array<string, mixed> $params Query params.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $params = []): array {
				return [];
			}

			/**
			 * @param array<string, mixed> $object Object to persist.
			 * @param string $register Register slug.
			 * @param string $schema Schema slug.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, string $register = '', string $schema = ''): array {
				$this->saved[] = ['schema' => $schema, 'object' => $object];
				return $object;
			}
		};

		// onlyMethods: a double that could invent a method the real class lacks
		// would let this test pass against a signature that does not exist.
		$costs = $this->getMockBuilder(SubjectCostService::class)
			->disableOriginalConstructor()
			->onlyMethods(['costFor'])
			->getMock();
		$costs->method('costFor')->willReturnCallback(
			static function (string $subjectApp, string $subjectId, ?array $administrationIds, string $period = '') use ($costsById): array {
				return ['costCents' => ($costsById[$subjectId] ?? null)];
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		return new ContractCostRollupService(
			objectService: new DuckObjectServiceAdapter(inner: $double),
			subjectCosts: $costs,
			appConfig: $appConfig,
		);
	}//end service()

	/**
	 * A contract with three linked cases.
	 *
	 * @return array<string, mixed> The contract.
	 */
	private function contract(): array {
		return [
			'id' => 'c-1',
			'contractNumber' => 'OVK-2026-0001',
			'totalContractValue' => 100000.0,
			'linkedObjects' => [
				['type' => 'case', 'register' => 'dossiq', 'schema' => 'Zaak', 'id' => 'zaak-1'],
				['type' => 'case', 'register' => 'dossiq', 'schema' => 'Zaak', 'id' => 'zaak-2'],
				['type' => 'case', 'register' => 'dossiq', 'schema' => 'Zaak', 'id' => 'zaak-3'],
			],
		];
	}//end contract()

	/**
	 * The three linked cases roll up into one total, each carrying its own cost,
	 * and the remaining value follows (REQ-FPCR-005).
	 *
	 * @return void
	 */
	public function testTheLinkedCasesRollUpIntoOneTotal(): void {
		$service = $this->service(['zaak-1' => 120000, 'zaak-2' => 45050, 'zaak-3' => 17000]);

		$contract = $service->rollUp($this->contract());

		self::assertSame(1820.5, $contract['incurredCost']);
		self::assertSame(98179.5, $contract['remainingValue']);
		self::assertSame(1200.0, $contract['linkedObjects'][0]['cost']);
	}//end testTheLinkedCasesRollUpIntoOneTotal()

	/**
	 * The total is stamped with the time it was computed. A total with no
	 * timestamp cannot be told apart from one that stopped updating, and the
	 * second is what people make decisions on.
	 *
	 * @return void
	 */
	public function testTheTotalIsStampedWithItsComputationTime(): void {
		$service = $this->service(['zaak-1' => 100, 'zaak-2' => 100, 'zaak-3' => 100]);

		$contract = $service->rollUp($this->contract());

		self::assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
			(string)$contract['incurredCostComputedAt']
		);
	}//end testTheTotalIsStampedWithItsComputationTime()

	/**
	 * A cost the aggregator WITHHOLDS (an unpriced person) counts as nothing, not
	 * as a guess. SubjectCostService is right to withhold it, and inventing a
	 * number here would undo that.
	 *
	 * @return void
	 */
	public function testAWithheldCostCountsAsNothing(): void {
		$service = $this->service(['zaak-1' => 120000, 'zaak-2' => null, 'zaak-3' => null]);

		$contract = $service->rollUp($this->contract());

		self::assertSame(1200.0, $contract['incurredCost']);
	}//end testAWithheldCostCountsAsNothing()

	/**
	 * Unlinking drops that case's cost from the total.
	 *
	 * @return void
	 */
	public function testUnlinkingDropsThatCasesCost(): void {
		$service = $this->service(['zaak-1' => 120000, 'zaak-2' => 45050, 'zaak-3' => 17000]);

		$contract = $service->unlink(
			$this->contract(),
			['register' => 'dossiq', 'schema' => 'Zaak', 'id' => 'zaak-1']
		);

		self::assertCount(2, $contract['linkedObjects']);
		self::assertSame(620.5, $contract['incurredCost']);
	}//end testUnlinkingDropsThatCasesCost()

	/**
	 * Unlinking writes NOTHING to the unlinked object. The link was the
	 * contract's claim about the case, not a property of the case, and a roll-up
	 * that wrote back into another app's object is exactly the cross-app write
	 * ADR-066 exists to prevent.
	 *
	 * @return void
	 */
	public function testUnlinkingLeavesTheCaseUntouched(): void {
		$service = $this->service(['zaak-1' => 120000, 'zaak-2' => 45050, 'zaak-3' => 17000]);

		$service->unlink($this->contract(), ['register' => 'dossiq', 'schema' => 'Zaak', 'id' => 'zaak-1']);

		$schemas = array_map(static fn (array $s): string => $s['schema'], $this->saved);
		self::assertSame(['Contract'], array_values(array_unique($schemas)));
	}//end testUnlinkingLeavesTheCaseUntouched()

	/**
	 * Unlinking something that was never linked changes nothing but the stamp, so
	 * a double unlink is not a way to lose a link.
	 *
	 * @return void
	 */
	public function testUnlinkingSomethingElseLeavesTheLinksAlone(): void {
		$service = $this->service(['zaak-1' => 100, 'zaak-2' => 100, 'zaak-3' => 100]);

		$contract = $service->unlink(
			$this->contract(),
			['register' => 'dossiq', 'schema' => 'Zaak', 'id' => 'zaak-99']
		);

		self::assertCount(3, $contract['linkedObjects']);
	}//end testUnlinkingSomethingElseLeavesTheLinksAlone()

	/**
	 * A contract with nothing linked rolls up to zero and still gets its stamp,
	 * rather than looking like a contract that was never computed.
	 *
	 * @return void
	 */
	public function testAContractWithNoLinksRollsUpToZero(): void {
		$service = $this->service([]);

		$contract = $service->rollUp(['id' => 'c-2', 'totalContractValue' => 500.0, 'linkedObjects' => []]);

		self::assertSame(0.0, $contract['incurredCost']);
		self::assertSame(500.0, $contract['remainingValue']);
		self::assertNotSame('', (string)$contract['incurredCostComputedAt']);
	}//end testAContractWithNoLinksRollsUpToZero()

	/**
	 * A linked case nobody can price makes the total INCOMPLETE, and the
	 * contract says so. It used to contribute zero in silence, and the comment
	 * beside it claimed the tell was a total that stops moving. It is not: the
	 * timestamp is written on every run, so an understated total looks exactly
	 * like a freshly computed correct one (REQ-FPCR-005).
	 *
	 * @return void
	 */
	public function testAnUnpricedLinkMarksTheTotalIncomplete(): void {
		$service = $this->service(['zaak-1' => 120000, 'zaak-2' => null, 'zaak-3' => 17000]);

		$contract = $service->rollUp($this->contract());

		self::assertFalse($contract['incurredCostComplete']);
		self::assertSame(1, $contract['incurredCostUnreadableLinks']);
	}//end testAnUnpricedLinkMarksTheTotalIncomplete()

	/**
	 * An incomplete total understates the cost, so the remaining value derived
	 * from it OVERSTATES the budget left and somebody commits money that is
	 * already spent. The field is withheld instead (REQ-FPCR-005).
	 *
	 * @return void
	 */
	public function testAnIncompleteRollUpWithholdsTheRemainingValue(): void {
		$service = $this->service(['zaak-1' => 120000, 'zaak-2' => null, 'zaak-3' => 17000]);

		$contract = $service->rollUp($this->contract());

		self::assertArrayNotHasKey('remainingValue', $contract);
	}//end testAnIncompleteRollUpWithholdsTheRemainingValue()

	/**
	 * An unpriced link carries no cost of its own either. Rendering 0.00 beside
	 * it says the case cost nothing, which is the same lie one row down.
	 *
	 * @return void
	 */
	public function testAnUnpricedLinkCarriesNoCostOfItsOwn(): void {
		$service = $this->service(['zaak-1' => 120000, 'zaak-2' => null, 'zaak-3' => 17000]);

		$contract = $service->rollUp($this->contract());

		self::assertNull($contract['linkedObjects'][1]['cost']);
		self::assertSame(1200.0, $contract['linkedObjects'][0]['cost']);
	}//end testAnUnpricedLinkCarriesNoCostOfItsOwn()

	/**
	 * A complete roll-up still says so, and still reports the remaining value.
	 * The flag has to be able to read true, or it only ever means "this field
	 * exists" (REQ-FPCR-005).
	 *
	 * @return void
	 */
	public function testACompleteRollUpSaysSoAndKeepsTheRemainingValue(): void {
		$service = $this->service(['zaak-1' => 120000, 'zaak-2' => 45050, 'zaak-3' => 17000]);

		$contract = $service->rollUp($this->contract());

		self::assertTrue($contract['incurredCostComplete']);
		self::assertSame(0, $contract['incurredCostUnreadableLinks']);
		self::assertSame((100000.0 - 1820.5), $contract['remainingValue']);
	}//end testACompleteRollUpSaysSoAndKeepsTheRemainingValue()

	/**
	 * A contract that already carried a remaining value loses it when a later
	 * roll-up comes back incomplete. A stale number left in place is read as a
	 * current one, which is the whole failure this change is about.
	 *
	 * @return void
	 */
	public function testAStaleRemainingValueIsRemovedOnAnIncompleteRollUp(): void {
		$service = $this->service(['zaak-1' => 120000, 'zaak-2' => null, 'zaak-3' => 17000]);
		$contract = $this->contract();
		$contract['remainingValue'] = 98179.5;

		$rolled = $service->rollUp($contract);

		self::assertArrayNotHasKey('remainingValue', $rolled);
		self::assertArrayNotHasKey('remainingValue', $this->saved[0]['object']);
	}//end testAStaleRemainingValueIsRemovedOnAnIncompleteRollUp()
}//end class
