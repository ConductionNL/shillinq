<?php

/**
 * Characterisation tests for PeppolInboundUblInvoiceListener.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#supplierinvoice
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\Shillinq\Listener\PeppolInboundUblInvoiceListener;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\SupplierInvoiceService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Pins which messages are ingested, with what arguments, and every log record.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 * phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
 */
final class PeppolInboundUblInvoiceListenerTest extends TestCase {

	public function testOutcomesPerMessage(): void {
		$invoice = [
			'documentType' => 'Invoice',
			'administrationId' => ' adm-1 ',
			'peppolMessageId' => 'msg-1',
			'ublSourceUri' => 'urn:ubl',
			'receivedAt' => '2026-07-20T10:15:00+02:00',
			'payload' => '<Invoice/>',
		];
		$cases = [
			'otherEvent' => [null, 'PeppolInboundMessage', $invoice],
			'otherSchema' => [true, 'SomethingElse', $invoice],
			'creditNote' => [true, 'PeppolInboundMessage', ['documentType' => 'CreditNote'] + $invoice],
			'noAdministration' => [true, 'PeppolInboundMessage', ['administrationId' => '  '] + $invoice],
			'noPayload' => [true, 'PeppolInboundMessage', ['payload' => "\n "] + $invoice],
			'noPayloadNoId' => [true, 'PeppolInboundMessage', ['documentType' => 'Invoice', 'administrationId' => 'adm-2']],
			'ingested' => [true, 'PeppolInboundMessage', $invoice],
			'ingestedBare' => [true, 'PeppolInboundMessage', ['documentType' => 'Invoice', 'administrationId' => 'adm-3', 'payload' => 'x']],
			'ingestFails' => [false, 'PeppolInboundMessage', $invoice],
		];

		$actual = [];
		foreach ($cases as $name => [$ingestOk, $slug, $message]) {
			$actual[$name] = $this->outcome($ingestOk, $slug, $message);
		}

		$expectedLogs = [
			'warning',
			'PeppolInboundUblInvoiceListener: inbound Peppol Invoice without administrationId — skipping',
			['peppolMessageId' => 'msg-1'],
		];
		self::assertSame(['ingest' => [], 'logs' => []], $actual['otherEvent']);
		self::assertSame(['ingest' => [], 'logs' => []], $actual['otherSchema']);
		self::assertSame(['ingest' => [], 'logs' => []], $actual['creditNote']);
		self::assertSame(['ingest' => [], 'logs' => [$expectedLogs]], $actual['noAdministration']);
		self::assertSame(
			[
				'ingest' => [],
				'logs' => [
					[
						'warning',
						'PeppolInboundUblInvoiceListener: inbound Peppol Invoice without payload — skipping',
						['peppolMessageId' => 'msg-1'],
					],
				],
			],
			$actual['noPayload']
		);
		self::assertSame([null], array_column(array_column($actual['noPayloadNoId']['logs'], 2), 'peppolMessageId'));
		self::assertSame(
			[
				'ingest' => [
					[
						'adm-1',
						'<Invoice/>',
						[
							'peppolMessageId' => 'msg-1',
							'ublSourceUri' => 'urn:ubl',
							'peppolReceivedAt' => '2026-07-20T10:15:00+02:00',
						],
					],
				],
				'logs' => [],
			],
			$actual['ingested']
		);
		self::assertSame(
			[['adm-3', 'x', ['peppolMessageId' => '', 'ublSourceUri' => '', 'peppolReceivedAt' => '']]],
			$actual['ingestedBare']['ingest']
		);
		self::assertSame(
			[['error', 'PeppolInboundUblInvoiceListener: failed to ingest Peppol UBL Invoice', ['exception' => 'ingest down']]],
			$actual['ingestFails']['logs']
		);
	}

	/**
	 * Dispatch one message and capture the ingest calls and log records.
	 *
	 * @param bool|null $ingestOk Null for a non-create event, false when ingestion throws.
	 * @param string $slug The resolved schema slug.
	 * @param mixed $message The entity's object payload.
	 *
	 * @return array<string,mixed>
	 */
	private function outcome(bool|null $ingestOk, string $slug, mixed $message): array {
		$calls = [];
		$service = $this->createMock(SupplierInvoiceService::class);
		$service->method('ingestUBLInvoice')->willReturnCallback(
			function (string $administrationId, string $ublXml, array $context = []) use (&$calls, $ingestOk): array {
				$calls[] = [$administrationId, $ublXml, $context];
				if ($ingestOk === false) {
					throw new \RuntimeException('ingest down');
				}

				return [];
			}
		);
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('schemaSlug')->willReturn($slug);
		$logger = new class extends AbstractLogger {

			public array $records = [];

			public function log($level, \Stringable|string $message, array $context = []): void {
				$this->records[] = [(string) $level, (string) $message, $context];
			}
		};
		$listener = new PeppolInboundUblInvoiceListener($service, $resolver, $logger);

		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($message);
		$event = new Event();
		if ($ingestOk !== null) {
			$event = new ObjectCreatedEvent($entity);
		}

		$listener->handle($event);

		return ['ingest' => $calls, 'logs' => $logger->records];
	}
}
