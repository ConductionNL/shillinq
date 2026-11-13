<?php

/**
 * Unit tests for the log-only dunning adapters bound while no outbound
 * connector is configured: what each one logs, what it leaves out of the log,
 * and the result it reports back to the dunning run.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Dunning
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

namespace OCA\Shillinq\Tests\Unit\Service\Dunning;

use OCA\Shillinq\Service\Dunning\LogCreditScoreFetchAdapter;
use OCA\Shillinq\Service\Dunning\LogIncassoBureauAdapter;
use OCA\Shillinq\Service\Dunning\LogPostNLAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Log-only dunning adapter unit tests.
 *
 * @covers \OCA\Shillinq\Service\Dunning\LogPostNLAdapter
 * @covers \OCA\Shillinq\Service\Dunning\LogIncassoBureauAdapter
 * @covers \OCA\Shillinq\Service\Dunning\LogCreditScoreFetchAdapter
 * @uses   \OCA\Shillinq\Service\Dunning\DunningChannelSendResult
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class LogDunningAdaptersTest extends TestCase {

	/**
	 * What the logger was given, as [message, context] pairs.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $logged = [];

	/**
	 * A logger that records every info() call.
	 *
	 * @return LoggerInterface The logger double.
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->logged[] = [$message, $context];
			}
		);

		return $logger;

	}//end logger()

	/**
	 * A registered letter is logged without its PDF bytes and reported delivered
	 * with a PostNL barcode and its tracking link.
	 *
	 * @return void
	 */
	public function testARegisteredLetterIsLoggedWithoutItsPdfAndReportedDelivered(): void {
		$adapter = new LogPostNLAdapter($this->logger());

		$result = $adapter->sendRegisteredLetter(['invoiceId' => 'inv-1', 'letterPdfBytes' => '%PDF-secret']);

		$this->assertSame('REGISTERED_POST', $result->channel);
		$this->assertSame('DELIVERED', $result->deliveryStatus);
		$this->assertMatchesRegularExpression('/^postnl-log-[0-9a-f]{16}$/', (string)$result->providerMessageId);
		$barcode = $result->extras['barcode'];
		$this->assertMatchesRegularExpression('/^3S\d{13}$/', $barcode);
		$this->assertSame('https://postnl.nl/tracktrace/' . $barcode, $result->extras['trackingUrl']);

		$this->assertCount(1, $this->logged);
		[$message, $context] = $this->logged[0];
		$this->assertSame('Shillinq PostNL registered-letter send deferred (no outbound connector bound)', $message);
		$this->assertSame(['barcode' => $barcode, 'payload' => ['invoiceId' => 'inv-1']], $context);

	}//end testARegisteredLetterIsLoggedWithoutItsPdfAndReportedDelivered()

	/**
	 * A collection transfer logs only counts of the dossier's runs and evidence,
	 * and is reported delivered with a stub dossier id.
	 *
	 * @return void
	 */
	public function testACollectionTransferLogsCountsOnly(): void {
		$adapter = new LogIncassoBureauAdapter($this->logger());

		$result = $adapter->transfer(
			'adm-1',
			'inv-9',
			['content' => ['dunningRuns' => [['id' => 1], ['id' => 2]], 'evidenceRefs' => [['ref' => 'e1']]]]
		);

		$this->assertSame('COLLECTION_AGENCY_API', $result->channel);
		$this->assertSame('DELIVERED', $result->deliveryStatus);
		$this->assertMatchesRegularExpression('/^incasso-log-[0-9a-f]{16}$/', (string)$result->providerMessageId);
		$this->assertMatchesRegularExpression('/^dossier-stub-[0-9a-f]{12}$/', $result->extras['dossierId']);
		$this->assertSame(
			[
				[
					'Shillinq incasso-bureau transfer deferred (no outbound connector bound)',
					['administrationId' => 'adm-1', 'invoiceId' => 'inv-9', 'dunningRuns' => 2, 'evidenceRefs' => 1],
				],
			],
			$this->logged
		);

	}//end testACollectionTransferLogsCountsOnly()

	/**
	 * A dossier without content counts zero runs and zero evidence.
	 *
	 * @return void
	 */
	public function testAnEmptyDossierCountsZero(): void {
		(new LogIncassoBureauAdapter($this->logger()))->transfer('adm-1', 'inv-9', []);

		$this->assertSame(0, $this->logged[0][1]['dunningRuns']);
		$this->assertSame(0, $this->logged[0][1]['evidenceRefs']);

	}//end testAnEmptyDossierCountsZero()

	/**
	 * A credit-score fetch is logged and answers no score.
	 *
	 * @return void
	 */
	public function testACreditScoreFetchIsLoggedAndAnswersNothing(): void {
		$adapter = new LogCreditScoreFetchAdapter($this->logger());

		$this->assertNull($adapter->fetch('adm-1', 'cust-4', 'graydon'));
		$this->assertSame(
			[
				[
					'Shillinq credit-score fetch deferred (no outbound connector bound)',
					['administrationId' => 'adm-1', 'customerId' => 'cust-4', 'provider' => 'graydon'],
				],
			],
			$this->logged
		);

	}//end testACreditScoreFetchIsLoggedAndAnswersNothing()
}//end class
