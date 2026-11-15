<?php

/**
 * Golden test for every PortalContributionProvider manifest.
 *
 * Records the complete contribution — collections with their scopeField /
 * scopeClaim pairs, field whitelists, joins, actions and generated pages —
 * per audience, so moving the declarative manifests cannot widen or narrow
 * what any portal audience may read or do.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Portal;

use OCA\Shillinq\Portal\PortalContributionProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Compares each audience's contribution with the recorded golden.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PortalContributionManifestGoldenTest extends TestCase {

	/**
	 * Path of the recorded golden.
	 *
	 * @var string
	 */
	private const GOLDEN = __DIR__ . '/fixtures/portal-contribution-golden.json';

	/**
	 * Every audience plus the refused ones.
	 *
	 * @return array<string, array{0: array<string, mixed>}> Cases.
	 */
	public static function subjects(): array {
		return [
			'customer'   => [['audience' => 'customer']],
			'supplier'   => [['audience' => 'supplier']],
			'accountant' => [['audience' => 'accountant']],
			'parent'     => [['audience' => 'parent']],
			'unknown'    => [['audience' => 'employee']],
			'none'       => [[]],
		];

	}//end subjects()

	/**
	 * The contribution for the subject equals the golden.
	 *
	 * @param array<string, mixed> $subject The portal subject.
	 *
	 * @return void
	 */
	#[DataProvider('subjects')]
	public function testContributionMatchesGolden(array $subject): void {
		$actual = (new PortalContributionProvider())->getContribution($subject);
		$key = (string)json_encode($subject);
		$golden = json_decode((string)file_get_contents(self::GOLDEN), true);

		if (getenv('SHILLINQ_RECORD_GOLDEN') === '1') {
			$golden[$key] = $actual;
			file_put_contents(self::GOLDEN, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		self::assertArrayHasKey($key, $golden);
		self::assertSame($golden[$key], $actual);

	}//end testContributionMatchesGolden()
}//end class
