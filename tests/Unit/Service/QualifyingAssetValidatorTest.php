<?php

/**
 * Unit tests for QualifyingAssetValidator.
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
 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\QualifyingAssetValidator;
use PHPUnit\Framework\TestCase;

/**
 * Tests the toegangsticket validation routes (REQ-IBA-001).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class QualifyingAssetValidatorTest extends TestCase {

	/**
	 * The validator under test.
	 *
	 * @var QualifyingAssetValidator
	 */
	private QualifyingAssetValidator $val;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->val = new QualifyingAssetValidator();

	}//end setUp()

	/**
	 * A valid, in-date S&O-verklaring yields status valid (REQ-IBA-001).
	 *
	 * @return void
	 */
	public function testValidSoVerklaringIsValid(): void {
		$asset = [
			'type' => 'software',
			'accessTicket' => [
				'kind' => 'so_declaration',
				'rnd_declaration_number' => 'S2024/001234',
				'so_declaration_period' => ['van' => '2024-01-01', 'tot' => '2024-12-31'],
			],
		];
		$result = $this->val->validateAccessTicket($asset, '2024-06-01');
		self::assertTrue($result['valid']);
		self::assertSame('valid', $result['status']);

	}//end testValidSoVerklaringIsValid()

	/**
	 * A malformed S&O number is rejected (REQ-IBA-001 format S{jaar}/{6-cijfer}).
	 *
	 * @return void
	 */
	public function testMalformedSoVerklaringIsInvalid(): void {
		$asset = [
			'type' => 'software',
			'accessTicket' => ['kind' => 'so_declaration', 'rnd_declaration_number' => '2024-1234'],
		];
		$result = $this->val->validateAccessTicket($asset, '2024-06-01');
		self::assertFalse($result['valid']);
		self::assertSame('invalid_access_ticket', $result['status']);
		self::assertContains('so_verklaring_format_invalid', $result['errors']);

	}//end testMalformedSoVerklaringIsInvalid()

	/**
	 * An expired S&O-verklaring yields status expired (REQ-IBA-001).
	 *
	 * @return void
	 */
	public function testExpiredSoVerklaringIsExpired(): void {
		$asset = [
			'type' => 'software',
			'accessTicket' => [
				'kind' => 'so_declaration',
				'rnd_declaration_number' => 'S2023/000999',
				'so_declaration_period' => ['van' => '2023-01-01', 'tot' => '2023-12-31'],
			],
		];
		$result = $this->val->validateAccessTicket($asset, '2024-06-01');
		self::assertSame('expired', $result['status']);

	}//end testExpiredSoVerklaringIsExpired()

	/**
	 * The octrooi-route requires an octrooi_nummer (REQ-IBA-001).
	 *
	 * @return void
	 */
	public function testOctrooiRouteRequiresNumber(): void {
		$valid = $this->val->validateAccessTicket(
			['type' => 'octrooi', 'accessTicket' => ['kind' => 'octrooi', 'patent_number' => 'NL2031234']]
		);
		self::assertTrue($valid['valid']);

		$missing = $this->val->validateAccessTicket(
			['type' => 'octrooi', 'accessTicket' => ['kind' => 'octrooi']]
		);
		self::assertFalse($missing['valid']);

	}//end testOctrooiRouteRequiresNumber()

	/**
	 * The combinatie-route requires BOTH S&O-verklaring AND octrooi/kwekersrecht.
	 *
	 * @return void
	 */
	public function testCombinatieRouteRequiresBoth(): void {
		$both = $this->val->validateAccessTicket(
			[
				'type' => 'combinatie',
				'accessTicket' => [
					'rnd_declaration_number' => 'S2024/001234',
					'patent_number' => 'NL2031234',
				],
			],
			'2024-06-01'
		);
		self::assertTrue($both['valid']);

		$onlySo = $this->val->validateAccessTicket(
			[
				'type' => 'combinatie',
				'accessTicket' => ['rnd_declaration_number' => 'S2024/001234'],
			],
			'2024-06-01'
		);
		self::assertFalse($onlySo['valid']);
		self::assertContains('combinatie_requires_octrooi_or_kwekersrecht', $onlySo['errors']);

	}//end testCombinatieRouteRequiresBoth()
}//end class
