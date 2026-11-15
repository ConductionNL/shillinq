<?php

/**
 * Unit tests for the tax-digipoort-filing register fragment.
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
 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-1.1
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
 * The fragment adds the filing fields and the OB entry point additively: on
 * the merged register the instance, the return and the taxonomy carry the new
 * optional properties, the entry point list gains belastingdienst-ob once,
 * and nothing that was there changes.
 */
final class TaxDigipoortFilingFragmentTest extends TestCase {

	/**
	 * The settings directory.
	 */
	private const SETTINGS = __DIR__ . '/../../../lib/Settings/';

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
	 * Decode one settings file.
	 *
	 * @param string $file The file under lib/Settings.
	 *
	 * @return array<mixed>
	 */
	private function decode(string $file): array {
		$data = json_decode((string)file_get_contents(self::SETTINGS . $file), true);
		self::assertIsArray($data, $file . ' is valid JSON');
		return $data;
	}//end decode()

	/**
	 * The register with the fragments that declare the three schemas, before this one.
	 *
	 * @return array<mixed>
	 */
	private function before(): array {
		$merged = $this->decode('shillinq_register.json');
		foreach (['register.d/bookkeeping-sbr-xbrl-reporting.json', 'register.d/add-shillinq-sbr-xbrl-reporting.json', 'register.d/bookkeeping-vat-btw-filing.json'] as $file) {
			$merged = $this->merge($merged, $this->decode($file));
		}

		return $merged;
	}//end before()

	public function testTheFilingFieldsAreOnTheMergedSchemasAndOptional(): void {
		$before = $this->before();
		$after  = $this->merge($before, $this->decode('register.d/tax-digipoort-filing.json'));
		$schemas = $after['components']['schemas'];

		self::assertSame(['vat-return', 'kvk-deponering'], $schemas['XbrlInstance']['properties']['filingType']['enum']);
		self::assertSame('string', $schemas['XbrlInstance']['properties']['sourceReturnId']['type']);
		self::assertSame('string', $schemas['BtwAangifte']['properties']['xbrlInstanceId']['type']);
		self::assertSame(['type' => 'string'], $schemas['XBRLTaxonomy']['properties']['entryPoints']['items']);

		foreach (['XbrlInstance', 'BtwAangifte', 'XBRLTaxonomy'] as $schema) {
			self::assertSame(
				($before['components']['schemas'][$schema]['required'] ?? []),
				($schemas[$schema]['required'] ?? []),
				$schema . ' requires nothing new, so existing rows keep validating'
			);
		}
	}//end testTheFilingFieldsAreOnTheMergedSchemasAndOptional()

	public function testTheEntryPointListGainsTheObEntryPointOnceAndKeepsTheRest(): void {
		$before = $this->before()['components']['schemas']['XbrlInstance']['properties']['entryPoint'];
		$after  = $this->merge($this->before(), $this->decode('register.d/tax-digipoort-filing.json'))['components']['schemas']['XbrlInstance']['properties']['entryPoint'];

		self::assertNotContains('belastingdienst-ob', $before['enum']);
		self::assertSame(array_merge($before['enum'], ['belastingdienst-ob']), $after['enum']);
		self::assertContains('kvk-jaarrekening', $after['enum']);
		self::assertSame(array_diff_key($before, ['enum' => 1]), array_diff_key($after, ['enum' => 1]));
	}//end testTheEntryPointListGainsTheObEntryPointOnceAndKeepsTheRest()

	public function testTheFragmentOnlyAddsProperties(): void {
		$fragment = $this->decode('register.d/tax-digipoort-filing.json');

		self::assertSame([], $fragment['objects']);
		self::assertSame(['XbrlInstance', 'BtwAangifte', 'XBRLTaxonomy'], array_keys($fragment['components']['schemas']));
		foreach ($fragment['components']['schemas'] as $schema) {
			self::assertSame(['properties'], array_keys($schema));
		}
	}//end testTheFragmentOnlyAddsProperties()
}//end class
