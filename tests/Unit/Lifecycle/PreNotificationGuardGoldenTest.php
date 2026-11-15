<?php

/**
 * Golden test for PreNotificationGuard::canIncludeInBatch.
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
 * @spec openspec/specs/bookkeeping-sepa-direct-debit/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\PreNotificationGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Pins result and every log record per case.
 *
 * Regenerate with PRE_NOTIFICATION_GOLDEN_WRITE=1.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 * phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
 */
final class PreNotificationGuardGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/fixtures/pre-notification-guard.golden.json';

	public function testDecisionsMatchGolden(): void {
		$due = ['requestedCollectionDate' => '2026-03-15'];
		$cases = [
			'noNotification' => [$due, null, null],
			'noProof' => [$due, ['channel' => 'email'], null],
			'invoiceLineNoSent' => [$due, ['channel' => 'invoice_line'], null],
			'invoiceLineNoDue' => [[], ['channel' => 'invoice_line', 'sentAt' => '2026-03-01'], null],
			'emailNoDue' => [[], ['channel' => 'email', 'sentAt' => '2026-03-01'], null],
			'exactly14' => [$due, ['sentAt' => '2026-03-01T23:59:00+01:00'], null],
			'thirteen' => [$due, ['sentAt' => '2026-03-02T00:00:00+01:00'], null],
			'recordNoticeDays' => [$due, ['sentAt' => '2026-03-10', 'noticeDays' => '5'], null],
			'overrideWins' => [$due, ['sentAt' => '2026-03-10', 'noticeDays' => 5], 6],
			'sentAfterDue' => [$due, ['sentAt' => '2026-03-20'], 0],
			'badDate' => [['requestedCollectionDate' => 'not-a-date'], ['sentAt' => '2026-03-01'], null],
		];

		$actual = [];
		foreach ($cases as $name => [$collection, $notification, $required]) {
			$logger = new class extends AbstractLogger {

				public array $records = [];

				public function log($level, \Stringable|string $message, array $context = []): void {
					$this->records[] = [(string) $level, (string) $message, array_keys($context)];
				}
			};
			$actual[$name] = [
				'result' => (new PreNotificationGuard($logger))->canIncludeInBatch($collection, $notification, $required),
				'logs' => $logger->records,
			];
		}

		if (getenv('PRE_NOTIFICATION_GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))."\n");
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame($golden, $actual);
	}
}
