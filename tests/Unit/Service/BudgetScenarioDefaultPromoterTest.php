<?php

/**
 * Unit tests for BudgetScenarioDefaultPromoter.
 *
 * Covers `budget-scenarios` REQ-BSC-002: atomic demotion of the previous
 * default in the same `promote()` call, promoting when no default exists,
 * promoting an already-default scenario as a no-op, the zero-default state,
 * and the post-promotion verification-mismatch logging path (a simulated
 * concurrent-promotion race).
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
 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\BudgetScenarioDefaultPromoter;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\ObjectEntityStub;
use OCA\Shillinq\Tests\Unit\Service\Support\RacingDefaultObjectServiceDecorator;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests the atomic-demotion promotion service.
 */
final class BudgetScenarioDefaultPromoterTest extends TestCase {

	/**
	 * Build the promoter over a seeded in-memory OpenRegister.
	 *
	 * @param array<int,array<string,mixed>> $existingScenarios Seeded BudgetScenario rows.
	 * @param LoggerInterface|null $logger Optional logger override (default NullLogger).
	 *
	 * @return array{0: BudgetScenarioDefaultPromoter, 1: InMemoryObjectServiceStub}
	 */
	private function buildPromoter(array $existingScenarios, ?LoggerInterface $logger = null): array {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$store = new InMemoryObjectServiceStub(['BudgetScenario' => $existingScenarios]);

		$promoter = new BudgetScenarioDefaultPromoter(
			appConfig: $appConfig,
			logger: ($logger ?? new NullLogger()),
			objectService: $store,
		);

		return [$promoter, $store];
	}//end buildPromoter()

	/**
	 * Promoting scenario B demotes scenario A (the previous default) in the
	 * same call; exactly one default remains for the administration
	 * afterward.
	 *
	 * @return void
	 */
	public function testPromotingNewDefaultDemotesThePreviousOneInTheSameAction(): void {
		$existing = [
			[
				'id' => 'scn-a',
				'administrationId' => 'adm-1',
				'name' => 'Scenario A',
				'isDefault' => true,
				'status' => 'active',
			],
			[
				'id' => 'scn-b',
				'administrationId' => 'adm-1',
				'name' => 'Scenario B',
				'isDefault' => false,
				'status' => 'draft',
			],
		];
		[$promoter, $store] = $this->buildPromoter($existing);

		$outcome = $promoter->promote(scenarioId: 'scn-b');

		$this->assertSame('scn-a', $outcome['demotedScenarioId']);
		$this->assertTrue($outcome['verified']);
		$this->assertSame(1, $outcome['defaultCount']);

		$rows = $store->setRegister('shillinq')->setSchema('BudgetScenario')->findAll();
		$byId = [];
		foreach ($rows as $row) {
			$byId[$row['id']] = $row;
		}

		$this->assertFalse($byId['scn-a']['isDefault']);
		$this->assertTrue($byId['scn-b']['isDefault']);
		$this->assertSame('active', $byId['scn-b']['status']);

	}//end testPromotingNewDefaultDemotesThePreviousOneInTheSameAction()

	/**
	 * Promoting with no existing default simply sets the target's isDefault
	 * true — nothing to demote.
	 *
	 * @return void
	 */
	public function testPromotingWithNoExistingDefault(): void {
		$existing = [
			[
				'id' => 'scn-a',
				'administrationId' => 'adm-1',
				'name' => 'Scenario A',
				'isDefault' => false,
				'status' => 'draft',
			],
		];
		[$promoter, $store] = $this->buildPromoter($existing);

		$outcome = $promoter->promote(scenarioId: 'scn-a');

		$this->assertNull($outcome['demotedScenarioId']);
		$this->assertTrue($outcome['verified']);
		$this->assertSame(1, $outcome['defaultCount']);

		$rows = $store->setRegister('shillinq')->setSchema('BudgetScenario')->findAll();
		$this->assertTrue($rows[0]['isDefault']);

	}//end testPromotingWithNoExistingDefault()

	/**
	 * Promoting an already-default scenario is a no-op: no sibling is
	 * touched, and it remains the sole default.
	 *
	 * @return void
	 */
	public function testPromotingAnAlreadyDefaultScenarioIsANoOp(): void {
		$existing = [
			[
				'id' => 'scn-a',
				'administrationId' => 'adm-1',
				'name' => 'Scenario A',
				'isDefault' => true,
				'status' => 'active',
			],
			[
				'id' => 'scn-b',
				'administrationId' => 'adm-1',
				'name' => 'Scenario B',
				'isDefault' => false,
				'status' => 'draft',
			],
		];
		[$promoter, $store] = $this->buildPromoter($existing);

		$outcome = $promoter->promote(scenarioId: 'scn-a');

		$this->assertNull($outcome['demotedScenarioId']);
		$this->assertSame(1, $outcome['defaultCount']);

		$rows = $store->setRegister('shillinq')->setSchema('BudgetScenario')->findAll();
		$byId = [];
		foreach ($rows as $row) {
			$byId[$row['id']] = $row;
		}

		$this->assertTrue($byId['scn-a']['isDefault']);
		$this->assertFalse($byId['scn-b']['isDefault']);

	}//end testPromotingAnAlreadyDefaultScenarioIsANoOp()

	/**
	 * A default in a DIFFERENT administration is untouched by promoting a
	 * scenario in this one — the invariant is scoped per administration.
	 *
	 * @return void
	 */
	public function testDoesNotDemoteADefaultInADifferentAdministration(): void {
		$existing = [
			[
				'id' => 'scn-other-adm',
				'administrationId' => 'adm-2',
				'name' => 'Other admin default',
				'isDefault' => true,
				'status' => 'active',
			],
			[
				'id' => 'scn-a',
				'administrationId' => 'adm-1',
				'name' => 'Scenario A',
				'isDefault' => false,
				'status' => 'draft',
			],
		];
		[$promoter, $store] = $this->buildPromoter($existing);

		$promoter->promote(scenarioId: 'scn-a');

		$rows = $store->setRegister('shillinq')->setSchema('BudgetScenario')->findAll();
		$byId = [];
		foreach ($rows as $row) {
			$byId[$row['id']] = $row;
		}

		$this->assertTrue($byId['scn-other-adm']['isDefault']);
		$this->assertTrue($byId['scn-a']['isDefault']);

	}//end testDoesNotDemoteADefaultInADifferentAdministration()

	/**
	 * Promoting an id that does not exist throws.
	 *
	 * @return void
	 */
	public function testPromotingUnknownScenarioThrows(): void {
		[$promoter] = $this->buildPromoter([]);

		$this->expectException(RuntimeException::class);
		$promoter->promote(scenarioId: 'does-not-exist');

	}//end testPromotingUnknownScenarioThrows()

	/**
	 * The verification-mismatch logging path: a race where, by the time the
	 * post-promotion re-read runs, TWO scenarios carry isDefault=true for
	 * the administration (simulating a second, concurrent promote() call
	 * that wrote its own default between this call's demotion and its own
	 * verification read). `verified` is false and an error is logged rather
	 * than silently resolved (design.md §3b).
	 *
	 * @return void
	 */
	public function testLogsVerificationMismatchOnConcurrentPromotionRace(): void {
		$existing = [
			[
				'id' => 'scn-a',
				'administrationId' => 'adm-1',
				'name' => 'Scenario A',
				'isDefault' => false,
				'status' => 'draft',
			],
		];

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$racyStore = new RacingDefaultObjectServiceDecorator(
			inner: new InMemoryObjectServiceStub(['BudgetScenario' => $existing]),
			racerId: 'scn-racer',
			administrationId: 'adm-1',
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');

		$promoter = new BudgetScenarioDefaultPromoter(
			appConfig: $appConfig,
			logger: $logger,
			objectService: $racyStore,
		);

		$outcome = $promoter->promote(scenarioId: 'scn-a');

		$this->assertFalse($outcome['verified']);
		$this->assertSame(2, $outcome['defaultCount']);

	}//end testLogsVerificationMismatchOnConcurrentPromotionRace()

	/**
	 * REGRESSION: findAll() hands back ObjectEntity INSTANCES, not arrays.
	 *
	 * Every other test here passes an InMemoryObjectServiceStub whose
	 * findAll() returns plain arrays — which is why this defect shipped green.
	 * The real ObjectService routes its rows through
	 * RenderObject::renderEntities(), whose per-row renderEntity() is declared
	 * `): ObjectEntity`, so production hands the promoter objects where the
	 * stub hands it arrays. findCurrentDefault() is declared `: ?array`, so
	 * PHP throws on the return itself —
	 *
	 *   TypeError: findCurrentDefault(): Return value must be of type ?array,
	 *   ObjectEntity returned
	 *
	 * — and BudgetScenarioController's `catch (Throwable)` turns that into a
	 * 500.
	 *
	 * It only ever fired on a promotion that had to DEMOTE a previous default:
	 * the first promotion in an administration has nothing to demote. The e2e
	 * trace showed exactly that pair — 200 with `demotedScenarioId: null`,
	 * then 500 on the next promotion.
	 *
	 * This test drives findAll() through the ENTITY shape production actually
	 * produces. It fails with the TypeError before the fix.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-002
	 */
	public function testDemotesAPreviousDefaultWhenFindAllReturnsEntities(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$rows = [
			['id' => 'scn-a', 'administrationId' => 'ADM-001', 'name' => 'A', 'isDefault' => true, 'status' => 'active'],
			['id' => 'scn-b', 'administrationId' => 'ADM-001', 'name' => 'B', 'isDefault' => false, 'status' => 'draft'],
		];

		$entity = static fn (array $row): ObjectEntityStub => new ObjectEntityStub(
			payload: $row,
			register: 'shillinq',
			schema: 'BudgetScenario',
		);

		$saved = [];
		$store = $this->createMock(ObjectServiceInterface::class);
		$store->method('setRegister')->willReturnSelf();
		$store->method('setSchema')->willReturnSelf();
		$store->method('find')->willReturnCallback(
			static function (int|string $id) use ($rows, $entity): ?ObjectEntityStub {
				foreach ($rows as $row) {
					if ($row['id'] === (string)$id) {
						return $entity($row);
					}
				}

				return null;
			}
		);
		// THE POINT OF THIS TEST: entities, exactly as ObjectService returns.
		$store->method('findAll')->willReturnCallback(
			static function () use (&$saved, $rows, $entity): array {
				$defaults = [];
				foreach ($rows as $row) {
					$current = ($saved[$row['id']] ?? $row);
					if (($current['isDefault'] ?? false) === true) {
						$defaults[] = $entity($current);
					}
				}

				return $defaults;
			}
		);
		$store->method('saveObject')->willReturnCallback(
			static function (array $object) use (&$saved, $entity): ObjectEntityStub {
				$saved[(string)($object['id'] ?? '')] = $object;

				return $entity($object);
			}
		);

		$promoter = new BudgetScenarioDefaultPromoter(
			appConfig: $appConfig,
			logger: new NullLogger(),
			objectService: $store,
		);

		$outcome = $promoter->promote(scenarioId: 'scn-b');

		$this->assertSame('scn-b', $outcome['scenarioId']);
		$this->assertSame(
			'scn-a',
			$outcome['demotedScenarioId'],
			'the previous default must be demoted even when findAll returns entities',
		);
		$this->assertTrue($outcome['verified']);
		$this->assertSame(1, $outcome['defaultCount']);

	}//end testDemotesAPreviousDefaultWhenFindAllReturnsEntities()
}//end class
