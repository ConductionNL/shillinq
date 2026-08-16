<?php

/**
 * Unit tests for the generalised Peppol\LogPeppolTransmissionAdapter (REQ-EINV-004).
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
 * @spec openspec/changes/add-invoice-pdf-export-with-ubl-peppol-support/specs/bookkeeping-einvoicing-ubl-peppol/spec.md#req-einv-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Peppol;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\Peppol\LogPeppolTransmissionAdapter;
use OCA\Shillinq\Service\Peppol\PeppolTransmissionPortInterface;
use OCA\Shillinq\Service\PurchaseOrder\PeppolTransmissionAdapterInterface;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Covers REQ-EINV-004: Log adapter submit() fabricates a URN + logs a single
 * redacted line; lookupParticipant() searches Vendor then CustomerMaster.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class LogPeppolTransmissionAdapterTest extends TestCase {
	/**
	 * The class implements BOTH the shared port and the PO alias interface.
	 *
	 * @return void
	 */
	public function testImplementsBothInterfaces(): void {
		$adapter = $this->buildAdapter(rows: []);

		self::assertInstanceOf(PeppolTransmissionPortInterface::class, $adapter);
		self::assertInstanceOf(PeppolTransmissionAdapterInterface::class, $adapter);

	}//end testImplementsBothInterfaces()

	/**
	 * REQ-EINV-004 scenario 1: submit() returns a urn:uuid: id.
	 *
	 * @return void
	 */
	public function testSubmitFabricatesUrn(): void {
		$adapter = $this->buildAdapter(rows: []);

		$urn = $adapter->submit(
			participantId: '0106:00000000',
			documentType: 'ubl-invoice-2.1',
			payloadFileUri: 'docudesk://file/abc123'
		);

		self::assertMatchesRegularExpression('/^urn:uuid:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $urn);

	}//end testSubmitFabricatesUrn()

	/**
	 * submit() is deterministic for the same inputs (reproducible dev/CI runs).
	 *
	 * @return void
	 */
	public function testSubmitIsDeterministic(): void {
		$adapter = $this->buildAdapter(rows: []);

		$first = $adapter->submit(participantId: 'X', documentType: 'ubl-invoice-2.1', payloadFileUri: 'docudesk://file/1');
		$second = $adapter->submit(participantId: 'X', documentType: 'ubl-invoice-2.1', payloadFileUri: 'docudesk://file/1');

		self::assertSame($first, $second);

	}//end testSubmitIsDeterministic()

	/**
	 * submitOrder() (PO alias surface) still returns a urn:uuid: id, preserving
	 * the pre-generalisation call-site contract.
	 *
	 * @return void
	 */
	public function testSubmitOrderStillWorks(): void {
		$adapter = $this->buildAdapter(rows: []);

		$urn = $adapter->submitOrder(participantId: '0192:1234567890', ublOrderXml: '<Order/>');

		self::assertStringStartsWith('urn:uuid:', $urn);

	}//end testSubmitOrderStillWorks()

	/**
	 * lookupParticipant() finds a CustomerMaster row's peppolParticipantId
	 * (AR debtor side) when Vendor has no matching row.
	 *
	 * @return void
	 */
	public function testLookupParticipantFindsCustomerMaster(): void {
		$adapter = $this->buildAdapter(
			rows: [
				'Vendor' => [],
				'CustomerMaster' => [
					['administrationId' => 'adm-1', 'customerId' => 'DEB-0001', 'peppolParticipantId' => '0106:00000000'],
				],
			]
		);

		$result = $adapter->lookupParticipant(administrationId: 'adm-1', partyId: 'DEB-0001');

		self::assertSame('0106:00000000', $result);

	}//end testLookupParticipantFindsCustomerMaster()

	/**
	 * lookupParticipant() finds a Vendor row's peppolParticipantId (PO
	 * supplier side) — unchanged behaviour after generalisation.
	 *
	 * @return void
	 */
	public function testLookupParticipantFindsVendor(): void {
		$adapter = $this->buildAdapter(
			rows: [
				'Vendor' => [
					['administrationId' => 'adm-1', 'id' => 'sup-1', 'peppolParticipantId' => '0192:1234567890'],
				],
			]
		);

		$result = $adapter->lookupParticipant(administrationId: 'adm-1', partyId: 'sup-1');

		self::assertSame('0192:1234567890', $result);

	}//end testLookupParticipantFindsVendor()

	/**
	 * lookupParticipant() returns null when no schema has a matching row
	 * (graceful fallback signal).
	 *
	 * @return void
	 */
	public function testLookupParticipantReturnsNullWhenNotFound(): void {
		$adapter = $this->buildAdapter(rows: ['Vendor' => [], 'CustomerMaster' => []]);

		self::assertNull($adapter->lookupParticipant(administrationId: 'adm-1', partyId: 'unknown'));

	}//end testLookupParticipantReturnsNullWhenNotFound()

	/**
	 * Empty administrationId/partyId short-circuits to null without querying.
	 *
	 * @return void
	 */
	public function testEmptyInputsShortCircuit(): void {
		$adapter = $this->buildAdapter(rows: []);

		self::assertNull($adapter->lookupParticipant(administrationId: '', partyId: 'DEB-0001'));
		self::assertNull($adapter->lookupParticipant(administrationId: 'adm-1', partyId: ''));

	}//end testEmptyInputsShortCircuit()

	/**
	 * Build an adapter over a stub ObjectService that serves the given
	 * schema => rows map and applies equality filters.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $rows Schema => rows.
	 *
	 * @return LogPeppolTransmissionAdapter
	 */
	private function buildAdapter(array $rows): LogPeppolTransmissionAdapter {
		// ADR-084: the adapter takes ObjectServiceInterface, not the container, so
		// the double has to BE that interface rather than merely quack like it.
		// createMock() gives the whole 25-method surface for free and, crucially,
		// is regenerated from the real interface — a signature that moves upstream
		// breaks this double instead of leaving it quietly answering the old shape.
		$schema = '';
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnCallback(
			static function (string|int $requested) use (&$schema, $objectService): ObjectServiceInterface {
				$schema = (string)$requested;
				return $objectService;
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			static function (array $config = []) use (&$schema, $rows): array {
				$candidates = ($rows[$schema] ?? []);
				$filters = ($config['filters'] ?? []);

				return array_values(
					array_filter(
						$candidates,
						static function (array $row) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if (($row[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		return new LogPeppolTransmissionAdapter(
			objectService: $objectService,
			appConfig: $appConfig,
			logger: new NullLogger()
		);

	}//end buildAdapter()

}//end class
