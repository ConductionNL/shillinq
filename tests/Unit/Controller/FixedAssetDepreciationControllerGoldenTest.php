<?php

/**
 * Golden test for the FixedAssetDepreciationController asset authorization.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Controller
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

namespace OCA\Shillinq\Tests\Unit\Controller;

use DomainException;
use OCA\Shillinq\Controller\FixedAssetDepreciationController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Asset\AssetRecords;
use OCA\Shillinq\Service\Asset\DepreciationScheduleService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins both actions, allowed and refused, and every check and call behind them in order.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class FixedAssetDepreciationControllerGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * Run one action for asset x1.
	 *
	 * @param string              $action The action.
	 * @param array<string,mixed> $setup  user, asset (array|null|'throw'), member, poster, refuse (bool).
	 *
	 * @return array{0:int,1:mixed}
	 */
	private function act(string $action, array $setup=[]): array {
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('currentUserId')->willReturnCallback(
			function () use ($setup): ?string {
				$this->calls[] = ['currentUserId'];
				if (array_key_exists('user', $setup) === true) {
					return $setup['user'];
				}

				return 'u1';
			}
		);
		$context->method('canAccess')->willReturnCallback(
			function (string $administrationId) use ($setup): bool {
				$this->calls[] = ['canAccess', $administrationId];
				return ($setup['member'] ?? true);
			}
		);
		$context->method('canPostJournalEntry')->willReturnCallback(
			function (string $administrationId) use ($setup): bool {
				$this->calls[] = ['canPostJournalEntry', $administrationId];
				return ($setup['poster'] ?? true);
			}
		);
		$asset = ['id' => 'x1', 'administrationId' => 'a1'];
		if (array_key_exists('asset', $setup) === true) {
			$asset = $setup['asset'];
		}

		$records = $this->createMock(AssetRecords::class);
		$records->method('find')->willReturnCallback(
			function (string $schema, string $id) use ($asset): ?array {
				$this->calls[] = ['find', $schema, $id];
				if ($asset === 'throw') {
					throw new RuntimeException('store down');
				}

				return $asset;
			}
		);
		$refuse    = ($setup['refuse'] ?? false);
		$schedules = $this->createMock(DepreciationScheduleService::class);
		foreach (['missed', 'postMissed'] as $method) {
			$schedules->method($method)->willReturnCallback(
				function (array $given, string $beforeMonth) use ($method, $refuse): array {
					$this->calls[] = [$method, $given['id'], $beforeMonth];
					if ($refuse === true) {
						throw new DomainException('Period closed.');
					}

					return [['period' => '2026-01', 'amount' => 10.105], ['period' => '2026-02', 'amount' => 0.1]];
				}
			);
		}

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(mktime(12, 0, 0, 3, 15, 2026));
		$response = (new FixedAssetDepreciationController($this->createMock(IRequest::class), $context, $records, $schedules, $time))->$action('x1');
		return [$response->getStatus(), $response->getData()];
	}//end act()

	/**
	 * No session is a 401; an unknown, unreadable or foreign asset a 404; posting without the right a 403.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		self::assertSame(expected: [401, ['error' => 'Not authenticated']], actual: $this->act(action: 'missed', setup: ['user' => null]));
		self::assertSame(expected: [404, ['error' => 'Not found']], actual: $this->act(action: 'missed', setup: ['asset' => null]));
		self::assertSame(expected: [404, ['error' => 'Not found']], actual: $this->act(action: 'postMissed', setup: ['asset' => 'throw']));
		self::assertSame(expected: [404, ['error' => 'Not found']], actual: $this->act(action: 'missed', setup: ['member' => false]));
		$forbidden = [403, ['error' => 'You may not post journal entries in this administration.']];
		self::assertSame(expected: $forbidden, actual: $this->act(action: 'postMissed', setup: ['poster' => false]));
		$find     = ['find', 'FixedAsset', 'x1'];
		$expected = [
			['currentUserId'],
			['currentUserId'],
			$find,
			['currentUserId'],
			$find,
			['currentUserId'],
			$find,
			['canAccess', 'a1'],
			['currentUserId'],
			$find,
			['canAccess', 'a1'],
			['canPostJournalEntry', 'a1'],
		];
		self::assertSame(expected: $expected, actual: $this->calls);
	}//end testRefusals()

	/**
	 * Reading never asks for the posting right; posting does; both answer the summed rows; a refusal is a 422.
	 *
	 * @return void
	 */
	public function testAnswers(): void {
		$summary = [200, ['rows' => [['period' => '2026-01', 'amount' => 10.105], ['period' => '2026-02', 'amount' => 0.1]], 'total' => 10.21]];
		self::assertSame(expected: $summary, actual: $this->act(action: 'missed', setup: ['poster' => false]));
		self::assertSame(expected: $summary, actual: $this->act(action: 'postMissed'));
		self::assertSame(expected: [422, ['error' => 'Period closed.']], actual: $this->act(action: 'postMissed', setup: ['refuse' => true]));
		$find     = ['find', 'FixedAsset', 'x1'];
		$expected = [
			['currentUserId'],
			$find,
			['canAccess', 'a1'],
			['missed', 'x1', '2026-03'],
			['currentUserId'],
			$find,
			['canAccess', 'a1'],
			['canPostJournalEntry', 'a1'],
			['postMissed', 'x1', '2026-03'],
		];
		self::assertSame(expected: $expected, actual: array_slice($this->calls, 0, 9));
	}//end testAnswers()
}//end class
