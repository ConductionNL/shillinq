<?php

/**
 * assets-method-change-and-reserve: the oven of Bakkerij Jansen from the
 * design's seed data, posted monthly, revised, depreciated extra, and the
 * reinvestment reserve of the old van applied to the new one.
 *
 * Real sibling classes throughout: the planner, the schedule service, the
 * actions the register declares, an in-memory register and a transition
 * engine that refuses what the lifecycle does not allow. Every written line,
 * journal entry and reserve is validated against the real merged register.
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Asset
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Asset;

use DateTimeImmutable;
use DomainException;
use OCA\Shillinq\Lifecycle\Action\ApplyReinvestmentReserveAction;
use OCA\Shillinq\Lifecycle\Action\ExtraDepreciationAction;
use OCA\Shillinq\Lifecycle\Action\ReviseDepreciationAction;
use OCA\Shillinq\Service\Asset\AssetRecords;
use OCA\Shillinq\Service\Asset\DepreciationPlanner;
use OCA\Shillinq\Service\Asset\DepreciationRun;
use OCA\Shillinq\Service\Asset\DepreciationScheduleService;
use OCA\Shillinq\Service\Asset\FixedAssetDepreciation;
use OCA\Shillinq\Service\Asset\ReinvestmentReserves;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Bank\LifecycleFaithfulTransitionEngine;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

class AssetsMethodChangeAndReserveTest extends TestCase {

	private const OVEN = '0a0e0000-0000-4000-8000-000000000001';

	private const NEW_VAN = '0a0e0000-0000-4000-8000-000000000002';

	private const RESERVE = '0a0e0000-0000-4000-8000-000000000003';

	private const ADMIN = 'adm-bakkerij-jansen';

	private InMemoryObjectServiceStub $store;

	private LifecycleFaithfulTransitionEngine $engine;

	protected function setUp(): void {
		parent::setUp();
		$this->store = new InMemoryObjectServiceStub(
			[
				'FixedAsset'          => [
					['id' => self::OVEN, 'assetNumber' => 'FA-2024-001', 'name' => 'Rademaker deegverwerker', 'assetCategory' => 'machinery', 'acquisitionDate' => '2024-01-01', 'acquisitionCost' => 60000, 'currency' => 'EUR', 'usefulLifeMonths' => 120, 'residualValue' => 0, 'depreciationMethod' => 'linear', 'assetAccountNumber' => '0300', 'accumulatedDepAccountNumber' => '0309', 'depreciationExpenseAccountNumber' => '4810', 'lifecycleState' => 'active', 'status' => 'active', 'administrationId' => self::ADMIN],
					['id' => self::NEW_VAN, 'assetNumber' => 'FA-2026-007', 'name' => 'Bestelbus', 'assetCategory' => 'vehicles', 'acquisitionDate' => '2026-09-15', 'acquisitionCost' => 42000, 'currency' => 'EUR', 'usefulLifeMonths' => 60, 'residualValue' => 2000, 'depreciationMethod' => 'linear', 'assetAccountNumber' => '0320', 'accumulatedDepAccountNumber' => '0329', 'depreciationExpenseAccountNumber' => '4820', 'lifecycleState' => 'active', 'status' => 'active', 'administrationId' => self::ADMIN],
				],
				'DepreciationSchedule' => [],
				'ReinvestmentReserve'  => [
					['id' => self::RESERVE, 'administrationId' => self::ADMIN, 'disposedAssetNumber' => 'FA-2019-004', 'formedOn' => '2026-03-31', 'amount' => 6000, 'expiresOn' => '2029-12-31', 'appliedAmount' => 0, 'remainder' => 6000, 'applications' => [], 'reserveAccountNumber' => '0640', 'lifecycleState' => 'open'],
				],
				'JournalEntry'         => [],
			]
		);

		$depreciation = $this->depreciation();
		$reserves = $this->reserves();
		$executors = [
			ReviseDepreciationAction::class       => new ReviseDepreciationAction($depreciation),
			ExtraDepreciationAction::class        => new ExtraDepreciationAction($depreciation),
			ApplyReinvestmentReserveAction::class => new ApplyReinvestmentReserveAction($reserves),
		];
		$store = $this->store;
		$this->engine = new LifecycleFaithfulTransitionEngine(
			$this->store,
			['FixedAsset', 'JournalEntry', 'ReinvestmentReserve'],
			static function (array $record, array $object) use ($executors, $store): void {
				$declared = RegisterSchema::schema($record['schema'])['x-openregister-lifecycle']['transitions'][$record['action']];
				foreach (($declared['actions'] ?? []) as $step) {
					if (isset($executors[$step['action']]) === true) {
						$object = $executors[$step['action']]->execute($object, [], ($step['actionParameters'] ?? []), $step['action']);
						$store->setSchema($record['schema'])->saveObject($object);
					}
				}
			}
		);

	}//end setUp()

	/**
	 * The three FixedAsset transitions are declared on the merged lifecycle with their inputs and executors.
	 */
	public function testTheTransitionsDeclareTheirInputsAndExecutors(): void {
		$transitions = RegisterSchema::schema('FixedAsset')['x-openregister-lifecycle']['transitions'];
		$expect = [
			'revise'                   => [ReviseDepreciationAction::class, ['revisionDate', 'revisedMethod', 'revisedUsefulLifeMonths', 'revisionReason']],
			'depreciateExtra'          => [ExtraDepreciationAction::class, ['extraDepreciationAmount', 'extraDepreciationDate', 'extraDepreciationReason']],
			'applyReinvestmentReserve' => [ApplyReinvestmentReserveAction::class, ['reinvestmentReserveId', 'reserveAmountApplied']],
		];
		foreach ($expect as $name => [$action, $inputs]) {
			$this->assertSame(['active'], (array)$transitions[$name]['from'], $name);
			$this->assertSame('active', $transitions[$name]['to'], $name);
			$this->assertSame($action, $transitions[$name]['actions'][0]['action'], $name);
			$this->assertSame($inputs, array_column($transitions[$name]['inputs'], 'field'), $name);
			foreach ($inputs as $field) {
				$this->assertArrayHasKey($field, RegisterSchema::schema('FixedAsset')['properties'], $field);
			}
		}

		$this->assertContains('disposalGainToReserve', array_column($transitions['dispose']['inputs'], 'field'));
		$calculations = RegisterSchema::schema('DepreciationSchedule')['x-openregister-calculations'];
		$this->assertFalse($calculations['depreciationAmount']['enabled'], 'A written line amount must survive the save.');

	}//end testTheTransitionsDeclareTheirInputsAndExecutors()

	/**
	 * An active asset without lines gets its whole plan, each line valid against the real schema.
	 */
	public function testTheOvenGetsItsWholePlanFromAcquisition(): void {
		$lines = $this->schedules()->ensureSchedule($this->asset(self::OVEN));

		$this->assertCount(120, $lines);
		$this->assertSame('2024-01-01', $lines[0]['periodStartDate']);
		$this->assertSame('2033-12-31', $lines[119]['periodEndDate']);
		$this->assertSame([500.0], array_values(array_unique(array_map('floatval', array_column($lines, 'depreciationAmount')))));
		$this->assertEquals(60000, $lines[119]['accumulatedDepreciation']);
		$this->assertSame([], $this->errors('DepreciationSchedule', $lines[0]));
		$this->assertCount(120, $this->schedules()->ensureSchedule($this->asset(self::OVEN)), 'A second call writes nothing.');

	}//end testTheOvenGetsItsWholePlanFromAcquisition()

	/**
	 * REQ-AMCR-002 "A controller shortens the oven's life": 750 a month from July 2026, earlier lines unchanged.
	 */
	public function testAControllerShortensTheOvensLife(): void {
		$before = $this->schedules()->ensureSchedule($this->asset(self::OVEN));
		$earlier = array_slice($before, 0, 30);

		$this->transition(self::OVEN, 'revise', ['revisionDate' => '2026-07-01', 'revisedUsefulLifeMonths' => 90, 'revisionReason' => 'slijtage door nachtproductie']);

		$lines = $this->schedules()->lines(self::OVEN);
		$this->assertCount(90, $lines);
		$this->assertSame(array_column($earlier, 'id'), array_column(array_slice($lines, 0, 30), 'id'));
		$this->assertSame(array_fill(0, 30, 500.0), array_map('floatval', array_column(array_slice($lines, 0, 30), 'depreciationAmount')));
		$july = $lines[30];
		$this->assertSame('2026-07-01', $july['periodStartDate']);
		$this->assertEquals(750, $july['depreciationAmount']);
		$this->assertSame('slijtage door nachtproductie', $july['reason']);
		$this->assertEquals(60000, $lines[89]['accumulatedDepreciation']);
		$this->assertSame([], $this->errors('DepreciationSchedule', $july));

		$oven = $this->asset(self::OVEN);
		$this->assertSame(90, $oven['usefulLifeMonths']);
		$this->assertEquals(750, $oven['monthlyDepreciation']);
		$this->assertEquals(45000, $oven['currentBookValue']);
		$this->assertSame([], $this->errors('FixedAsset', $oven));

	}//end testAControllerShortensTheOvensLife()

	/**
	 * REQ-AMCR-002: degressive and units of production replan from the same book value.
	 */
	public function testDegressiveAndUnitsOfProductionReplanFromTheBookValue(): void {
		$this->schedules()->ensureSchedule($this->asset(self::OVEN));
		$asset = $this->asset(self::OVEN) + ['degressiveRate' => 0.2];

		$this->schedules()->revise($asset, '2026-07-01', 'degressive', 90, 'naar degressief');
		$lines = $this->schedules()->lines(self::OVEN);
		$this->assertEquals(750, $lines[30]['depreciationAmount'], '45,000 x 20% / 12');
		$this->assertLessThan(750, (float)$lines[31]['depreciationAmount']);
		$this->assertSame('declining-balance', $lines[30]['depreciationMethod']);
		$this->assertEquals(60000, $lines[count($lines) - 1]['accumulatedDepreciation'], 'The last month brings it to the residual value.');

		$this->schedules()->revise($asset, '2026-07-01', 'units-of-production', 90, 'naar productie-eenheden');
		$lines = $this->schedules()->lines(self::OVEN);
		$this->assertCount(90, $lines);
		$this->assertEquals(750, $lines[30]['depreciationAmount']);
		$this->assertSame('units-of-production', $lines[30]['depreciationMethod']);

	}//end testDegressiveAndUnitsOfProductionReplanFromTheBookValue()

	/**
	 * REQ-AMCR-002: posted lines MUST NOT change, so a revision cannot start before a posted month.
	 */
	public function testARevisionCannotStartBeforeAPostedMonth(): void {
		$this->schedules()->ensureSchedule($this->asset(self::OVEN));
		$this->runOn('2026-08-01');

		$this->expectException(DomainException::class);
		$this->expectExceptionMessage('2026-07');
		$this->schedules()->revise($this->asset(self::OVEN), '2026-06-01', 'linear', 90, 'te laat');

	}//end testARevisionCannotStartBeforeAPostedMonth()

	/**
	 * REQ-AMCR-001 "September's depreciation is posted", and a second run posts nothing.
	 */
	public function testSeptembersDepreciationIsPosted(): void {
		$this->schedules()->ensureSchedule($this->asset(self::OVEN));
		$this->transition(self::OVEN, 'revise', ['revisionDate' => '2026-07-01', 'revisedUsefulLifeMonths' => 90, 'revisionReason' => 'slijtage door nachtproductie']);
		$this->store->setSchema('DepreciationSchedule');
		$this->dropNewVan();

		$result = $this->runOn('2026-10-01');

		$this->assertSame('2026-09', $result['month']);
		$this->assertSame(1, $result['journals']);
		$journals = $this->all('JournalEntry');
		$this->assertCount(1, $journals);
		$journal = $journals[0];
		$this->assertSame('posted', $journal['state'], 'postDirect ran.');
		$this->assertSame('2026-09-30', $journal['entryDate']);
		$this->assertSame([['4810', 'debit', 750.0], ['0309', 'credit', 750.0]], array_map(static fn (array $line): array => [$line['accountNumber'], $line['side'], (float)$line['amount']], $journal['lines']));
		$this->assertSame([], $this->errors('JournalEntry', ['state' => 'draft'] + $journal));

		$september = $this->lineFor('2026-09');
		$this->assertSame('posted', $september['status']);
		$this->assertSame($journal['id'], $september['glTransactionRef']);
		$this->assertSame([], $this->errors('DepreciationSchedule', $september));

		$again = $this->runOn('2026-10-01');
		$this->assertSame(0, $again['journals']);
		$this->assertCount(1, $this->all('JournalEntry'));

	}//end testSeptembersDepreciationIsPosted()

	/**
	 * REQ-AMCR-001: earlier months are listed with their amounts and posted only on request.
	 */
	public function testMissedMonthsAreListedAndPostedOnRequest(): void {
		$this->dropNewVan();
		$this->runOn('2026-10-01');
		$oven = $this->asset(self::OVEN);

		$missed = $this->schedules()->missed($oven, '2026-10');
		$this->assertCount(32, $missed, 'January 2024 to August 2026.');
		$this->assertSame(['period' => '2024-01', 'amount' => 500.0], ['period' => $missed[0]['period'], 'amount' => $missed[0]['amount']]);
		$this->assertCount(1, $this->all('JournalEntry'), 'The list posts nothing.');

		$this->schedules()->postMissed($oven, '2026-10');
		$this->assertCount(33, $this->all('JournalEntry'), 'One per missed month.');
		$this->assertSame([], $this->schedules()->missed($oven, '2026-10'));

	}//end testMissedMonthsAreListedAndPostedOnRequest()

	/**
	 * REQ-AMCR-003 "Water damage lowers the oven's value".
	 */
	public function testWaterDamageLowersTheOvensValue(): void {
		$this->dropNewVan();
		$this->schedules()->ensureSchedule($this->asset(self::OVEN));
		$this->transition(self::OVEN, 'revise', ['revisionDate' => '2026-07-01', 'revisedUsefulLifeMonths' => 90, 'revisionReason' => 'slijtage door nachtproductie']);
		$this->assertEquals(42750, $this->depreciation()->withScheduleFigures($this->asset(self::OVEN), '2026-10-01')['currentBookValue']);

		$this->transition(self::OVEN, 'depreciateExtra', ['extraDepreciationAmount' => 5000, 'extraDepreciationDate' => '2026-10-15', 'extraDepreciationReason' => 'waterschade']);

		$extra = array_values(array_filter($this->schedules()->lines(self::OVEN), static fn (array $line): bool => $line['rateType'] === 'extra'));
		$this->assertCount(1, $extra);
		$this->assertSame('posted', $extra[0]['status']);
		$this->assertEquals(5000, $extra[0]['depreciationAmount']);
		$this->assertSame([], $this->errors('DepreciationSchedule', $extra[0]));
		$journal = $this->all('JournalEntry')[0];
		$this->assertSame('posted', $journal['state']);
		$this->assertEquals(5000, $journal['lines'][0]['amount']);

		$october = $this->lineFor('2026-10');
		$this->assertEquals(662.28, $october['depreciationAmount'], '37,750 over the 57 months left.');
		$this->assertLessThan(750, (float)$this->asset(self::OVEN)['monthlyDepreciation']);
		$all = $this->schedules()->lines(self::OVEN);
		$this->assertEquals(60000, $all[count($all) - 1]['accumulatedDepreciation']);

	}//end testWaterDamageLowersTheOvensValue()

	/**
	 * REQ-AMCR-003: an extra depreciation larger than what is left is refused, and so is one without a reason.
	 */
	public function testAnExtraDepreciationBeyondTheBookValueIsRefused(): void {
		$this->schedules()->ensureSchedule($this->asset(self::OVEN));

		try {
			$this->schedules()->extra($this->asset(self::OVEN), 70000, '2026-10-15', 'brand');
			$this->fail('70,000 on a 60,000 asset was booked.');
		} catch (DomainException $e) {
			$this->assertStringContainsString('left to depreciate', $e->getMessage());
		}

		$this->expectException(DomainException::class);
		$this->schedules()->extra($this->asset(self::OVEN), 100, '2026-10-15', ' ');

	}//end testAnExtraDepreciationBeyondTheBookValueIsRefused()

	/**
	 * REQ-AMCR-005 "The reserve pays for part of the new van", then a partial application elsewhere.
	 */
	public function testTheReservePaysForPartOfTheNewVan(): void {
		$this->transition(self::NEW_VAN, 'applyReinvestmentReserve', ['reinvestmentReserveId' => self::RESERVE]);

		$van = $this->asset(self::NEW_VAN);
		$this->assertEquals(36000, $van['fiscalCostBasis']);
		$this->assertSame([], $this->errors('FixedAsset', $van));
		$reserve = $this->get('ReinvestmentReserve', self::RESERVE);
		$this->assertSame('applied', $reserve['lifecycleState']);
		$this->assertEquals(6000, $reserve['appliedAmount']);
		$this->assertSame(self::NEW_VAN, $reserve['applications'][0]['assetRef']);
		$this->assertSame([], $this->errors('ReinvestmentReserve', $reserve));
		$journal = $this->all('JournalEntry')[0];
		$this->assertSame([['0640', 'debit', 6000.0], ['0320', 'credit', 6000.0]], array_map(static fn (array $line): array => [$line['accountNumber'], $line['side'], (float)$line['amount']], $journal['lines']));
		$this->assertSame('posted', $journal['state']);

		$this->expectException(DomainException::class);
		$this->expectExceptionMessage('open reinvestment reserve');
		$this->reserves()->apply($this->asset(self::OVEN) + ['reinvestmentReserveId' => self::RESERVE]);

	}//end testTheReservePaysForPartOfTheNewVan()

	/**
	 * REQ-AMCR-005: a partial application leaves the reserve open with its remainder.
	 */
	public function testAPartialApplicationLeavesTheRemainderOpen(): void {
		$van = $this->reserves()->apply($this->asset(self::NEW_VAN) + ['reinvestmentReserveId' => self::RESERVE, 'reserveAmountApplied' => 2500]);

		$this->assertEquals(39500, $van['fiscalCostBasis']);
		$reserve = $this->get('ReinvestmentReserve', self::RESERVE);
		$this->assertSame('open', $reserve['lifecycleState']);
		$this->assertEquals(3500, $reserve['remainder']);

	}//end testAPartialApplicationLeavesTheRemainderOpen()

	/**
	 * REQ-AMCR-005: an open reserve past its expiry is released to profit by the run; one in its term is not.
	 */
	public function testAnExpiredReserveIsReleasedByTheRun(): void {
		$this->dropNewVan();
		$this->store->setSchema('FixedAsset');
		$this->assertSame(0, $this->runOn('2029-12-31')['released']);

		$result = $this->runOn('2030-01-02');

		$this->assertSame(1, $result['released']);
		$reserve = $this->get('ReinvestmentReserve', self::RESERVE);
		$this->assertSame('released', $reserve['lifecycleState']);
		$release = array_values(array_filter($this->all('JournalEntry'), static fn (array $journal): bool => $journal['id'] === $reserve['releaseJournalEntryId']));
		$this->assertSame([['0640', 'debit', 6000.0], ['8000', 'credit', 6000.0]], array_map(static fn (array $line): array => [$line['accountNumber'], $line['side'], (float)$line['amount']], $release[0]['lines']));

	}//end testAnExpiredReserveIsReleasedByTheRun()

	/**
	 * A reserve formed by a disposal expires at the end of the third year after it was formed.
	 */
	public function testAReserveExpiresAtTheEndOfTheThirdYear(): void {
		$reserve = $this->reserves()->form(['id' => 'old-van', 'assetNumber' => 'FA-2019-004', 'administrationId' => self::ADMIN], 6000.0, '2026-03-31');

		$this->assertSame('2029-12-31', $reserve['expiresOn']);
		$this->assertEquals(6000, $reserve['amount']);
		$this->assertSame([], $this->errors('ReinvestmentReserve', $reserve));

	}//end testAReserveExpiresAtTheEndOfTheThirdYear()

	private function transition(string $id, string $action, array $inputs): void {
		$asset = $this->asset($id);
		$this->store->setSchema('FixedAsset')->saveObject(array_merge($asset, $inputs));
		$this->engine->transition($id, $action, $inputs);

	}//end transition()

	private function runOn(string $day): array {
		return (new DepreciationRun($this->schedules(), $this->depreciation(), $this->reserves(), $this->records()))->run(new DateTimeImmutable($day));

	}//end run()

	private function dropNewVan(): void {
		$van = $this->asset(self::NEW_VAN);
		$van['status'] = 'inactive';
		$this->store->setSchema('FixedAsset')->saveObject($van);

	}//end dropNewVan()

	private function lineFor(string $month): array {
		foreach ($this->schedules()->lines(self::OVEN) as $line) {
			if ($line['rateType'] !== 'extra' && substr($line['periodStartDate'], 0, 7) === $month) {
				return $line;
			}
		}

		$this->fail('No line for ' . $month);

	}//end lineFor()

	private function records(): AssetRecords {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(true);
		$container->method('get')->willReturnCallback(fn (): object => $this->engine);
		return new AssetRecords($this->store, new ObjectTransitionRunner(container: $container), $settings);

	}//end records()

	private function schedules(): DepreciationScheduleService {
		return new DepreciationScheduleService($this->records(), new DepreciationPlanner());

	}//end schedules()

	private function depreciation(): FixedAssetDepreciation {
		return new FixedAssetDepreciation($this->schedules());

	}//end depreciation()

	private function reserves(): ReinvestmentReserves {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => ['fixed_asset_disposal_gain_account' => '8000'][$key] ?? $default);
		return new ReinvestmentReserves($this->records(), $config);

	}//end reserves()

	private function asset(string $id): array {
		return $this->get('FixedAsset', $id);

	}//end asset()

	private function get(string $schema, string $id): array {
		return $this->store->setSchema($schema)->find($id)->getObject();

	}//end get()

	private function all(string $schema): array {
		return array_values(array_map(static fn (mixed $row): array => (array)$row, $this->store->setSchema($schema)->findAll()));

	}//end all()

	/**
	 * Errors against the real merged register; the stub's obj-N ids stand in for uuids.
	 */
	private function errors(string $schema, array $row): array {
		unset($row['id']);
		foreach ($row as $key => $value) {
			if (is_string($value) === true && preg_match('/^obj-\d+$/', $value) === 1) {
				$row[$key] = sprintf('00000000-0000-4000-8000-%012d', (int)substr($value, 4));
			}
		}

		if (($row['administrationId'] ?? null) === self::ADMIN && $schema === 'FixedAsset') {
			$row['administrationId'] = '0a0e0000-0000-4000-8000-0000000000ad';
		}

		return RegisterSchema::errors($schema, $row);

	}//end errors()
}//end class
