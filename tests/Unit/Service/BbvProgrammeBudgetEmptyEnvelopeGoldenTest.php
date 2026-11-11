<?php

/**
 * Golden test for the empty BBV programme-budget envelope.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\BbvProgrammeBudgetService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pins the envelope returned when there is nothing to report.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BbvProgrammeBudgetEmptyEnvelopeGoldenTest extends TestCase {

	/**
	 * The envelope matches the golden fixture.
	 *
	 * @return void
	 */
	public function testEmptyEnvelopeMatchesGolden(): void {
		$service = (new ReflectionClass(BbvProgrammeBudgetService::class))->newInstanceWithoutConstructor();
		$envelope = (new ReflectionMethod(BbvProgrammeBudgetService::class, 'emptyEnvelope'))->invoke($service);
		$this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $envelope['generatedAt']);
		$envelope['generatedAt'] = '<now>';

		$file = __DIR__.'/fixtures/bbv-programme-budget-empty-envelope.golden.json';
		$json = json_encode($envelope, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
		if (getenv('BBV_EMPTY_GOLDEN_WRITE') === '1') {
			file_put_contents($file, $json."\n");
		}

		$this->assertSame(file_get_contents($file), $json."\n");

	}//end testEmptyEnvelopeMatchesGolden()
}//end class
