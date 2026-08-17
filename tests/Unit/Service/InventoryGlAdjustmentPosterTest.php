<?php

/**
 * Unit tests for InventoryGlAdjustmentPoster.
 *
 * Covers the balanced two-line GLTransaction shape and the refusal to post
 * when accounts are missing or the amount is non-positive.
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
 * @spec openspec/changes/inventory-accounting-correctness/specs/inventory-accounting-correctness/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\InventoryGlAdjustmentPoster;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/InMemoryObjectService.php';

/**
 * InventoryGlAdjustmentPoster unit tests.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class InventoryGlAdjustmentPosterTest extends TestCase {
	/**
	 * Build the poster wired to an in-memory ObjectService stub.
	 *
	 * @param InMemoryObjectService $os The stub.
	 *
	 * @return InventoryGlAdjustmentPoster
	 */
	private function makePoster(InMemoryObjectService $os): InventoryGlAdjustmentPoster {
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturn($os);

		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$logger = $this->createStub(LoggerInterface::class);

		return new InventoryGlAdjustmentPoster(
			appConfig: $appConfig,
			logger: $logger,
			objectService: new DuckObjectServiceAdapter($os),
		);

	}//end makePoster()

	/**
	 * A well-formed request writes one GLTransaction + a balanced debit/credit
	 * pair of GLLines.
	 *
	 * @return void
	 */
	public function testPostsBalancedTwoLineTransaction(): void {
		$os = new InMemoryObjectService();
		$poster = $this->makePoster(os: $os);

		$result = $poster->post(
			administrationId: 'adm-1',
			debitAccount: '1300',
			creditAccount: '1305',
			amountCents: 12345,
			journalCode: 'LAND',
			description: 'Test landed cost',
			sourceReference: 'PO-1',
			postingDate: '2026-04-01',
			periodId: '2026-Q2'
		);

		self::assertTrue($result['posted']);
		self::assertTrue($result['balanced']);
		self::assertSame(12345, $result['debitCents']);
		self::assertSame($result['debitCents'], $result['creditCents']);

		self::assertCount(1, $os->dump(schema: 'GLTransaction'));
		$lines = $os->dump(schema: 'GLLine');
		self::assertCount(2, $lines);
		self::assertSame(123.45, $lines[0]['amount']);
		self::assertSame(123.45, $lines[1]['amount']);
		self::assertNotSame($lines[0]['side'], $lines[1]['side']);

	}//end testPostsBalancedTwoLineTransaction()

	/**
	 * A missing account is refused (posted false) — no lopsided journal.
	 *
	 * @return void
	 */
	public function testRefusesMissingAccount(): void {
		$os = new InMemoryObjectService();
		$poster = $this->makePoster(os: $os);

		$result = $poster->post(
			administrationId: 'adm-1',
			debitAccount: '1300',
			creditAccount: '',
			amountCents: 500,
			journalCode: 'NRV',
			description: 'x',
			sourceReference: 'r',
			postingDate: '2026-04-01',
			periodId: '2026-Q2'
		);

		self::assertFalse($result['posted']);
		self::assertSame([], $os->dump(schema: 'GLTransaction'));

	}//end testRefusesMissingAccount()

	/**
	 * A non-positive amount posts nothing.
	 *
	 * @return void
	 */
	public function testRefusesNonPositiveAmount(): void {
		$os = new InMemoryObjectService();
		$poster = $this->makePoster(os: $os);

		$result = $poster->post(
			administrationId: 'adm-1',
			debitAccount: '1300',
			creditAccount: '1305',
			amountCents: 0,
			journalCode: 'NRV',
			description: 'x',
			sourceReference: 'r',
			postingDate: '2026-04-01',
			periodId: '2026-Q2'
		);

		self::assertFalse($result['posted']);
		self::assertSame([], $os->dump(schema: 'GLLine'));

	}//end testRefusesNonPositiveAmount()
}//end class
