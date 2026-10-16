<?php

/**
 * Unit tests for DunningLetterComposer.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Dunning
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/arinvoice-field-backfill-and-bt10/specs/bookkeeping-credit-control-dunning/spec.md (REQ-CCD-017)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Dunning;

use DateTimeImmutable;
use OCA\Shillinq\Service\Dunning\DunningLetterComposer;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The letter and record composition taken out of DunningRunService.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @spec openspec/changes/arinvoice-field-backfill-and-bt10/specs/bookkeeping-credit-control-dunning/spec.md (REQ-CCD-017)
 */
final class DunningLetterComposerTest extends TestCase {
	/**
	 * A composer over an app config with one stage override.
	 *
	 * @return DunningLetterComposer
	 */
	private function composer(): DunningLetterComposer {
		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				return ['dunning.template.stage_3' => 'tpl-deployment-stage3'][$key] ?? $default;
			}
		);

		return new DunningLetterComposer(appConfig: $appConfig);
	}//end composer()

	/**
	 * An ordinary invoice is not prepared: the params pass through and the run count is never asked.
	 *
	 * @return void
	 */
	public function testAnOrdinaryInvoicePassesThroughWithoutCountingRuns(): void {
		$params = ['stageNr' => 2, 'collectionCostAmount' => 40.0];

		$prepared = $this->composer()->prepare(
			invoice: ['lifecycleState' => 'issued'],
			params: $params,
			runsSoFar: static function (): int {
				throw new RuntimeException('the run count is only read for a voluntary contribution');
			}
		);

		self::assertSame($params, $prepared);
	}//end testAnOrdinaryInvoicePassesThroughWithoutCountingRuns()

	/**
	 * A voluntary contribution gets its own letter, without costs, once.
	 *
	 * @return void
	 */
	public function testAVoluntaryContributionGetsItsOwnLetterWithoutCosts(): void {
		$invoice = ['lifecycleState' => 'issued', 'contribution' => ['voluntary' => true], 'invoiceNumber' => 'SC-1'];

		$prepared = $this->composer()->prepare(
			invoice: $invoice,
			params: ['stageNr' => 1, 'collectionCostAmount' => 40.0, 'interestAmount' => 3.0],
			runsSoFar: static fn (): int => 0
		);

		self::assertNull($prepared['collectionCostAmount']);
		self::assertNull($prepared['interestAmount']);
		self::assertNotSame('', (string)$prepared['templateId']);
		self::assertNotSame('', (string)$prepared['renderedBody']);

		$this->expectException(RuntimeException::class);
		$this->composer()->prepare(invoice: $invoice, params: ['stageNr' => 1], runsSoFar: static fn (): int => 1);
	}//end testAVoluntaryContributionGetsItsOwnLetterWithoutCosts()

	/**
	 * The record carries the named template, else the registry's stage default with its override.
	 *
	 * @return void
	 */
	public function testTheRecordFallsBackToTheRegistryTemplate(): void {
		$now = new DateTimeImmutable('2026-09-28T10:00:00+00:00');
		$composer = $this->composer();

		$named = $composer->compose(administrationId: 'adm-1', invoiceId: 'inv-1', params: ['stageNr' => 2, 'templateId' => 'tpl-custom'], now: $now);
		$stage2 = $composer->compose(administrationId: 'adm-1', invoiceId: 'inv-1', params: ['stageNr' => 2], now: $now);
		$stage3 = $composer->compose(administrationId: 'adm-1', invoiceId: 'inv-1', params: ['stageNr' => 3, 'templateId' => ''], now: $now);

		self::assertSame('tpl-custom', $named['templateId']);
		self::assertSame('tpl-stage2-herinnering-nl', $stage2['templateId']);
		self::assertSame('tpl-deployment-stage3', $stage3['templateId']);
	}//end testTheRecordFallsBackToTheRegistryTemplate()

	/**
	 * The record has the DunningRun shape: pending, executed, scoped, dated.
	 *
	 * @return void
	 */
	public function testTheRecordHasTheDunningRunShape(): void {
		$record = $this->composer()->compose(
			administrationId: 'adm-1',
			invoiceId: 'inv-1',
			params: ['stageNr' => 4, 'ladderId' => 'lad-1', 'channel' => 'POST', 'invoiceAmount' => '121.5'],
			now: new DateTimeImmutable('2026-09-28T10:00:00+00:00')
		);

		self::assertSame('inv-1', $record['invoiceId']);
		self::assertSame('lad-1', $record['ladderId']);
		self::assertSame(4, $record['stageNr']);
		self::assertSame('POST', $record['channel']);
		self::assertSame(121.5, $record['invoiceAmount']);
		self::assertSame('PENDING', $record['deliveryStatus']);
		self::assertSame('executed', $record['lifecycleState']);
		self::assertSame('adm-1', $record['administrationId']);
		self::assertSame('2026-09-28T10:00:00+00:00', $record['executedOn']);
		self::assertNull($record['collectionCostAmount']);
	}//end testTheRecordHasTheDunningRunShape()
}//end class
