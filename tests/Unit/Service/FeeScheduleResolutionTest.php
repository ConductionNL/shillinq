<?php

/**
 * Characterisation tests for FeeScheduleService resolution and overlap paths.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Shillinq\Service\FeeScheduleService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the channel choice, the amount pick, the per-object walk and the
 * overlap message of FeeScheduleService.
 */
class FeeScheduleResolutionTest extends TestCase {

	/**
	 * The service over a fixed FeeSchedule row set.
	 *
	 * @param array<int, mixed> $rows The FeeSchedule rows.
	 *
	 * @return FeeScheduleService The service.
	 */
	private function service(array $rows): FeeScheduleService {
		$double = new class($rows) {
			/**
			 * @param array<int, mixed> $rows Rows.
			 */
			public function __construct(private array $rows) {
			}

			/**
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}

			/**
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				return $this;
			}

			/**
			 * @param array<string, mixed> $params Query params.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $params = []): array {
				return $this->rows;
			}
		};

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		return new FeeScheduleService(
			objectService: new DuckObjectServiceAdapter(inner: $double),
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end service()

	/**
	 * One schedule row for the bouwvergunning tuple.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function row(array $overrides = []): array {
		return array_merge(
			[
				'targetApp' => 'dossiq',
				'register' => 'dossiq',
				'schema' => 'Zaak',
				'typeProperty' => 'caseType',
				'typeValue' => 'bouwvergunning',
				'id' => 'fs-1',
				'legalBasis' => ['regulation' => 'Legesverordening 2026', 'article' => '2.3.1', 'effectiveDate' => '2026-01-01'],
				'amount' => 245.0,
				'currency' => 'EUR',
				'validFrom' => '2026-01-01',
				'validTo' => '2026-12-31',
				'intakeChannel' => '',
			],
			$overrides
		);
	}//end row()

	/**
	 * The tuple a lookup asks with.
	 *
	 * @return array<string, string> The tuple.
	 */
	private function tuple(): array {
		return [
			'targetApp' => 'dossiq',
			'register' => 'dossiq',
			'schema' => 'Zaak',
			'typeProperty' => 'caseType',
			'typeValue' => 'bouwvergunning',
		];
	}//end tuple()

	/**
	 * The first channel-less schedule is the default; a later exact channel
	 * still wins; an unknown channel takes the default; no default and no
	 * matching channel resolves to null.
	 *
	 * @return void
	 */
	public function testResolvePicksTheChannelThenTheFirstDefault(): void {
		$rows = [
			$this->row(overrides: ['id' => 'a', 'amount' => 1.0]),
			$this->row(overrides: ['id' => 'b', 'amount' => 2.0]),
			$this->row(overrides: ['id' => 'c', 'amount' => 3.0, 'intakeChannel' => 'desk']),
			$this->row(overrides: ['id' => 'x', 'amount' => 9.0, 'validFrom' => '2027-01-01', 'validTo' => '', 'intakeChannel' => 'web']),
		];
		$service = $this->service(rows: $rows);

		self::assertSame('c', $service->resolve(tuple: $this->tuple(), intakeChannel: 'desk', onDate: '2026-06-01')['id']);
		self::assertSame('a', $service->resolve(tuple: $this->tuple(), intakeChannel: 'web', onDate: '2026-06-01')['id']);
		self::assertSame('a', $service->resolve(tuple: $this->tuple(), intakeChannel: '', onDate: '2026-06-01')['id']);

		$onlyDesk = $this->service(rows: [$this->row(overrides: ['intakeChannel' => 'desk'])]);
		self::assertNull($onlyDesk->resolve(tuple: $this->tuple(), intakeChannel: 'web', onDate: '2026-06-01'));
		self::assertNull($onlyDesk->resolve(tuple: $this->tuple(), intakeChannel: 'desk', onDate: '2028-06-01'));

	}//end testResolvePicksTheChannelThenTheFirstDefault()

	/**
	 * Every branch of applyChannelAmount, field by field.
	 *
	 * @return void
	 */
	public function testApplyChannelAmountBranches(): void {
		$service = $this->service(rows: []);

		self::assertSame(
			['amount' => 5.0, 'amounts' => [], 'amountChannel' => ''],
			$service->applyChannelAmount(schedule: ['amount' => 5.0, 'amounts' => []], intakeChannel: 'desk')
		);
		self::assertSame(
			['amounts' => 'x', 'amountChannel' => ''],
			$service->applyChannelAmount(schedule: ['amounts' => 'x'], intakeChannel: '')
		);

		$amounts = [
			'junk',
			['intakeChannel' => 'web', 'amount' => 7],
			['amount' => '10.5'],
			['amount' => 11, 'currency' => 'USD'],
			['intakeChannel' => 'desk', 'amount' => 4, 'currency' => 'GBP'],
			['intakeChannel' => 'desk', 'amount' => 99],
		];
		$schedule = ['amount' => 1.0, 'currency' => 'CHF', 'amounts' => $amounts];

		self::assertSame(
			['amount' => 4.0, 'currency' => 'GBP', 'amounts' => $amounts, 'amountChannel' => 'desk'],
			$service->applyChannelAmount(schedule: $schedule, intakeChannel: 'desk')
		);
		self::assertSame(
			['amount' => 10.5, 'currency' => 'CHF', 'amounts' => $amounts, 'amountChannel' => ''],
			$service->applyChannelAmount(schedule: $schedule, intakeChannel: 'post')
		);
		self::assertSame(
			['amount' => 10.5, 'amounts' => [['amount' => '10.5']], 'currency' => 'EUR', 'amountChannel' => ''],
			$service->applyChannelAmount(schedule: ['amount' => 1.0, 'amounts' => [['amount' => '10.5']]], intakeChannel: '')
		);
		self::assertSame(
			['currency' => 'CHF', 'amounts' => [['intakeChannel' => 'web', 'amount' => 7]], 'amountChannel' => 'none'],
			$service->applyChannelAmount(
				schedule: ['amount' => 1.0, 'currency' => 'CHF', 'amounts' => [['intakeChannel' => 'web', 'amount' => 7]]],
				intakeChannel: ''
			)
		);

	}//end testApplyChannelAmountBranches()

	/**
	 * resolveForObject skips rows of another register or schema, rows without
	 * a type property, a type property it already tried and an object without
	 * that property's value; the variant schedule wins over the type's.
	 *
	 * @return void
	 */
	public function testResolveForObjectWalksTheRows(): void {
		$rows = [
			'not-a-row',
			$this->row(overrides: ['id' => 'other-schema', 'schema' => 'Melding']),
			$this->row(overrides: ['id' => 'other-register', 'register' => 'elders']),
			$this->row(overrides: ['id' => 'no-type', 'typeProperty' => '']),
			$this->row(overrides: ['id' => 'missing', 'typeProperty' => 'subtype']),
			$this->row(overrides: ['id' => 'type', 'amount' => 100.0]),
			$this->row(overrides: ['id' => 'repeat', 'amount' => 300.0, 'variantProperty' => 'kind']),
			$this->row(overrides: ['id' => 'variant', 'amount' => 150.0, 'variantValue' => 'klein']),
		];
		$service = $this->service(rows: $rows);

		$plain = $service->resolveForObject('dossiq', 'Zaak', ['caseType' => 'bouwvergunning'], '', '2026-06-01');
		self::assertSame('type', $plain['id']);
		self::assertSame(100.0, $plain['amount']);

		$variant = $service->resolveForObject('dossiq', 'Zaak', ['caseType' => 'bouwvergunning', 'variant' => 'klein'], '', '2026-06-01');
		self::assertSame('variant', $variant['id']);

		$noVariantMatch = $service->resolveForObject('dossiq', 'Zaak', ['caseType' => 'bouwvergunning', 'variant' => 'groot'], '', '2026-06-01');
		self::assertSame('type', $noVariantMatch['id']);

		self::assertNull($service->resolveForObject('dossiq', 'Zaak', ['caseType' => 'kapvergunning'], '', '2026-06-01'));
		self::assertNull($service->resolveForObject('dossiq', 'Zaak', [], '', '2026-06-01'));

	}//end testResolveForObjectWalksTheRows()

	/**
	 * The overlap message names the window end and the id when they exist, and
	 * leaves them out otherwise; the schedule itself is never its own overlap.
	 *
	 * @return void
	 */
	public function testOverlapMessages(): void {
		$service = $this->service(rows: []);
		$new     = $this->row(overrides: ['id' => 'fs-new', 'validFrom' => '2026-06-01', 'validTo' => '']);

		$messages = [];
		foreach ([
			[$this->row()],
			[$this->row(overrides: ['id' => '', 'validTo' => ''])],
			[
				$this->row(overrides: ['id' => 'fs-new']),
				$this->row(overrides: ['id' => 'fs-desk', 'intakeChannel' => 'desk']),
				$this->row(overrides: ['id' => 'fs-9', 'validTo' => '']),
			],
		] as $existing) {
			try {
				$service->assertNoOverlap(schedule: $new, existing: $existing);
				$messages[] = 'accepted';
			} catch (InvalidArgumentException $e) {
				$messages[] = $e->getMessage();
			}
		}

		self::assertSame(
			[
				'This fee overlaps the schedule valid from 2026-01-01 to 2026-12-31 (fs-1); close that one before opening this.',
				'This fee overlaps the schedule valid from 2026-01-01; close that one before opening this.',
				'This fee overlaps the schedule valid from 2026-01-01 (fs-9); close that one before opening this.',
			],
			$messages
		);

		$before = [$this->row(overrides: ['id' => 'fs-new']), $this->row(overrides: ['validTo' => '2026-05-31'])];
		$service->assertNoOverlap(schedule: $new, existing: $before);
		$this->expectExceptionMessage('A fee schedule needs a validFrom');
		$service->assertNoOverlap(schedule: $this->row(overrides: ['validFrom' => '']), existing: []);

	}//end testOverlapMessages()
	/**
	 * assertAmountsHaveADefault accepts a flat fee and exactly one default
	 * entry, and names the channels when the default is missing.
	 *
	 * @return void
	 */
	public function testAmountsNeedExactlyOneDefault(): void {
		$service  = $this->service(rows: []);
		$messages = [];
		foreach ([
			['amount' => 5.0],
			['amounts' => []],
			['amounts' => 'x'],
			['amounts' => ['junk', ['amount' => 1], ['intakeChannel' => 'web', 'amount' => 2]]],
			['amounts' => [['intakeChannel' => 'web', 'amount' => 2], 'junk', ['intakeChannel' => 'desk', 'amount' => 3]]],
			['amounts' => [['amount' => 1], ['intakeChannel' => '', 'amount' => 2]]],
		] as $schedule) {
			try {
				$service->assertAmountsHaveADefault(schedule: $schedule);
				$messages[] = 'accepted';
			} catch (InvalidArgumentException $e) {
				$messages[] = $e->getMessage();
			}
		}

		self::assertSame(
			[
				'accepted',
				'accepted',
				'accepted',
				'accepted',
				'This fee names only web, desk and no default, so every other channel would resolve to no fee at all. Add an entry without an intakeChannel.',
				'This fee names more than one default amount; only one entry may omit its intakeChannel.',
			],
			$messages
		);

	}//end testAmountsNeedExactlyOneDefault()
}//end class
