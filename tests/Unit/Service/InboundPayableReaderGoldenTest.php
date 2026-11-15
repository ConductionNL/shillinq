<?php

/**
 * Golden characterisation of InboundPayableReader.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Event\PayablesRequestedEvent;
use OCA\Shillinq\Service\InboundPayableReader;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Pins the paging queries, the narrowing, the view and the refusals.
 */
final class InboundPayableReaderGoldenTest extends TestCase {

	/**
	 * Recorded queries.
	 *
	 * @var array<int,mixed>
	 */
	private array $queries = [];

	/**
	 * Build the reader over the given payouts.
	 *
	 * @param array<int,mixed> $rows Stored payouts.
	 *
	 * @return InboundPayableReader
	 */
	private function reader(array $rows): InboundPayableReader {
		$queries = &$this->queries;
		$store = $this->createMock(ObjectServiceInterface::class);
		$store->method('setRegister')->willReturnCallback(
			function (string $register) use (&$queries, $store): ObjectServiceInterface {
				$queries[] = ['register', $register];
				return $store;
			}
		);
		$store->method('setSchema')->willReturnSelf();
		$store->method('findAll')->willReturnCallback(
			function (array $config, bool $rbac, bool $multitenancy) use (&$queries, $rows): array {
				$queries[] = ['find', $config, $rbac, $multitenancy];
				$matching = array_values(
					array_filter($rows, static fn ($row): bool => is_array($row) === false || array_intersect_assoc($config['filters'], $row) === $config['filters'])
				);
				return array_slice($matching, $config['offset'], $config['limit']);
			}
		);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('reg');
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(static fn (string $app): bool => in_array($app, ['dossiq', 'larpinq'], true));

		return new InboundPayableReader($store, $config, $apps);

	}//end reader()

	/**
	 * The stored payouts: 450 of one app across three pages, plus edge rows.
	 *
	 * @return array<int,mixed>
	 */
	private static function rows(): array {
		$rows = [];
		for ($i = 0; $i < 450; $i++) {
			$rows[] = ['id' => 'p' . $i, 'sourceApp' => 'dossiq', 'category' => ($i % 2 === 0) ? 'fee' : 'refund', 'correlationId' => 'c' . ($i % 5), 'paidOn' => (2025 + ($i % 2)) . '-03-01', 'amount' => $i];
		}
		$rows[] = [
			'id' => 'full', 'sourceApp' => 'dossiq', 'category' => 'fee', 'reference' => 'R-1', 'correlationId' => 'c9', 'amount' => '12.50',
			'payee' => ['name' => 'Jan', 'iban' => 'NL01'], 'currency' => 'USD', 'state' => 'paid', 'paidOn' => '2026-05-05',
			'bankReference' => 'B-1', 'historical' => true, 'linkedObjects' => ['a' => ['id' => 'x']],
		];
		$rows[] = ['id' => '', 'sourceApp' => 'dossiq', 'category' => 'fee', 'correlationId' => 'c9'];
		$rows[] = ['id' => 'other', 'sourceApp' => 'larpinq', 'correlationId' => 'c9'];
		$rows[] = ['id' => 'nodate', 'sourceApp' => 'dossiq', 'category' => 'fee', 'correlationId' => 'c9', 'historical' => 'yes'];

		return $rows;

	}//end rows()

	/**
	 * Run one event and return the answer, the error and the queries.
	 *
	 * @param PayablesRequestedEvent $event The question.
	 *
	 * @return array<string,mixed>
	 */
	private function ask(PayablesRequestedEvent $event): array {
		$this->queries = [];
		$this->reader(self::rows())->listFor($event);

		return ['handled' => $event->isHandled(), 'error' => $event->getError(), 'payables' => $event->getPayables(), 'queries' => $this->queries];

	}//end ask()

	/**
	 * Pin every narrowing and the full payout view.
	 *
	 * @return void
	 */
	public function testAnswersAreExact(): void {
		$all = $this->ask(new PayablesRequestedEvent('dossiq'));
		self::assertSame(452, count($all['payables']));
		self::assertSame(
			[
				['register', 'reg'],
				['find', ['filters' => ['sourceApp' => 'dossiq'], 'limit' => 200, 'offset' => 0], false, false],
				['find', ['filters' => ['sourceApp' => 'dossiq'], 'limit' => 200, 'offset' => 200], false, false],
				['find', ['filters' => ['sourceApp' => 'dossiq'], 'limit' => 200, 'offset' => 400], false, false],
			],
			$all['queries']
		);

		$narrow = $this->ask(new PayablesRequestedEvent('dossiq', ['c9', 'c3'], 'fee', 2026));
		self::assertSame([['find', ['filters' => ['sourceApp' => 'dossiq', 'category' => 'fee'], 'limit' => 200, 'offset' => 0], false, false]], array_slice($narrow['queries'], 1, 1));
		self::assertSame(['full'], array_column($narrow['payables'], 'payableId'));
		self::assertSame(['p3', 'p13', 'p23'], array_slice(array_column($this->ask(new PayablesRequestedEvent('dossiq', ['c3'], '', 2026))['payables'], 'payableId'), 0, 3));
		self::assertSame(
			[
				'payableId' => 'full',
				'reference' => 'R-1',
				'correlationId' => 'c9',
				'category' => 'fee',
				'amount' => 12.5,
				'payee' => ['name' => 'Jan', 'iban' => 'NL01'],
				'currency' => 'USD',
				'state' => 'paid',
				'paidOn' => '2026-05-05',
				'bankReference' => 'B-1',
				'historical' => true,
				'linkedObjects' => [['id' => 'x']],
			],
			$narrow['payables'][count($narrow['payables']) - 1]
		);

		$any = $this->ask(new PayablesRequestedEvent('dossiq', ['c9']));
		self::assertSame(
			[
				'payableId' => 'nodate', 'reference' => '', 'correlationId' => 'c9', 'category' => 'fee', 'amount' => 0.0,
				'payee' => ['name' => '', 'iban' => ''], 'currency' => 'EUR', 'state' => '', 'paidOn' => '', 'bankReference' => '',
				'historical' => false, 'linkedObjects' => [],
			],
			$any['payables'][1]
		);
		self::assertSame(['full', 'nodate'], array_column($any['payables'], 'payableId'));

	}//end testAnswersAreExact()

	/**
	 * Allowed and refused: an empty or uninstalled app is refused before any
	 * read; an installed app only ever reads its own payouts.
	 *
	 * @return void
	 */
	public function testRefusalsAndScoping(): void {
		foreach (['' => 'Only an installed app can read its payouts; "" is not one.', 'ghost' => 'Only an installed app can read its payouts; "ghost" is not one.'] as $app => $error) {
			$answer = $this->ask(new PayablesRequestedEvent((string)$app));
			self::assertSame([false, $error, [], []], [$answer['handled'], $answer['error'], $answer['payables'], $answer['queries']]);
		}

		self::assertSame(['other'], array_column($this->ask(new PayablesRequestedEvent('larpinq'))['payables'], 'payableId'));

	}//end testRefusalsAndScoping()
}//end class
