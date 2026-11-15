<?php

/**
 * Allowed/refused matrix for PeriodStatusGuard::postingAllowed().
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-19
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Lifecycle\PeriodStatusGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins every allowed and refused posting outcome, its log line and its queries (REQ-CLS-001).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PeriodStatusPostingMatrixTest extends TestCase {

	/**
	 * Log lines captured from the guard, as [level, message, context].
	 *
	 * @var list<array{0:string,1:string,2:array<string,mixed>}>
	 */
	private array $log = [];

	/**
	 * findAll() calls captured, as [schema, config].
	 *
	 * @var list<array{0:string,1:array<string,mixed>}>
	 */
	private array $queries = [];

	/**
	 * A guard whose register holds the given rows per schema.
	 *
	 * @param array<string,list<array<string,mixed>>> $rows The rows per schema.
	 *
	 * @return PeriodStatusGuard
	 */
	private function guard(array $rows): PeriodStatusGuard {
		$schema = '';
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnCallback(
			static function (string $name) use (&$schema, $objects): ObjectServiceInterface {
				$schema = $name;
				return $objects;
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $config) use (&$schema, $rows): array {
				$this->queries[] = [$schema, $config];
				if (($rows[$schema] ?? null) === 'throw') {
					throw new RuntimeException('register down');
				}

				return ($rows[$schema] ?? []);
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->log[] = [$level, $message, $context];
				}
			);
		}

		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturn('shillinq');

		return new PeriodStatusGuard($config, $logger, objectService: $objects);

	}//end guard()

	/**
	 * The posting outcome per stage, kind and override flags.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>,2:bool,3:string}>
	 */
	public static function matrix(): array {
		$soft = 'PeriodStatusGuard: posting rejected — soft-closed period accepts only accrual reversals + corrections';
		$restricted = 'PeriodStatusGuard: posting rejected — restricted stage; controllerOverride + exceptionJournal required';
		$locked = 'PeriodStatusGuard: posting rejected — period is locked';
		$both = ['controllerOverride' => true, 'exceptionJournal' => true];

		return [
			'open' => ['open', [], true, ''],
			'no stage reads as open' => ['', [], true, ''],
			'unknown stage' => ['archived', [], true, ''],
			'soft-closed regular' => ['soft-closed', [], false, $soft],
			'soft-closed reversal' => ['soft-closed', ['postingKind' => 'accrual-reversal'], true, ''],
			'soft-closed correction' => ['soft-closed', ['postingKind' => 'correction'], true, ''],
			'soft-closed ignores overrides' => ['soft-closed', $both, false, $soft],
			'hard-closed bare' => ['hard-closed', [], false, $restricted],
			'hard-closed one flag' => ['hard-closed', ['exceptionJournal' => true], false, $restricted],
			'hard-closed truthy flags' => ['hard-closed', ['controllerOverride' => 1, 'exceptionJournal' => 'yes'], false, $restricted],
			'hard-closed both' => ['hard-closed', $both, true, ''],
			'audited both' => ['audited', $both, true, ''],
			'audited reversal' => ['audited', ['postingKind' => 'correction'], false, $restricted],
			'locked both' => ['locked', $both, false, $locked],
		];

	}//end matrix()

	/**
	 * Each stage allows or refuses as pinned, with its log line.
	 *
	 * @param string              $stage    The PeriodStatus stage ('' for none).
	 * @param array<string,mixed> $extra    Transaction fields.
	 * @param bool                $allowed  The expected outcome.
	 * @param string              $message  The expected info line ('' for none).
	 *
	 * @return void
	 *
	 * @dataProvider matrix
	 */
	public function testThePostingMatrix(string $stage, array $extra, bool $allowed, string $message): void {
		$status = ['id' => 'ps-1'];
		if ($stage !== '') {
			$status['stage'] = $stage;
		}

		$transaction = $extra + ['periodId' => '2026-3', 'administrationId' => 'adm-1'];
		self::assertSame($allowed, $this->guard(['PeriodStatus' => [$status]])->postingAllowed($transaction));

		$expectedLog = [];
		if ($message !== '') {
			$context = ['periodId' => '2026-3', 'stage' => $stage];
			if ($stage === 'soft-closed') {
				$context['postingKind'] = (string)($extra['postingKind'] ?? 'regular');
			}

			$expectedLog[] = ['info', $message, $context];
		}

		self::assertSame($expectedLog, $this->log);
		self::assertSame([['PeriodStatus', ['filters' => ['periodYear' => 2026, 'periodMonth' => 3, 'administrationId' => 'adm-1'], 'limit' => 1]]], $this->queries);

	}//end testThePostingMatrix()

	/**
	 * A transaction id is resolved first; without a period nothing else is queried.
	 *
	 * @return void
	 */
	public function testATransactionIdIsResolvedFirst(): void {
		$guard = $this->guard(['GLTransaction' => [['id' => 'gl-1', 'periodId' => '2026-04']], 'PeriodStatus' => [['stage' => 'locked']]]);

		self::assertFalse($guard->postingAllowed('gl-1'));
		self::assertSame(
			[
				['GLTransaction', ['filters' => ['id' => 'gl-1'], 'limit' => 1]],
				['PeriodStatus', ['filters' => ['periodYear' => 2026, 'periodMonth' => 4], 'limit' => 1]],
			],
			$this->queries
		);

		$this->queries = [];
		self::assertTrue($this->guard([])->postingAllowed('gl-missing'));
		self::assertSame([['GLTransaction', ['filters' => ['id' => 'gl-missing'], 'limit' => 1]]], $this->queries);

	}//end testATransactionIdIsResolvedFirst()

	/**
	 * No period, an unparseable period or no status allows the posting.
	 *
	 * @return void
	 */
	public function testUngatedPostingsAreAllowed(): void {
		self::assertTrue($this->guard([])->postingAllowed(['administrationId' => 'adm-1']));
		self::assertSame([], $this->queries);
		self::assertTrue($this->guard([])->postingAllowed(['periodId' => '2026-13x']));
		self::assertTrue($this->guard([])->postingAllowed(['periodId' => '2026-05']));
		self::assertSame([], $this->log);

	}//end testUngatedPostingsAreAllowed()

	/**
	 * A failing lookup refuses the posting, with the error logged.
	 *
	 * @return void
	 */
	public function testAFailingLookupRefuses(): void {
		self::assertFalse($this->guard(['PeriodStatus' => 'throw'])->postingAllowed(['periodId' => '2026-05']));
		self::assertSame(
			[['error', 'PeriodStatusGuard: postingAllowed check failed — denying post (fail-closed)', ['exception' => 'register down']]],
			$this->log
		);

	}//end testAFailingLookupRefuses()
}//end class
