<?php

/**
 * Shared fixture for the reserve and interest tests: Gemeente Voorbeeld 2026
 * from the design's seed data, an in-memory register and a transition engine
 * that runs the actions the register declares.
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\PublicSector
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-programmabegroting/spec.md
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\PublicSector;

use OCA\Shillinq\Lifecycle\Action\InterestAllocationAction;
use OCA\Shillinq\Lifecycle\Action\RealiseReserveMutationAction;
use OCA\Shillinq\Service\KapitaallastenCalculator;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\PublicSector\InterestAllocationService;
use OCA\Shillinq\Service\PublicSector\PublicSectorRecords;
use OCA\Shillinq\Service\PublicSector\ReserveBalances;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Bank\LifecycleFaithfulTransitionEngine;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use Psr\Container\ContainerInterface;

trait PublicSectorFixture {
	private InMemoryObjectServiceStub $store;

	private LifecycleFaithfulTransitionEngine $engine;

	private array $ids = [
		'general'     => '11111111-0001-4000-8000-000000000001',
		'maintenance' => '11111111-0002-4000-8000-000000000002',
		'sporthal'    => '22222222-0001-4000-8000-000000000001',
		'withdrawal'  => '33333333-0088-4000-8000-000000000088',
		'run'         => '44444444-2026-4000-8000-000000002026',
	];

	private function seed(array $mutations = []): void {
		$this->store = new InMemoryObjectServiceStub(
			[
				'Reserve'               => [
					['id' => $this->ids['general'], 'administrationId' => 'adm-gov-1', 'budgetId' => 'pb-gem-2026', 'name' => 'Algemene reserve', 'kind' => 'algemeen', 'type' => 'algemene-reserve', 'openingBalance' => 12000000, 'closingBalance' => 12000000, 'openingBalanceYear' => 2026, 'rentetoerekening' => false, 'balanceAccountNumber' => '0510', 'resultAccountNumber' => '8990'],
					['id' => $this->ids['maintenance'], 'administrationId' => 'adm-gov-1', 'budgetId' => 'pb-gem-2026', 'name' => 'Reserve onderhoud sportaccommodaties', 'kind' => 'bestemming', 'type' => 'bestemmingsreserve', 'openingBalance' => 800000, 'closingBalance' => 800000, 'openingBalanceYear' => 2026, 'rentetoerekening' => true, 'floorCents' => 10000000, 'plafondCents' => 150000000, 'balanceAccountNumber' => '0520', 'resultAccountNumber' => '8990'],
				],
				'ReserveMutation'       => $mutations,
				'Investering'           => [
					['id' => $this->ids['sporthal'], 'administrationId' => 'adm-gov-1', 'programmeId' => '5', 'description' => 'Sporthal De Wielewaal', 'gross' => 5000000, 'coverage' => 'lening', 'depreciationTerm' => 40, 'firstDepreciationYear' => 2022, 'taskFieldCode' => '5.2', 'capitalChargesSchedule' => ['2022' => 125000, '2023' => 125000, '2024' => 125000, '2025' => 125000, '2026' => 125000]],
				],
				'InterestAllocationRun' => [
					['id' => $this->ids['run'], 'administrationId' => 'adm-gov-1', 'year' => 2026, 'omslagrentePercentage' => 1.2, 'interestCostAccountNumber' => '4810', 'treasuryAccountNumber' => '8050', 'state' => 'draft'],
				],
				'JournalEntry'          => [],
			]
		);

		$balances = $this->balances();
		$realise = new RealiseReserveMutationAction($balances);
		$interest = new InterestAllocationAction($this->interest());
		$store = $this->store;
		$this->engine = new LifecycleFaithfulTransitionEngine(
			$this->store,
			['ReserveMutation', 'InterestAllocationRun', 'JournalEntry'],
			static function (array $record, array $object) use ($realise, $interest, $store): void {
				$declared = RegisterSchema::schema($record['schema'])['x-openregister-lifecycle']['transitions'][$record['action']];
				foreach (($declared['actions'] ?? []) as $step) {
					$executor = null;
					if ($step['action'] === RealiseReserveMutationAction::class) {
						$executor = $realise;
					}

					if ($step['action'] === InterestAllocationAction::class) {
						$executor = $interest;
					}

					if ($executor !== null) {
						$object = $executor->execute($object, [], ($step['actionParameters'] ?? []), $step['action']);
						$store->setSchema($record['schema'])->saveObject($object);
					}
				}
			}
		);

	}//end seed()

	private function records(): PublicSectorRecords {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		return new PublicSectorRecords($this->store, $this->runner(), $settings);

	}//end records()

	private function balances(): ReserveBalances {
		return new ReserveBalances($this->records());

	}//end balances()

	private function interest(): InterestAllocationService {
		return new InterestAllocationService($this->records(), $this->balances(), new KapitaallastenCalculator());

	}//end interest()

	private function runner(): ObjectTransitionRunner {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(true);
		$container->method('get')->willReturnCallback(fn (): object => $this->engine);
		return new ObjectTransitionRunner(container: $container);

	}//end runner()

	private function get(string $schema, string $id): array {
		return $this->store->setSchema($schema)->find($id)->getObject();

	}//end get()

	private function all(string $schema): array {
		return $this->store->setSchema($schema)->findAll();

	}//end all()

	/**
	 * Errors of a payload against the real merged register schema; the stub's
	 * obj-N ids stand in for uuids.
	 */
	private function registerErrors(string $schema, array $row): array {
		unset($row['id']);
		foreach ($row as $key => $value) {
			if (is_string($value) === true && preg_match('/^obj-\d+$/', $value) === 1) {
				$row[$key] = sprintf('00000000-0000-4000-8000-%012d', (int)substr($value, 4));
			}
		}

		return RegisterSchema::errors($schema, $row);

	}//end registerErrors()
}//end trait
