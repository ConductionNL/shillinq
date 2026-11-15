<?php

/**
 * Unit tests for ChartTemplateService.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\ChartTemplate
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ledger-multi-administration/specs/bookkeeping-multi-administratie/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\ChartTemplate;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Shillinq\Service\ChartTemplate\ChartTemplateService;
use OCA\Shillinq\Service\RegisterBinding;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * The office De Boer's template, its clients and a second version.
 *
 * @covers \OCA\Shillinq\Service\ChartTemplate\ChartTemplateService
 * @uses   \OCA\Shillinq\Service\RegisterBinding
 */
class ChartTemplateServiceTest extends TestCase {

	/**
	 * Stored rows by schema, keyed by id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $store = [];

	/**
	 * Saves in order, as [schema, row].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $saves = [];

	/**
	 * The schema the last setSchema() named.
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * The service over an in-memory register.
	 *
	 * @return ChartTemplateService
	 */
	private function service(): ChartTemplateService {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnCallback(
			function (string|int $schema) use ($objects) {
				$this->schema = (string)$schema;

				return $objects;
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				$rows = [];
				foreach (($this->store[$this->schema] ?? []) as $id => $row) {
					foreach (($config['filters'] ?? []) as $key => $value) {
						if (($row[$key] ?? null) !== $value) {
							continue 2;
						}
					}

					$rows[] = $this->entity(id: $id, row: $row);
				}

				return $rows;
			}
		);
		$objects->method('find')->willReturnCallback(
			function (int|string $id) {
				if (isset($this->store[$this->schema][$id]) === false) {
					return null;
				}

				return $this->entity(id: (string)$id, row: $this->store[$this->schema][$id]);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null) {
				$id = ($uuid ?? strtolower($this->schema) . '-' . (count($this->store[$this->schema] ?? []) + 1));
				unset($object['id']);
				$this->store[$this->schema][$id] = $object;
				$this->saves[] = [$this->schema, $object];

				return $this->entity(id: $id, row: $object);
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-11-13 10:00:00'));

		return new ChartTemplateService(binding: new RegisterBinding(objectService: $objects, appConfig: $appConfig), timeFactory: $time);
	}//end service()

	/**
	 * A stored row as OpenRegister hands it back.
	 *
	 * @param string               $id  The id.
	 * @param array<string, mixed> $row The row.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $id, array $row): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn(['id' => $id] + $row);

		return $entity;
	}//end entity()

	/**
	 * Bakkerij Jansen's chart: two accounts, one cost centre, with balances and budgets.
	 *
	 * @return void
	 */
	private function seedBakery(): void {
		$this->store['Account'] = [
			'acc-1' => ['accountNumber' => '4150', 'name' => 'Uitzendkrachten', 'accountType' => 'expense', 'currency' => 'EUR', 'administrationId' => 'adm-bakker', 'lifecycleState' => 'active', 'balance' => 1200.5, 'emuAggregationHash' => 'abc'],
			'acc-2' => ['accountNumber' => '8050', 'name' => 'Omzet catering', 'accountType' => 'revenue', 'currency' => 'EUR', 'administrationId' => 'adm-bakker', 'lifecycleState' => 'active'],
			'acc-x' => ['accountNumber' => '9999', 'name' => 'Elders', 'accountType' => 'expense', 'currency' => 'EUR', 'administrationId' => 'adm-elders', 'lifecycleState' => 'active'],
		];
		$this->store['AnalyticalDimension'] = [
			'dim-1' => ['code' => 'WINKEL', 'name' => 'Winkel', 'dimensionType' => 'costCenter', 'administrationId' => 'adm-bakker', 'lifecycleState' => 'active', 'budget' => 5000, 'spentToDate' => 100],
		];
	}//end seedBakery()

	/**
	 * Save as template copies the chart and dimensions, never balances,
	 * budgets, ids or the administration, at version 1 (REQ-LMA-003).
	 *
	 * @return void
	 */
	public function testSaveAsTemplateCopiesTheChartWithoutBalances(): void {
		$this->seedBakery();

		$template = $this->service()->saveAsTemplate(administrationId: 'adm-bakker', name: '  Kantoor De Boer MKB ');

		self::assertSame('administrationtemplate-1', $template['id']);
		self::assertSame(
			[
				'name' => 'Kantoor De Boer MKB',
				'sourceAdministrationId' => 'adm-bakker',
				'version' => 1,
				'lifecycleState' => 'active',
				'accounts' => [
					['accountNumber' => '4150', 'name' => 'Uitzendkrachten', 'accountType' => 'expense', 'currency' => 'EUR'],
					['accountNumber' => '8050', 'name' => 'Omzet catering', 'accountType' => 'revenue', 'currency' => 'EUR'],
				],
				'dimensions' => [
					['code' => 'WINKEL', 'name' => 'Winkel', 'dimensionType' => 'costCenter'],
				],
			],
			$this->store['AdministrationTemplate']['administrationtemplate-1']
		);
	}//end testSaveAsTemplateCopiesTheChartWithoutBalances()

	/**
	 * A template needs a name of at most 200 characters and an administration.
	 *
	 * @return void
	 */
	public function testATemplateNeedsANameAndAnAdministration(): void {
		foreach ([['adm-bakker', '  '], ['', 'Naam'], ['adm-bakker', str_repeat('x', 201)]] as [$administrationId, $name]) {
			try {
				$this->service()->saveAsTemplate(administrationId: $administrationId, name: $name);
				self::fail('Saved a template without a valid name or administration.');
			} catch (InvalidArgumentException $refused) {
				self::assertSame('A template needs an administration and a name of at most 200 characters.', $refused->getMessage());
			}
		}

		self::assertSame([], $this->saves);
	}//end testATemplateNeedsANameAndAnAdministration()

	/**
	 * A new administration seeded from the template gets its accounts and
	 * cost centres; a record it already has is left alone (REQ-LMA-004).
	 *
	 * @return void
	 */
	public function testANewAdministrationStartsFromTheTemplate(): void {
		$this->seedBakery();
		$service = $this->service();
		$service->saveAsTemplate(administrationId: 'adm-bakker', name: 'Kantoor De Boer MKB');
		$this->store['Account']['acc-own'] = ['accountNumber' => '4150', 'name' => 'Eigen naam', 'accountType' => 'expense', 'administrationId' => 'adm-plein'];

		$report = $service->seedFromTemplate(templateId: 'administrationtemplate-1', administrationId: 'adm-plein');

		self::assertSame(['added' => ['Account:8050', 'AnalyticalDimension:WINKEL'], 'updated' => [], 'skipped' => []], $report);
		self::assertSame('Eigen naam', $this->store['Account']['acc-own']['name']);
		self::assertSame(
			['accountNumber' => '8050', 'name' => 'Omzet catering', 'accountType' => 'revenue', 'currency' => 'EUR', 'administrationId' => 'adm-plein', 'lifecycleState' => 'active'],
			$this->store['Account']['account-5']
		);
		self::assertSame('adm-plein', $this->store['AnalyticalDimension']['analyticaldimension-2']['administrationId']);
	}//end testANewAdministrationStartsFromTheTemplate()

	/**
	 * An unknown template or an empty administration is refused.
	 *
	 * @return void
	 */
	public function testSeedingRefusesAnUnknownTemplateOrNoAdministration(): void {
		foreach ([['missing', 'adm-plein', 'Template not found.'], ['', 'adm-plein', 'Template not found.'], ['administrationtemplate-1', '', 'An administration to seed is required.']] as [$templateId, $administrationId, $message]) {
			try {
				$this->service()->seedFromTemplate(templateId: $templateId, administrationId: $administrationId);
				self::fail('Seeded from ' . $templateId);
			} catch (InvalidArgumentException $refused) {
				self::assertSame($message, $refused->getMessage());
			}
		}
	}//end testSeedingRefusesAnUnknownTemplateOrNoAdministration()

	/**
	 * Version 2 adds 4160 to both followers, renames where allowed, keeps
	 * Kapsalon Mooi's own 8050, records each run and moves each follower to
	 * version 2; a follower already at version 2 is left alone (REQ-LMA-005).
	 *
	 * @return void
	 */
	public function testVersionTwoReachesTheFollowersAndKeepsADeviation(): void {
		$this->store['AdministrationTemplate']['tpl-1'] = [
			'name' => 'Kantoor De Boer MKB',
			'sourceAdministrationId' => 'adm-bakker',
			'version' => 2,
			'lifecycleState' => 'active',
			'accounts' => [
				['accountNumber' => '8050', 'name' => 'Omzet catering', 'accountType' => 'revenue', 'balance' => 99],
				['accountNumber' => '4160', 'name' => 'Scholingskosten', 'accountType' => 'expense'],
			],
			'dimensions' => [['code' => 'WINKEL', 'name' => 'Winkel', 'dimensionType' => 'costCenter']],
		];
		$this->store['Administration'] = [
			'adm-bakker' => ['name' => 'Bakkerij Jansen', 'followsTemplateId' => 'tpl-1', 'followedTemplateVersion' => 1],
			'adm-kapper' => ['name' => 'Kapsalon Mooi', 'followsTemplateId' => 'tpl-1', 'followedTemplateVersion' => 1],
			'adm-nieuw' => ['name' => 'Al bij', 'followsTemplateId' => 'tpl-1', 'followedTemplateVersion' => 2],
			'adm-los' => ['name' => 'Volgt niets'],
		];
		$this->store['Account'] = [
			'b-8050' => ['accountNumber' => '8050', 'name' => 'Omzet', 'accountType' => 'revenue', 'administrationId' => 'adm-bakker'],
			'k-8050' => ['accountNumber' => '8050', 'name' => 'Omzet producten', 'accountType' => 'revenue', 'administrationId' => 'adm-kapper', 'deviatesFromTemplate' => true],
		];
		$this->store['AnalyticalDimension'] = [
			'k-winkel' => ['code' => 'WINKEL', 'name' => 'Winkel', 'dimensionType' => 'costCenter', 'administrationId' => 'adm-kapper'],
		];

		$reports = $this->service()->syncFollowers(templateId: 'tpl-1');

		self::assertSame(
			[
				'adm-bakker' => ['added' => ['Account:4160', 'AnalyticalDimension:WINKEL'], 'updated' => ['Account:8050'], 'skipped' => []],
				'adm-kapper' => ['added' => ['Account:4160'], 'updated' => [], 'skipped' => ['Account:8050']],
			],
			$reports
		);
		self::assertSame('Omzet catering', $this->store['Account']['b-8050']['name']);
		self::assertArrayNotHasKey('balance', $this->store['Account']['b-8050']);
		self::assertSame('Omzet producten', $this->store['Account']['k-8050']['name']);
		self::assertSame([2, 2, 2], array_column([$this->store['Administration']['adm-bakker'], $this->store['Administration']['adm-kapper'], $this->store['Administration']['adm-nieuw']], 'followedTemplateVersion'));
		self::assertSame('Kapsalon Mooi', $this->store['Administration']['adm-kapper']['name']);

		$runs = array_values($this->store['AdministrationMigration']);
		self::assertCount(2, $runs);
		self::assertSame(
			[
				'migrationNumber' => 'SYNC-tpl-1-v2-adm-kapper',
				'date' => '2026-11-13',
				'sourceAdministrationId' => 'adm-bakker',
				'destinationAdministrationId' => 'adm-kapper',
				'kind' => 'template_sync',
				'templateId' => 'tpl-1',
				'syncReport' => ['added' => ['Account:4160'], 'updated' => [], 'skipped' => ['Account:8050']],
				'objectIds' => [],
				'status' => 'geboekt_beide',
			],
			$runs[1]
		);

		$this->saves = [];
		self::assertSame([], $this->service()->syncFollowers(templateId: 'tpl-1'));
		self::assertSame([], $this->saves);
	}//end testVersionTwoReachesTheFollowersAndKeepsADeviation()

	/**
	 * Only active templates are offered, with their size.
	 *
	 * @return void
	 */
	public function testOnlyActiveTemplatesAreOffered(): void {
		$this->store['AdministrationTemplate'] = [
			'tpl-1' => ['name' => 'Kantoor De Boer MKB', 'version' => 3, 'lifecycleState' => 'active', 'accounts' => [[], []], 'dimensions' => [[]]],
			'tpl-2' => ['name' => 'Oud', 'version' => 1, 'lifecycleState' => 'retired', 'accounts' => []],
		];

		self::assertSame([['id' => 'tpl-1', 'name' => 'Kantoor De Boer MKB', 'version' => 3, 'accounts' => 2, 'dimensions' => 1]], $this->service()->activeTemplates());
	}//end testOnlyActiveTemplatesAreOffered()
}//end class
