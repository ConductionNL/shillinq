<?php

/**
 * Unit tests for the integration-leaves register fragment.
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
 * @spec openspec/changes/integration-leaves-consume/specs/consume-platform-abstractions/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Merges the whole register the way SettingsService::loadRegisterConfigData()
 * does (the monolith, then every register.d fragment in name order) and checks
 * the leaves this change declares: calendar on VatReturn and ARInvoice,
 * contacts on CustomerMaster and Payee, and the mail and decidesk-decisions
 * leaves of Invoice and Contract unchanged.
 *
 * @covers \OCA\Shillinq\Service\SettingsService
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class IntegrationLeavesFragmentTest extends TestCase {

	/**
	 * Absolute path to the settings directory.
	 *
	 * @var string
	 */
	private string $settingsDir = __DIR__ . '/../../../lib/Settings';

	/**
	 * Invoke the private static SettingsService::deepMergeConfig().
	 *
	 * @param array<mixed> $base    Base config.
	 * @param array<mixed> $overlay Fragment.
	 *
	 * @return array<mixed> Merged config.
	 */
	private function merge(array $base, array $overlay): array {
		$m = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$m->setAccessible(true);
		return $m->invoke(null, $base, $overlay);
	}//end merge()

	/**
	 * The register as shipped: the monolith with every fragment merged in name order.
	 *
	 * @return array<mixed>
	 */
	private function mergedRegister(): array {
		$config = json_decode((string)file_get_contents($this->settingsDir . '/shillinq_register.json'), true);
		$files = glob($this->settingsDir . '/register.d/*.json');
		sort($files);
		foreach ($files as $file) {
			$fragment = json_decode((string)file_get_contents($file), true);
			if (is_array($fragment) === true) {
				$config = $this->merge($config, $fragment);
			}
		}

		return $config;
	}//end mergedRegister()

	/**
	 * The fragment as an array.
	 *
	 * @return array<mixed>
	 */
	private function fragment(): array {
		$fragment = json_decode((string)file_get_contents($this->settingsDir . '/register.d/zzz-integration-leaves.json'), true);
		self::assertIsArray($fragment);
		return $fragment;
	}//end fragment()

	/**
	 * The fragment declares only linkedTypes, on exactly the four schemas.
	 *
	 * @return void
	 */
	public function testTheFragmentDeclaresOnlyLinkedTypesOnFourSchemas(): void {
		$fragment = $this->fragment();

		self::assertSame('integration-leaves-consume', $fragment['_meta']['change']);
		self::assertSame(
			[
				'VatReturn'      => ['configuration' => ['linkedTypes' => ['calendar']]],
				'ARInvoice'      => ['configuration' => ['linkedTypes' => ['calendar']]],
				'CustomerMaster' => ['configuration' => ['linkedTypes' => ['contacts']]],
				'Payee'          => ['configuration' => ['linkedTypes' => ['contacts']]],
			],
			$fragment['components']['schemas']
		);
	}//end testTheFragmentDeclaresOnlyLinkedTypesOnFourSchemas()

	/**
	 * In the merged register the four schemas carry their leaf and Invoice and Contract keep theirs.
	 *
	 * @return void
	 */
	public function testTheMergedRegisterCarriesEachLeafOnce(): void {
		$schemas = $this->mergedRegister()['components']['schemas'];

		self::assertSame(['calendar'], $schemas['VatReturn']['configuration']['linkedTypes']);
		self::assertSame(['calendar'], $schemas['ARInvoice']['configuration']['linkedTypes']);
		self::assertSame(['contacts'], $schemas['CustomerMaster']['configuration']['linkedTypes']);
		self::assertSame(['contacts'], $schemas['Payee']['configuration']['linkedTypes']);
		self::assertSame(['mail'], $schemas['Invoice']['configuration']['linkedTypes']);
		self::assertSame(['decidesk-decisions'], $schemas['Contract']['configuration']['linkedTypes']);
	}//end testTheMergedRegisterCarriesEachLeafOnce()

	/**
	 * The fragment leaves every other part of the four schemas as the other fragments built it.
	 *
	 * @return void
	 */
	public function testTheFragmentChangesNothingElseOnTheFourSchemas(): void {
		$withFragment = $this->mergedRegister()['components']['schemas'];

		$config = json_decode((string)file_get_contents($this->settingsDir . '/shillinq_register.json'), true);
		$files = glob($this->settingsDir . '/register.d/*.json');
		sort($files);
		foreach ($files as $file) {
			if (basename($file) === 'zzz-integration-leaves.json') {
				continue;
			}

			$config = $this->merge($config, json_decode((string)file_get_contents($file), true));
		}

		foreach (['VatReturn', 'ARInvoice', 'CustomerMaster', 'Payee'] as $schema) {
			$before = $config['components']['schemas'][$schema];
			self::assertArrayNotHasKey('linkedTypes', ($before['configuration'] ?? []), $schema . ' already declared linkedTypes');
			$after = $withFragment[$schema];
			unset($after['configuration']['linkedTypes']);
			self::assertSame($before, $after, $schema);
		}
	}//end testTheFragmentChangesNothingElseOnTheFourSchemas()

	/**
	 * A list merged onto an existing list is appended to it, which is why the fragment
	 * does not restate the linkedTypes of Invoice or Contract.
	 *
	 * @return void
	 */
	public function testAListMergedOntoAListIsAppended(): void {
		$merged = $this->merge(
			['configuration' => ['linkedTypes' => ['mail']]],
			['configuration' => ['linkedTypes' => ['mail']]]
		);

		self::assertSame(['mail', 'mail'], $merged['configuration']['linkedTypes']);
	}//end testAListMergedOntoAListIsAppended()
}//end class
