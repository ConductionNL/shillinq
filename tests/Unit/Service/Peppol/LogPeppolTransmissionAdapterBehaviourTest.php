<?php

/**
 * Behaviour tests for LogPeppolTransmissionAdapter: the exact URN derivation,
 * what each submit logs (and does not), and the exact lookup queries and their
 * Vendor-before-CustomerMaster precedence.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Peppol
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

namespace OCA\Shillinq\Tests\Unit\Service\Peppol;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\Peppol\LogPeppolTransmissionAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * LogPeppolTransmissionAdapter behaviour.
 *
 * @covers \OCA\Shillinq\Service\Peppol\LogPeppolTransmissionAdapter
 */
final class LogPeppolTransmissionAdapterBehaviourTest extends TestCase {

	/**
	 * Queries made: [register, schema, config].
	 *
	 * @var list<array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $queries = [];

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->logger = $this->createMock(LoggerInterface::class);
		$this->queries = [];

	}//end setUp()

	/**
	 * Build the adapter over rows per schema.
	 *
	 * @param array<string, list<mixed>> $rows Rows returned per schema
	 *
	 * @return LogPeppolTransmissionAdapter
	 */
	private function adapter(array $rows = []): LogPeppolTransmissionAdapter {
		$register = '';
		$schema = '';
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnCallback(
			static function (string|int $value) use (&$register, &$objectService): ObjectServiceInterface {
				$register = (string)$value;
				return $objectService;
			}
		);
		$objectService->method('setSchema')->willReturnCallback(
			static function (string|int $value) use (&$schema, &$objectService): ObjectServiceInterface {
				$schema = (string)$value;
				return $objectService;
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config = []) use (&$register, &$schema, $rows): array {
				$this->queries[] = [$register, $schema, $config];
				return ($rows[$schema] ?? []);
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->with('shillinq', 'register', 'shillinq')->willReturn('books');

		return new LogPeppolTransmissionAdapter(objectService: $objectService, appConfig: $appConfig, logger: $this->logger);

	}//end adapter()

	/**
	 * The URN is the first 32 hex characters of the SHA-256 of the seed, laid out as a UUID.
	 *
	 * @return void
	 */
	public function testSubmitUrnIsDerivedFromAllThreeInputs(): void {
		$adapter = $this->adapter();
		$hash = hash('sha256', '0106:1:ubl-invoice-2.1:docudesk://file/1');
		$expected = 'urn:uuid:' . substr($hash, 0, 8) . '-' . substr($hash, 8, 4) . '-' . substr($hash, 12, 4)
			. '-' . substr($hash, 16, 4) . '-' . substr($hash, 20, 12);

		$urn = $adapter->submit(participantId: '0106:1', documentType: 'ubl-invoice-2.1', payloadFileUri: 'docudesk://file/1');

		$this->assertSame($expected, $urn);
		$this->assertNotSame($urn, $adapter->submit(participantId: '0106:2', documentType: 'ubl-invoice-2.1', payloadFileUri: 'docudesk://file/1'));
		$this->assertNotSame($urn, $adapter->submit(participantId: '0106:1', documentType: 'ubl-creditnote-2.1', payloadFileUri: 'docudesk://file/1'));
		$this->assertNotSame($urn, $adapter->submit(participantId: '0106:1', documentType: 'ubl-invoice-2.1', payloadFileUri: 'docudesk://file/2'));

	}//end testSubmitUrnIsDerivedFromAllThreeInputs()

	/**
	 * submit() logs one line with the participant, type and payload reference.
	 *
	 * @return void
	 */
	public function testSubmitLogsTheTransmission(): void {
		$this->logger->expects($this->once())
			->method('info')
			->with(
				'shillinq.peppol.submit',
				['participantId' => '0106:1', 'documentType' => 'ubl-invoice-2.1', 'payloadFileUri' => 'docudesk://file/1']
			);

		$this->adapter()->submit(participantId: '0106:1', documentType: 'ubl-invoice-2.1', payloadFileUri: 'docudesk://file/1');

	}//end testSubmitLogsTheTransmission()

	/**
	 * submitOrder() logs the document length, never the document, and derives the URN from participant and XML.
	 *
	 * @return void
	 */
	public function testSubmitOrderLogsOnlyTheLength(): void {
		$xml = '<Order><ID>PO-1</ID></Order>';
		$this->logger->expects($this->once())
			->method('info')
			->with('shillinq.peppol.submit', ['participantId' => '0192:9', 'ublLength' => strlen($xml)]);

		$urn = $this->adapter()->submitOrder(participantId: '0192:9', ublOrderXml: $xml);

		$hash = hash('sha256', '0192:9:' . $xml);
		$this->assertSame('urn:uuid:' . substr($hash, 0, 8) . '-' . substr($hash, 8, 4), substr($urn, 0, 22));

	}//end testSubmitOrderLogsOnlyTheLength()

	/**
	 * A Vendor match wins and CustomerMaster is not queried; each schema is filtered on its own id field.
	 *
	 * @return void
	 */
	public function testVendorMatchWinsWithExactQuery(): void {
		$adapter = $this->adapter(
			[
				'Vendor' => [['peppolParticipantId' => ' 0192:111 ']],
				'CustomerMaster' => [['peppolParticipantId' => '0106:222']],
			]
		);

		$this->assertSame('0192:111', $adapter->lookupParticipant(administrationId: 'adm-1', partyId: 'P-1'));
		$this->assertSame(
			[['books', 'Vendor', ['filters' => ['administrationId' => 'adm-1', 'id' => 'P-1']]]],
			$this->queries
		);

	}//end testVendorMatchWinsWithExactQuery()

	/**
	 * Without a usable Vendor id the CustomerMaster is queried on customerId.
	 *
	 * @return void
	 */
	public function testFallsThroughToCustomerMaster(): void {
		$adapter = $this->adapter(
			[
				'Vendor' => ['not-a-row', ['peppolParticipantId' => '   '], ['name' => 'no id']],
				'CustomerMaster' => [['peppolParticipantId' => '0106:222']],
			]
		);

		$this->assertSame('0106:222', $adapter->lookupParticipant(administrationId: 'adm-1', partyId: 'DEB-1'));
		$this->assertSame(
			[
				['books', 'Vendor', ['filters' => ['administrationId' => 'adm-1', 'id' => 'DEB-1']]],
				['books', 'CustomerMaster', ['filters' => ['administrationId' => 'adm-1', 'customerId' => 'DEB-1']]],
			],
			$this->queries
		);

	}//end testFallsThroughToCustomerMaster()

	/**
	 * Empty inputs query nothing.
	 *
	 * @return void
	 */
	public function testEmptyInputsQueryNothing(): void {
		$adapter = $this->adapter();

		$this->assertNull($adapter->lookupParticipant(administrationId: '', partyId: ''));
		$this->assertSame([], $this->queries);

	}//end testEmptyInputsQueryNothing()
}//end class
