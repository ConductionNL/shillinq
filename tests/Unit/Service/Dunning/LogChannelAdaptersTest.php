<?php

/**
 * Unit tests for the log-only dunning channel adapters.
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

use OCA\Shillinq\Service\Dunning\CreditScoreFetchAdapterInterface;
use OCA\Shillinq\Service\Dunning\IncassoBureauAdapterInterface;
use OCA\Shillinq\Service\Dunning\LogCreditScoreFetchAdapter;
use OCA\Shillinq\Service\Dunning\LogIncassoBureauAdapter;
use OCA\Shillinq\Service\Dunning\LogPostNLAdapter;
use OCA\Shillinq\Service\Dunning\PostNLAdapterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Without an outbound connector, each channel logs what it would have sent,
 * leaves the letter bytes out of the log, and answers in the shape the
 * dunning run expects.
 *
 * @covers \OCA\Shillinq\Service\Dunning\LogPostNLAdapter
 * @covers \OCA\Shillinq\Service\Dunning\LogIncassoBureauAdapter
 * @covers \OCA\Shillinq\Service\Dunning\LogCreditScoreFetchAdapter
 * @uses   \OCA\Shillinq\Kernel\Model\DunningChannelSendResult
 */
class LogChannelAdaptersTest extends TestCase {

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Build the logger before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Each adapter implements the channel interface it stands in for.
	 *
	 * @return void
	 */
	public function testEachAdapterImplementsItsChannel(): void {
		$this->assertInstanceOf(PostNLAdapterInterface::class, new LogPostNLAdapter($this->logger));
		$this->assertInstanceOf(IncassoBureauAdapterInterface::class, new LogIncassoBureauAdapter($this->logger));
		$this->assertInstanceOf(CreditScoreFetchAdapterInterface::class, new LogCreditScoreFetchAdapter($this->logger));
	}//end testEachAdapterImplementsItsChannel()

	/**
	 * A registered letter is logged without its PDF bytes and answered with a PostNL barcode and tracking URL.
	 *
	 * @return void
	 */
	public function testARegisteredLetterIsLoggedWithoutItsBytes(): void {
		$logged = null;
		$this->logger->expects($this->once())
			->method('info')
			->with('Shillinq PostNL registered-letter send deferred (no outbound connector bound)')
			->willReturnCallback(
				static function (string $message, array $context) use (&$logged): void {
					$logged = $context;
				}
			);

		$result = (new LogPostNLAdapter($this->logger))->sendRegisteredLetter(
			['invoiceId' => 'inv-1', 'letterPdfBytes' => '%PDF-secret', 'address' => 'Dorpsstraat 1']
		);

		$this->assertSame(['invoiceId' => 'inv-1', 'address' => 'Dorpsstraat 1'], $logged['payload']);
		$barcode = $result->extras['barcode'];
		$this->assertMatchesRegularExpression('/^3S\d{13}$/', $barcode);
		$this->assertSame($barcode, $logged['barcode']);
		$this->assertSame('https://postnl.nl/tracktrace/'.$barcode, $result->extras['trackingUrl']);
		$this->assertSame('REGISTERED_POST', $result->channel);
		$this->assertSame('DELIVERED', $result->deliveryStatus);
		$this->assertMatchesRegularExpression('/^postnl-log-[0-9a-f]{16}$/', (string)$result->providerMessageId);
		$this->assertNull($result->errorMessage);
	}//end testARegisteredLetterIsLoggedWithoutItsBytes()

	/**
	 * An incasso transfer logs counts, not the dossier, and answers a stub dossier id.
	 *
	 * @return void
	 */
	public function testAnIncassoTransferLogsCountsOnly(): void {
		$this->logger->expects($this->once())
			->method('info')
			->with(
				'Shillinq incasso-bureau transfer deferred (no outbound connector bound)',
				['administrationId' => 'adm-1', 'invoiceId' => 'inv-1', 'dunningRuns' => 3, 'evidenceRefs' => 1]
			);

		$result = (new LogIncassoBureauAdapter($this->logger))->transfer(
			'adm-1',
			'inv-1',
			['content' => ['dunningRuns' => ['r1', 'r2', 'r3'], 'evidenceRefs' => ['e1'], 'debtor' => 'private']]
		);

		$this->assertSame('COLLECTION_AGENCY_API', $result->channel);
		$this->assertSame('DELIVERED', $result->deliveryStatus);
		$this->assertMatchesRegularExpression('/^incasso-log-[0-9a-f]{16}$/', (string)$result->providerMessageId);
		$this->assertMatchesRegularExpression('/^dossier-stub-[0-9a-f]{12}$/', $result->extras['dossierId']);
	}//end testAnIncassoTransferLogsCountsOnly()

	/**
	 * A dossier without runs or evidence logs zero of each.
	 *
	 * @return void
	 */
	public function testAnEmptyDossierLogsZeroCounts(): void {
		$this->logger->expects($this->once())
			->method('info')
			->with($this->anything(), ['administrationId' => 'adm-1', 'invoiceId' => 'inv-2', 'dunningRuns' => 0, 'evidenceRefs' => 0]);

		(new LogIncassoBureauAdapter($this->logger))->transfer('adm-1', 'inv-2', []);
	}//end testAnEmptyDossierLogsZeroCounts()

	/**
	 * A credit-score fetch is logged and answers no score.
	 *
	 * @return void
	 */
	public function testACreditScoreFetchAnswersNoScore(): void {
		$this->logger->expects($this->once())
			->method('info')
			->with(
				'Shillinq credit-score fetch deferred (no outbound connector bound)',
				['administrationId' => 'adm-1', 'customerId' => 'cust-9', 'provider' => 'graydon']
			);

		$this->assertNull((new LogCreditScoreFetchAdapter($this->logger))->fetch('adm-1', 'cust-9', 'graydon'));
	}//end testACreditScoreFetchAnswersNoScore()
}//end class
