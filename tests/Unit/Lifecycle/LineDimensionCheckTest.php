<?php

/**
 * Unit tests for LineDimensionCheck.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ledger-journal-line-dimensions/specs/bookkeeping-general-ledger/spec.md#requirement-posting-refuses-an-unknown-or-inactive-code-req-ljd-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Lifecycle\LineDimensionCheck;
use OCA\Shillinq\Lifecycle\PostingRefusedException;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * A line's cost centre, cost carrier and project must be active dimensions of
 * the administration on the posting date; the refusal names line and code.
 *
 * @covers \OCA\Shillinq\Lifecycle\LineDimensionCheck
 */
final class LineDimensionCheckTest extends TestCase {

	/**
	 * The dimensions of administration adm-1 (and one of adm-2).
	 *
	 * @var list<array<string,mixed>>
	 */
	private const DIMENSIONS = [
		['code' => 'CC-100', 'dimensionType' => 'cost-center', 'lifecycleState' => 'active', 'administrationId' => 'adm-1'],
		['code' => 'KD-200', 'dimensionType' => 'cost-object', 'lifecycleState' => 'active', 'administrationId' => 'adm-1'],
		['code' => 'PRJ-1', 'dimensionType' => 'project', 'lifecycleState' => 'active', 'administrationId' => 'adm-1', 'startDate' => '2026-01-01', 'endDate' => '2026-06-30'],
		['code' => 'CC-OLD', 'dimensionType' => 'cost-center', 'lifecycleState' => 'archived', 'administrationId' => 'adm-1'],
		['code' => 'CC-900', 'dimensionType' => 'cost-center', 'lifecycleState' => 'active', 'administrationId' => 'adm-2'],
	];

	/**
	 * The filters each findAll() was called with.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $reads = [];

	/**
	 * Whether the store applies the filters, as OpenRegister does; false answers every row.
	 *
	 * @var bool
	 */
	private bool $storeFilters = true;

	public function testLinesWithoutCodesReadNothing(): void {
		$this->check()->assertKnown(
			lines: [['accountNumber' => '4500', 'costCenterCode' => ''], ['accountNumber' => '1000']],
			administrationId: 'adm-1',
			postingDate: '2026-03-01'
		);

		self::assertSame([], $this->reads);
	}//end testLinesWithoutCodesReadNothing()

	public function testActiveCodesOfEachKindPassWithOneRead(): void {
		$this->check()->assertKnown(
			lines: [
				['costCenterCode' => 'CC-100', 'costCarrierCode' => 'KD-200', 'projectCode' => 'PRJ-1'],
				['costCenterCode' => ' CC-100 '],
			],
			administrationId: 'adm-1',
			postingDate: '2026-03-01'
		);

		self::assertCount(1, $this->reads);
		self::assertSame(['administrationId' => 'adm-1', 'lifecycleState' => 'active'], $this->reads[0]['filters']);
	}//end testActiveCodesOfEachKindPassWithOneRead()

	/**
	 * @return array<string, array{0: array<string,string>, 1: string}>
	 */
	public static function refusals(): array {
		return [
			'unknown cost centre' => [['costCenterCode' => 'KP-900'], 'Line 2: cost centre KP-900 does not exist or is not active in this administration'],
			'archived cost centre' => [['costCenterCode' => 'CC-OLD'], 'Line 2: cost centre CC-OLD does not exist or is not active in this administration'],
			'another administration' => [['costCenterCode' => 'CC-900'], 'Line 2: cost centre CC-900 does not exist or is not active in this administration'],
			'a cost centre as carrier' => [['costCarrierCode' => 'CC-100'], 'Line 2: cost carrier CC-100 does not exist or is not active in this administration'],
			'a carrier as project' => [['projectCode' => 'KD-200'], 'Line 2: project KD-200 does not exist or is not active in this administration'],
		];
	}//end refusals()

	/**
	 * @dataProvider refusals
	 *
	 * @param array<string,string> $codes   The codes on line 2.
	 * @param string               $message The refusal.
	 */
	public function testACodeThatIsNoActiveDimensionOfItsKindIsRefused(array $codes, string $message): void {
		$this->expectException(PostingRefusedException::class);
		$this->expectExceptionMessage($message);

		$this->check()->assertKnown(
			lines: [['costCenterCode' => 'CC-100'], $codes],
			administrationId: 'adm-1',
			postingDate: '2026-03-01'
		);
	}//end testACodeThatIsNoActiveDimensionOfItsKindIsRefused()

	public function testAProjectOutsideItsValidityWindowIsRefused(): void {
		$this->check()->assertKnown(lines: [['projectCode' => 'PRJ-1']], administrationId: 'adm-1', postingDate: '2026-06-30');
		$this->check()->assertKnown(lines: [['projectCode' => 'PRJ-1']], administrationId: 'adm-1', postingDate: '2026-01-01T00:00:00Z');

		$this->expectException(PostingRefusedException::class);
		$this->expectExceptionMessage('Line 1: project PRJ-1 does not exist or is not active in this administration');
		$this->check()->assertKnown(lines: [['projectCode' => 'PRJ-1']], administrationId: 'adm-1', postingDate: '2026-07-01');
	}//end testAProjectOutsideItsValidityWindowIsRefused()

	public function testARowTheStoreFailedToFilterOutStillDoesNotCount(): void {
		$this->storeFilters = false;
		$this->check()->assertKnown(lines: [['costCenterCode' => 'CC-100']], administrationId: 'adm-1', postingDate: '2026-03-01');

		$this->expectException(PostingRefusedException::class);
		$this->expectExceptionMessage('Line 1: cost centre CC-900 does not exist or is not active in this administration');
		$this->check()->assertKnown(lines: [['costCenterCode' => 'CC-900'], ['costCenterCode' => 'CC-OLD']], administrationId: 'adm-1', postingDate: '2026-03-01');
	}//end testARowTheStoreFailedToFilterOutStillDoesNotCount()

	public function testWithoutAnAdministrationEveryCodeIsRefused(): void {
		$this->expectException(PostingRefusedException::class);
		$this->expectExceptionMessage('Line 1: cost centre CC-100 does not exist or is not active in this administration');

		try {
			$this->check()->assertKnown(lines: [['costCenterCode' => 'CC-100']], administrationId: '', postingDate: '2026-03-01');
		} finally {
			self::assertSame([], $this->reads);
		}
	}//end testWithoutAnAdministrationEveryCodeIsRefused()

	/**
	 * The check over DIMENSIONS, filtered as OpenRegister would on administration and lifecycle state.
	 *
	 * @return LineDimensionCheck
	 */
	private function check(): LineDimensionCheck {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('setRegister')->with('shillinq')->willReturnSelf();
		$objects->method('setSchema')->with('AnalyticalDimension')->willReturnSelf();
		$objects->method('findAll')->willReturnCallback(
			function (array $config): array {
				$this->reads[] = $config;
				$filters = $config['filters'];
				if ($this->storeFilters === false) {
					return self::DIMENSIONS;
				}

				return array_values(
					array_filter(
						self::DIMENSIONS,
						static fn (array $d): bool => $d['administrationId'] === $filters['administrationId'] && $d['lifecycleState'] === $filters['lifecycleState']
					)
				);
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		return new LineDimensionCheck(appConfig: $appConfig, objectService: $objects, l10n: $l10n);
	}//end check()
}//end class
