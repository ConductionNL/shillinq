<?php

/**
 * Unit tests for DunningTemplateRegistry.
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
 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-28
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Dunning;

use OCA\Shillinq\Service\Dunning\DunningTemplateRegistry;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * DunningTemplateRegistry unit tests.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class DunningTemplateRegistryTest extends TestCase {
	/**
	 * Build a registry with an arbitrary appConfig override map.
	 *
	 * @param array<string,string> $overrides Per-key overrides.
	 *
	 * @return DunningTemplateRegistry
	 */
	private function makeRegistry(array $overrides = []): DunningTemplateRegistry {
		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($overrides): string {
				return ($overrides[$key] ?? $default);
			}
		);
		return new DunningTemplateRegistry(appConfig: $appConfig);
	}//end makeRegistry()

	/**
	 * Task-28: default templateId per stage matches the docudesk naming convention.
	 *
	 * @return void
	 */
	public function testDefaultTemplateIdsPerStage(): void {
		$reg = $this->makeRegistry();

		self::assertSame('tpl-stage1-vriendelijk-nl', $reg->templateIdForStage(stageNr: 1));
		self::assertSame('tpl-stage2-herinnering-nl', $reg->templateIdForStage(stageNr: 2));
		self::assertSame('tpl-stage3-aanmaning-14d-nl', $reg->templateIdForStage(stageNr: 3));
		self::assertSame('tpl-stage4-ingebrekestelling-nl', $reg->templateIdForStage(stageNr: 4));
		self::assertSame('tpl-stage5-overdracht-incasso-nl', $reg->templateIdForStage(stageNr: 5));

	}//end testDefaultTemplateIdsPerStage()

	/**
	 * Task-28: app-config override wins over the baked-in default.
	 *
	 * @return void
	 */
	public function testAppConfigOverrideWinsOverDefault(): void {
		$reg = $this->makeRegistry(overrides: ['dunning.template.stage_3' => 'tpl-stage3-customer-x']);
		self::assertSame('tpl-stage3-customer-x', $reg->templateIdForStage(stageNr: 3));
		// Stage 1 still default.
		self::assertSame('tpl-stage1-vriendelijk-nl', $reg->templateIdForStage(stageNr: 1));

	}//end testAppConfigOverrideWinsOverDefault()

	/**
	 * Task-28: tone-gradient labels match the design D2 vriendelijk → juridisch curve.
	 *
	 * @return void
	 */
	public function testToneGradient(): void {
		$reg = $this->makeRegistry();
		self::assertSame('vriendelijk', $reg->toneForStage(stageNr: 1));
		self::assertSame('zakelijk', $reg->toneForStage(stageNr: 2));
		self::assertSame('formeel', $reg->toneForStage(stageNr: 3));
		self::assertSame('juridisch', $reg->toneForStage(stageNr: 4));
		self::assertSame('juridisch', $reg->toneForStage(stageNr: 5));

	}//end testToneGradient()

	/**
	 * Task-28: merge-fields list is the canonical set the docudesk templates
	 * must interpolate.
	 *
	 * @return void
	 */
	public function testMergeFieldsCarryCanonicalSet(): void {
		$fields = $this->makeRegistry()->mergeFields();
		self::assertContains('klantNaam', $fields);
		self::assertContains('factuurNummer', $fields);
		self::assertContains('outstandingAmount', $fields);
		self::assertContains('expiryDate', $fields);
		self::assertContains('iban', $fields);
		self::assertContains('incassokosten', $fields);
		self::assertContains('rente', $fields);

	}//end testMergeFieldsCarryCanonicalSet()

	/**
	 * Task-28: listAll surfaces a full row per stage for UI consumption.
	 *
	 * @return void
	 */
	public function testListAllSurfacesEveryStageRow(): void {
		$rows = $this->makeRegistry()->listAll();
		self::assertCount(5, $rows);
		self::assertSame(1, $rows[0]['stageNr']);
		self::assertSame('tpl-stage1-vriendelijk-nl', $rows[0]['templateId']);
		self::assertSame('vriendelijk', $rows[0]['tone']);

	}//end testListAllSurfacesEveryStageRow()

}//end class
