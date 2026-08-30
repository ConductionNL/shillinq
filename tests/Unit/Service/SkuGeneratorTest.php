<?php

/**
 * Unit tests for SkuGenerator.
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
 * @spec openspec/changes/inventory-barcode-sku/specs/inventory-barcode-sku/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SkuGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SkuGenerator::generate per REQ-SKU-002.
 *
 * Covers the three bundled templates (apparel, pet food, supplements), the
 * mapping / passthrough / hex transforms, and the unknown-template error path.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class SkuGeneratorTest extends TestCase {

	/**
	 * The service under test.
	 *
	 * @var SkuGenerator
	 */
	private SkuGenerator $generator;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->generator = new SkuGenerator();

	}//end setUp()

	/**
	 * REQ-SKU-002: the apparel template maps category/manufacturer and resolves
	 * the named colour to its hex code (Black -> 000).
	 *
	 * @return void
	 */
	public function testApparelTemplateProducesMappedSku(): void {
		$sku = $this->generator->generate(
			[
				'category' => 'Apparel',
				'manufacturer' => 'Nike',
				'size' => 'M',
				'color' => 'Black',
			],
			'RETAIL_APPAREL_TEMPLATE'
		);

		self::assertSame('AP-NK-M-000', $sku);

	}//end testApparelTemplateProducesMappedSku()

	/**
	 * REQ-SKU-002: the pet food template maps all four attributes.
	 *
	 * @return void
	 */
	public function testPetFoodTemplateProducesMappedSku(): void {
		$sku = $this->generator->generate(
			[
				'category' => 'Dry Food',
				'species' => 'Cat',
				'formula' => 'Senior',
				'size' => '2kg',
			],
			'PET_FOOD_TEMPLATE'
		);

		self::assertSame('DV-KAT-SENIOR-2KG', $sku);

	}//end testPetFoodTemplateProducesMappedSku()

	/**
	 * REQ-SKU-002: the supplement template mixes mapping and passthrough (dose).
	 *
	 * @return void
	 */
	public function testSupplementTemplateMixesMappingAndPassthrough(): void {
		$sku = $this->generator->generate(
			[
				'ingredient' => 'Vitamin C',
				'dose' => '1000',
				'unit' => 'mg',
				'form' => 'Capsule',
			],
			'SUPPLEMENT_TEMPLATE'
		);

		self::assertSame('VIT-C-1000-MG-CAP', $sku);

	}//end testSupplementTemplateMixesMappingAndPassthrough()

	/**
	 * Unmapped values pass through verbatim (mapping rule fall-through).
	 *
	 * @return void
	 */
	public function testUnmappedMappingValueFallsThrough(): void {
		$sku = $this->generator->generate(
			[
				'category' => 'Apparel',
				'manufacturer' => 'UnknownBrand',
				'size' => 'L',
				'color' => 'White',
			],
			'RETAIL_APPAREL_TEMPLATE'
		);

		// Apparel -> AP, UnknownBrand (no mapping) -> verbatim, White -> FFF.
		self::assertSame('AP-UnknownBrand-L-FFF', $sku);

	}//end testUnmappedMappingValueFallsThrough()

	/**
	 * The hex transform uppercases the first three characters when no named
	 * colour mapping exists.
	 *
	 * @return void
	 */
	public function testHexTransformFallsBackToFirstThreeChars(): void {
		$sku = $this->generator->generate(
			[
				'category' => 'Footwear',
				'manufacturer' => 'Adidas',
				'size' => '42',
				'color' => 'teal',
			],
			'RETAIL_APPAREL_TEMPLATE'
		);

		// Teal has no mapping -> first 3 chars uppercased -> TEA.
		self::assertSame('FW-AD-42-TEA', $sku);

	}//end testHexTransformFallsBackToFirstThreeChars()

	/**
	 * An unknown template id raises InvalidArgumentException.
	 *
	 * @return void
	 */
	public function testUnknownTemplateThrows(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->generator->generate(['category' => 'Apparel'], 'NO_SUCH_TEMPLATE');

	}//end testUnknownTemplateThrows()
}//end class
