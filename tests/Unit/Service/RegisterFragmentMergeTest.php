<?php

/**
 * Unit tests for the ADR-037 modular register fragment deep-merge.
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
 * @spec openspec/specs/apphost-adoption/spec.md#requirement-mechanical-boilerplate-served-by-apphost-generics
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies that disjoint register fragments union cleanly so concurrent
 * OpenSpec change builds never collide on the shared register file (ADR-037).
 */
final class RegisterFragmentMergeTest extends TestCase {
	/**
	 * Invoke the private static SettingsService::deepMergeConfig().
	 *
	 * @param array<mixed> $base Base config.
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
	 * Two fragments adding disjoint OpenAPI schemas/paths union by key.
	 *
	 * @return void
	 */
	public function testDisjointFragmentsUnionSchemasAndPaths(): void {
		$base = [
			'components' => ['schemas' => ['Existing' => ['type' => 'object']]],
			'paths' => ['/existing' => ['get' => []]],
		];

		$base = $this->merge(
			$base,
			[
				'components' => ['schemas' => ['AlphaInvoice' => ['type' => 'object']]],
				'paths' => ['/alpha' => ['get' => []]],
			]
		);
		$base = $this->merge(
			$base,
			[
				'components' => ['schemas' => ['BetaPayment' => ['type' => 'object']]],
				'paths' => ['/beta' => ['post' => []]],
			]
		);

		$this->assertArrayHasKey('Existing', $base['components']['schemas']);
		$this->assertArrayHasKey('AlphaInvoice', $base['components']['schemas']);
		$this->assertArrayHasKey('BetaPayment', $base['components']['schemas']);
		$this->assertCount(3, $base['components']['schemas']);
		$this->assertArrayHasKey('/existing', $base['paths']);
		$this->assertArrayHasKey('/alpha', $base['paths']);
		$this->assertArrayHasKey('/beta', $base['paths']);
	}//end testDisjointFragmentsUnionSchemasAndPaths()

	/**
	 * List arrays are concatenated; scalars overwrite.
	 *
	 * @return void
	 */
	public function testListsConcatenateAndScalarsOverwrite(): void {
		$merged = $this->merge(
			['required' => ['a', 'b'], 'info' => ['version' => '0.1.0']],
			['required' => ['c'], 'info' => ['version' => '0.2.0']]
		);
		$this->assertSame(['a', 'b', 'c'], $merged['required']);
		$this->assertSame('0.2.0', $merged['info']['version']);
	}//end testListsConcatenateAndScalarsOverwrite()

	/**
	 * A fragment introducing components.objects[] when the base has none sets the
	 * list wholesale; a second fragment then concatenates onto it. This locks the
	 * additive-union behaviour the bookings-resource-calendar seed fragment relies
	 * on so its seed objects merge cleanly alongside other changes' objects (ADR-037).
	 *
	 * @return void
	 */
	public function testComponentsObjectsListUnionsAdditively(): void {
		// Base monolith carries schemas but no components.objects key.
		$base = ['components' => ['schemas' => ['Existing' => ['type' => 'object']]]];

		// Fragment A introduces the objects list and a new schema.
		$base = $this->merge(
			$base,
			[
				'components' => [
					'schemas' => ['Booking' => ['type' => 'object']],
					'objects' => [['@self' => ['schema' => 'Booking', 'slug' => 'bk-001']]],
				],
			]
		);
		$this->assertArrayHasKey('objects', $base['components']);
		$this->assertCount(1, $base['components']['objects']);

		// Fragment B appends another object — list concatenation, no clobber.
		$base = $this->merge(
			$base,
			[
				'components' => [
					'objects' => [['@self' => ['schema' => 'Booking', 'slug' => 'bk-002']]],
				],
			]
		);
		$this->assertCount(2, $base['components']['objects']);
		$this->assertSame('bk-001', $base['components']['objects'][0]['@self']['slug']);
		$this->assertSame('bk-002', $base['components']['objects'][1]['@self']['slug']);
		// Pre-existing schema survives the object merges.
		$this->assertArrayHasKey('Existing', $base['components']['schemas']);
		$this->assertArrayHasKey('Booking', $base['components']['schemas']);
	}//end testComponentsObjectsListUnionsAdditively()
}//end class
