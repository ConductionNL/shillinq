<?php

/**
 * SalesOrder / SalesOrderLine schema + seed-data integration test.
 *
 * `order-revenue-recognition` is a `kind:config` head — no shillinq PHP ships in
 * this change; recognition arithmetic is deferred to the chained
 * `order-revenue-recognition-engine` service. This test therefore exercises
 * only the declarative surface: it loads `lib/Settings/shillinq_register.json`
 * (no OpenRegister runtime, no HTTP) and asserts that
 *
 *  - the SalesOrder + SalesOrderLine schemas declare the lifecycle,
 *    audit-trail and administrationId-scoped RBAC the spec requires (Task 1/2);
 *  - the seeded SalesOrder ORDER-2026-0001 round-trips through OpenRegister's
 *    object shape: every seeded SalesOrderLine's `orderId` FK and
 *    `administrationId` resolve back to the parent order, i.e. create/read
 *    referential integrity holds with zero shillinq PHP (Task 5.2);
 *  - the schema-level conditional rules hold on the seed data (RECURRING
 *    lines carry a non-null `frequentie`; POINT_IN_TIME lines carry a
 *    non-null `recognitionDate`);
 *  - the worked sample in design.md ("recognized recurring revenue for
 *    [2026-01-01, 2026-03-31] = 7500; one-off = 5000") is reproducible from
 *    the seed data alone via the same frequency-normalization arithmetic the
 *    `maandWaarde` calc already encodes, proving the data shape supports the
 *    metric the chained `-engine` service will compute (Task 5.3).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/order-revenue-recognition/tasks.md#5-verification
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Declarative-only round-trip + worked-sample lock for order-revenue-recognition.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class SalesOrderSeedDataIntegrationTest extends TestCase {
	/**
	 * Load the base register config once.
	 *
	 * @return array<string,mixed>
	 */
	private function register(): array {
		$path = __DIR__ . '/../../lib/Settings/shillinq_register.json';
		$raw = file_get_contents($path);
		if ($raw === false) {
			self::fail('Could not read shillinq_register.json.');
		}

		$data = json_decode($raw, true);
		if (is_array($data) === false) {
			self::fail('shillinq_register.json is not valid JSON.');
		}

		return $data;
	}//end register()

	/**
	 * All seeded `objects` entries for a given schema slug.
	 *
	 * @param string $schema Schema slug (e.g. "SalesOrder").
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function seededObjects(string $schema): array {
		$objects = ($this->register()['objects'] ?? []);
		$matches = [];
		foreach ($objects as $object) {
			if (($object['@self']['schema'] ?? null) === $schema) {
				$matches[] = $object;
			}
		}

		return $matches;
	}//end seededObjects()

	/**
	 * Monthly-normalized rate for a RECURRING line — mirrors the
	 * `maandWaarde` JSON-AST calc's frequencyFactor table exactly (Bucket-4
	 * reference pattern) so this test proves the seed data supports the
	 * metric without duplicating the engine's business logic ahead of time.
	 *
	 * @param array<string,mixed> $line A SalesOrderLine seed object.
	 *
	 * @return float Monthly-normalized amount; 0.0 for ONE_OFF lines.
	 */
	private function monthlyNormalizedAmount(array $line): float {
		if ($line['nature'] === 'ONE_OFF') {
			return 0.0;
		}

		$amount = (float)$line['amount'];
		switch ($line['frequency']) {
			case 'MONTHLY':
				return $amount;
			case 'QUARTERLY':
				return ($amount / 3);
			case 'ANNUALLY':
				return ($amount / 12);
			case 'WEEKLY':
				return (($amount * 52) / 12);
			case 'FORTNIGHTLY':
				return (($amount * 26) / 12);
			default:
				self::fail('Unknown frequentie: ' . (string)$line['frequency']);
		}

	}//end monthlyNormalizedAmount()

	/**
	 * Whole-month overlap between a line's effective term (inheriting the
	 * order's term when null, per D3) and a reporting period, per design.md
	 * D5 (provisional whole-month granularity).
	 *
	 * @param array<string,mixed> $line SalesOrderLine seed object.
	 * @param array<string,mixed> $order Parent SalesOrder seed object.
	 * @param string $from Period start (Y-m-d).
	 * @param string $to Period end (Y-m-d).
	 *
	 * @return int Whole months of overlap.
	 */
	private function overlapMonths(array $line, array $order, string $from, string $to): int {
		$termStart = ($line['termStart'] ?? null) ?? $order['termStart'];
		$termEnd = ($line['termEnd'] ?? null) ?? $order['termEnd'];

		$effectiveStart = max($termStart, $from);
		$effectiveEnd = $to;
		if ($termEnd !== null) {
			$effectiveEnd = min($termEnd, $to);
		}

		if ($effectiveStart > $effectiveEnd) {
			return 0;
		}

		$start = new \DateTimeImmutable($effectiveStart);
		$end = new \DateTimeImmutable($effectiveEnd);
		$diff = $start->diff($end);

		// Whole-month proration per D5: months elapsed + 1 (inclusive of the
		// starting month) within the overlapping window.
		return (($diff->y * 12) + $diff->m + 1);
	}//end overlapMonths()

	/**
	 * SalesOrder + SalesOrderLine declare the lifecycle, audit-trail and
	 * administrationId-scoped RBAC the spec requires.
	 *
	 * @return void
	 */
	public function testSchemasDeclareLifecycleAuditTrailAndRbac(): void {
		$schemas = $this->register()['components']['schemas'];
		self::assertArrayHasKey('SalesOrder', $schemas);
		self::assertArrayHasKey('SalesOrderLine', $schemas);

		$order = $schemas['SalesOrder'];
		self::assertSame('active', $order['x-openregister-lifecycle']['initialState']);
		self::assertArrayHasKey('ended', $order['x-openregister-lifecycle']['states']);
		self::assertTrue($order['x-openregister-audit-trail']['enabled']);
		self::assertSame('administrationId', $order['x-openregister-rbac']['adminScope']['field']);

		$line = $schemas['SalesOrderLine'];
		self::assertTrue($line['x-openregister-audit-trail']['enabled']);
		self::assertContains('RECURRING', $line['properties']['nature']['enum']);
		self::assertContains('ONE_OFF', $line['properties']['nature']['enum']);
		self::assertContains('OVER_TIME', $line['properties']['recognitionMethod']['enum']);
		self::assertContains('POINT_IN_TIME', $line['properties']['recognitionMethod']['enum']);

	}//end testSchemasDeclareLifecycleAuditTrailAndRbac()

	/**
	 * Task 5.2 — the seeded SalesOrder round-trips through OpenRegister's
	 * object shape with its three lines: every line's `orderId` FK and
	 * `administrationId` resolve back to the parent order (create/read +
	 * audit-trail-scoping integrity), with zero shillinq PHP involved.
	 *
	 * @return void
	 */
	public function testSeedSalesOrderRoundTripsWithItsLines(): void {
		$orders = $this->seededObjects('SalesOrder');
		self::assertCount(1, $orders, 'design.md seeds exactly one SalesOrder.');
		$order = $orders[0];
		self::assertSame('ORDER-2026-0001', $order['orderId']);

		$lines = $this->seededObjects('SalesOrderLine');
		self::assertCount(3, $lines, 'design.md seeds exactly three mixed SalesOrderLines.');

		foreach ($lines as $line) {
			self::assertSame(
				$order['orderId'],
				$line['orderId'],
				'Line ' . $line['lineId'] . ' must FK back to the seeded order.'
			);
			self::assertSame(
				$order['administrationId'],
				$line['administrationId'],
				'Line ' . $line['lineId'] . ' must share the order\'s administrationId (RBAC/audit-trail scope).'
			);
		}

		$lineIds = array_map(static fn (array $l): string => $l['lineId'], $lines);
		self::assertContains('ORDERLINE-2026-0001-A', $lineIds);
		self::assertContains('ORDERLINE-2026-0001-B', $lineIds);
		self::assertContains('ORDERLINE-2026-0001-C', $lineIds);

	}//end testSeedSalesOrderRoundTripsWithItsLines()

	/**
	 * Task 5.2 — schema-level conditional rules hold on the seed: RECURRING
	 * lines carry a non-null `frequentie`; POINT_IN_TIME lines carry a
	 * non-null `recognitionDate`. A POINT_IN_TIME line without
	 * recognitionDate, or a RECURRING line without frequentie, would be an
	 * invalid seed per the acceptance criteria.
	 *
	 * @return void
	 */
	public function testSeedLinesSatisfyConditionalRules(): void {
		$lines = $this->seededObjects('SalesOrderLine');
		self::assertNotEmpty($lines);

		foreach ($lines as $line) {
			if ($line['nature'] === 'RECURRING') {
				self::assertNotNull(
					$line['frequency'],
					'RECURRING line ' . $line['lineId'] . ' must carry a non-null frequentie.'
				);
			}

			if ($line['recognitionMethod'] === 'POINT_IN_TIME') {
				self::assertNotNull(
					$line['recognitionDate'],
					'POINT_IN_TIME line ' . $line['lineId'] . ' must carry a non-null recognitionDate.'
				);
			} else {
				self::assertNull(
					$line['recognitionDate'],
					'OVER_TIME line ' . $line['lineId'] . ' should not carry a recognitionDate.'
				);
			}
		}

	}//end testSeedLinesSatisfyConditionalRules()

	/**
	 * Task 5.3 — the design.md worked sample is reproducible from the seed
	 * data alone: recognized recurring revenue for [2026-01-01, 2026-03-31]
	 * = 7500 (Line A 3000 + Line C 4500); the one-off Line B (5000,
	 * recognitionDate 2026-01-15) is excluded from the recurring figure and
	 * separately recognized point-in-time within the same period.
	 *
	 * @return void
	 */
	public function testWorkedSampleRecognizedRevenueForQ1(): void {
		$orders = $this->seededObjects('SalesOrder');
		$order = $orders[0];
		$lines = $this->seededObjects('SalesOrderLine');

		$periodFrom = '2026-01-01';
		$periodTo = '2026-03-31';

		$recurringTotal = 0.0;
		$oneOffTotal = 0.0;

		foreach ($lines as $line) {
			if ($line['nature'] === 'RECURRING') {
				$months = $this->overlapMonths($line, $order, $periodFrom, $periodTo);
				$recurringTotal += ($this->monthlyNormalizedAmount($line) * $months);
				continue;
			}

			// ONE_OFF + POINT_IN_TIME: recognized in full if recognitionDate
			// falls within the period.
			$recognitionDate = $line['recognitionDate'];
			if ($recognitionDate >= $periodFrom && $recognitionDate <= $periodTo) {
				$oneOffTotal += (float)$line['amount'];
			}
		}

		self::assertEqualsWithDelta(7500.0, $recurringTotal, 0.001, 'Recognized recurring revenue for Q1 2026.');
		self::assertEqualsWithDelta(5000.0, $oneOffTotal, 0.001, 'Recognized one-off revenue for Q1 2026.');

	}//end testWorkedSampleRecognizedRevenueForQ1()
}//end class
