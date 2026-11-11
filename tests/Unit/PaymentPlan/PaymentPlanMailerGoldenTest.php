<?php

/**
 * Golden test for PaymentPlanMailer::confirmation.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\PaymentPlan
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\PaymentPlan;

use OCA\Shillinq\PaymentPlan\PaymentPlanMailer;
use OCP\IL10N;
use OCP\Mail\IMailer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Pins the exact confirmation mail and the order of translation lookups.
 *
 * Regenerate with PAYMENT_PLAN_MAILER_GOLDEN_WRITE=1.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 * phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
 */
final class PaymentPlanMailerGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/fixtures/payment-plan-mailer.golden.json';

	public function testConfirmationsMatchGolden(): void {
		$plan = ['paymentReference' => 'PP-7', 'customerName' => ' Ada ', 'totalAmount' => 1234.5, 'graceDays' => '10'];
		$invoices = [['invoiceNumber' => 'VF-1', 'amountDue' => '1000'], ['amountDue' => 234.5]];
		$instalments = [['instalmentNumber' => 1, 'dueDate' => '2026-04-01', 'amount' => 617.25], ['instalmentNumber' => '2', 'amount' => 617.25]];

		$actual = [];
		foreach (['full' => [$plan, $instalments, $invoices], 'bare' => [[], [], []]] as $name => [$p, $i, $v]) {
			$lookups = [];
			$l10n = $this->createMock(IL10N::class);
			$l10n->method('t')->willReturnCallback(
				function (string $text, array $parameters = []) use (&$lookups): string {
					$lookups[] = $text;
					return '['.vsprintf($text, $parameters).']';
				}
			);
			$mailer = new PaymentPlanMailer($this->createMock(IMailer::class), $l10n, new NullLogger());
			$actual[$name] = ['mail' => $mailer->confirmation($p, $i, $v, 'NL91ABNA0417164300'), 'lookups' => $lookups];
		}

		if (getenv('PAYMENT_PLAN_MAILER_GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))."\n");
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame($golden, $actual);
	}
}
