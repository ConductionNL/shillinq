<?php

/**
 * Self-billed Invoice Intake
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Takes the UBL document integriq received over Peppol for this instance
 * (CloudEvent `nl.conduction.peppol.inbound.received`, data {senderPeppolId,
 * documentType, payloadReference}) and decides where it goes (design D5):
 *
 * - a self-billed invoice (Peppol self-billing customization, or invoice type
 *   code 389) that a customer drew up on an administration's behalf becomes a
 *   draft `ARInvoice` with `selfBilled` true, once per sender and the
 *   customer's invoice number, but only when the seller party is an
 *   administration and the buyer party is one of its customers with a current
 *   self-billing agreement (article 224 VAT Directive). Otherwise a
 *   `SelfBilledInvoiceRefusal` names the reason, so nothing received
 *   disappears;
 * - every other invoice goes to the supplier invoice intake,
 *   {@see SupplierInvoiceService::ingestUBLInvoice()}, for the administration
 *   its buyer party names.
 *
 * The header, totals and lines are read with
 * {@see SupplierInvoiceService::parseUblInvoice()}; only the parties, the
 * self-billing markers and the VAT breakdown are read here.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\EInvoice
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-3.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\EInvoice;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\AppInfo\AppIdentity;
use OCA\Shillinq\Service\SupplierInvoiceService;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use SimpleXMLElement;
use Throwable;

/**
 * Turns a received self-billed invoice into a draft sales invoice, or refuses it.
 *
 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-3.2
 */
class SelfBilledInvoiceIntake {
	/**
	 * The Peppol BIS self-billing customization (CustomizationID) marker.
	 *
	 * @var string
	 */
	public const SELF_BILLING_CUSTOMIZATION = 'urn:fdc:peppol.eu:2017:poacc:selfbilling:3.0';

	/**
	 * UNTDID 1001 code of a self-billed invoice (InvoiceTypeCode).
	 *
	 * @var string
	 */
	public const SELF_BILLED_TYPE_CODE = '389';

	/**
	 * The mention article 226(10a) VAT Directive requires on the invoice.
	 *
	 * @var string
	 */
	public const SELF_BILLING_MENTION = 'Factuur uitgereikt door afnemer';

	/**
	 * Refusal reasons, the texts the bookkeeper reads on the refusal record.
	 *
	 * @var array<string,string>
	 */
	public const REASONS = [
		'unreadable' => 'The received document could not be read',
		'administration' => 'No administration with this seller VAT id or KvK number',
		'customer' => 'No customer with this VAT id, Peppol id or KvK number',
		'agreement' => 'No self-billing agreement is recorded for this customer',
		'expired' => 'The self-billing agreement does not cover the invoice date',
	];

	/**
	 * UBL namespaces the party and marker reads use.
	 *
	 * @var array<string,string>
	 */
	private const NAMESPACES = [
		'cbc' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
		'cac' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service (ADR-083).
	 * @param SupplierInvoiceService $supplierInvoices The UBL parser and the supplier invoice intake.
	 * @param IRootFolder            $rootFolder       Reads the received document from Files.
	 * @param IAppConfig             $appConfig        App config for the register slug.
	 * @param ITimeFactory           $timeFactory      The clock for the received time.
	 * @param LoggerInterface        $logger           Logger; no document bodies are logged.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly SupplierInvoiceService $supplierInvoices,
		private readonly IRootFolder $rootFolder,
		private readonly IAppConfig $appConfig,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Take one inbound document integriq reported.
	 *
	 * @param array<string,mixed> $data The CloudEvent data: {senderPeppolId, documentType, payloadReference}.
	 *
	 * @return array{result:string,reason?:string,id?:string} `drafted`, `duplicate`, `refused`,
	 *                                                        `supplier` or `skipped` (not an
	 *                                                        invoice, or no administration).
	 *
	 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-3.2
	 */
	public function receive(array $data): array {
		$received = new InboundDocument(
			senderPeppolId: trim((string)($data['senderPeppolId'] ?? '')),
			payloadReference: trim((string)($data['payloadReference'] ?? '')),
			receivedAt: $this->timeFactory->getDateTime()->format(DATE_ATOM)
		);

		$xml = $this->readPayload(reference: $received->payloadReference);
		if ($xml === null) {
			return $this->refuse(received: $received, document: [], reason: 'unreadable');
		}

		try {
			$parties = $this->readParties(ublXml: $xml);
			if ($parties['isInvoice'] === false) {
				// A credit note or any other document is not taken here (out of scope).
				$this->logger->info('SelfBilledInvoiceIntake: the received document is not an invoice; skipped');
				return ['result' => 'skipped', 'reason' => 'document'];
			}

			$document = $this->supplierInvoices->parseUblInvoice(ublXml: $xml) + $parties;
		} catch (Throwable $e) {
			$this->logger->info('SelfBilledInvoiceIntake: the received document is not a UBL invoice', ['exception' => $e->getMessage()]);
			return $this->refuse(received: $received, document: [], reason: 'unreadable');
		}

		if ($document['selfBilled'] === false) {
			return $this->toSupplierIntake(received: $received, document: $document, ublXml: $xml);
		}

		return $this->selfBilled(received: $received, document: $document);
	}//end receive()

	/**
	 * Whether an agreement covers a date: agreed on or before it, and not ended.
	 *
	 * @param mixed  $agreement The customer's `selfBillingAgreement`.
	 * @param string $date      The invoice date (Y-m-d).
	 *
	 * @return string|null Null when it covers the date, else the refusal reason key.
	 *
	 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-3.2
	 */
	public static function agreementGap(mixed $agreement, string $date): ?string {
		if (is_array($agreement) === false) {
			return 'agreement';
		}

		$agreedOn = substr(trim((string)($agreement['agreedOn'] ?? '')), 0, 10);
		if ($agreedOn === '') {
			return 'agreement';
		}

		$validUntil = substr(trim((string)($agreement['validUntil'] ?? '')), 0, 10);
		if ($date === '' || $date < $agreedOn || ($validUntil !== '' && $date > $validUntil)) {
			return 'expired';
		}

		return null;
	}//end agreementGap()

	/**
	 * Match the parties and the agreement, then write the draft or a refusal.
	 *
	 * @param InboundDocument     $received The delivery.
	 * @param array<string,mixed> $document The parsed document.
	 *
	 * @return array{result:string,reason?:string,id?:string}
	 */
	private function selfBilled(InboundDocument $received, array $document): array {
		$administration = $this->administrationFor(party: $document['seller']);
		if ($administration === null) {
			return $this->refuse(received: $received, document: $document, reason: 'administration');
		}

		$administrationId = (string)($administration['id'] ?? '');
		$customer         = $this->customerFor(administrationId: $administrationId, party: $document['buyer']);
		if ($customer === null) {
			return $this->refuse(received: $received, document: $document, reason: 'customer', administrationId: $administrationId);
		}

		$gap = self::agreementGap(agreement: ($customer['selfBillingAgreement'] ?? null), date: (string)$document['invoiceDate']);
		if ($gap !== null) {
			return $this->refuse(received: $received, document: $document, reason: $gap, administrationId: $administrationId);
		}

		$existing = $this->existingDraft(administrationId: $administrationId, customer: $customer, document: $document, received: $received);
		if ($existing !== null) {
			return ['result' => 'duplicate', 'id' => (string)($existing['id'] ?? '')];
		}

		$saved = $this->save(
			schema: 'ARInvoice',
			object: $this->draftInvoice(administrationId: $administrationId, customer: $customer, document: $document, received: $received)
		);

		return ['result' => 'drafted', 'id' => ObjectIdentifier::resolve(saved: $saved)];
	}//end selfBilled()

	/**
	 * The draft ARInvoice for a matched self-billed invoice.
	 *
	 * @param string              $administrationId The administration.
	 * @param array<string,mixed> $customer         The matched CustomerMaster.
	 * @param array<string,mixed> $document         The parsed document.
	 * @param InboundDocument     $received         The delivery.
	 *
	 * @return array<string,mixed>
	 */
	private function draftInvoice(string $administrationId, array $customer, array $document, InboundDocument $received): array {
		$invoiceDate = (string)$document['invoiceDate'];
		$dueDate     = (string)$document['dueDate'];
		if ($dueDate === '') {
			$dueDate = $invoiceDate;
		}

		return [
			'invoiceNumber' => (string)$document['invoiceNumber'],
			'administrationId' => $administrationId,
			'periodId' => substr($invoiceDate, 0, 7),
			'customerId' => (string)($customer['customerId'] ?? ''),
			'invoiceDate' => $invoiceDate,
			'dueDate' => $dueDate,
			'currency' => (string)$document['currency'],
			'netAmount' => self::amount(cents: $document['totalExclVat'] ?? 0),
			'vatAmount' => self::amount(cents: $document['totalVat'] ?? 0),
			'grossAmount' => self::amount(cents: $document['totalInclVat'] ?? 0),
			'lifecycleState' => 'draft',
			'deliveryStatus' => 'not-sent',
			'sellerVatId' => (string)$document['seller']['vatId'],
			'buyerVatId' => (string)$document['buyer']['vatId'],
			'selfBilled' => true,
			'selfBillingMention' => self::SELF_BILLING_MENTION,
			'selfBilling' => [
				'buyerInvoiceNumber' => (string)$document['invoiceNumber'],
				'receivedAt' => $received->receivedAt,
				'payloadFileUri' => $received->payloadReference,
				'senderPeppolId' => $received->senderPeppolId,
			],
			'invoiceLines' => self::invoiceLines(lines: (array)($document['lines'] ?? []), breakdown: $document['vatBreakdown']),
			'vatBreakdown' => $document['vatBreakdown'],
		];
	}//end draftInvoice()

	/**
	 * The parsed lines in ARInvoice's EN 16931 line shape.
	 *
	 * @param array<int,array<string,mixed>> $lines     Lines from parseUblInvoice() (amounts in cents, rate a fraction).
	 * @param array<int,array<string,mixed>> $breakdown The document's VAT breakdown.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function invoiceLines(array $lines, array $breakdown): array {
		$categoryByRate = [];
		foreach ($breakdown as $group) {
			$categoryByRate[(string)(float)($group['rate'] ?? 0)] = (string)$group['category'];
		}

		$result = [];
		foreach (array_values($lines) as $position => $line) {
			// The parser parseUblInvoice() answers the rate as a fraction; ARInvoice keeps percent.
			$rate     = round(((float)($line['vatRate'] ?? 0)) * 100, 2);
			$result[] = [
				'lineId' => (string)($line['lineNumber'] ?? ($position + 1)),
				'quantity' => (float)($line['quantity'] ?? 0),
				'unitCode' => 'C62',
				'itemName' => (string)($line['description'] ?? ''),
				'netPrice' => self::amount(cents: $line['unitPrice'] ?? 0),
				'netAmount' => self::amount(cents: $line['lineExtension'] ?? 0),
				'vatCategory' => ($categoryByRate[(string)$rate] ?? self::defaultCategory(rate: $rate)),
				'vatRate' => $rate,
			];
		}

		return $result;
	}//end invoiceLines()

	/**
	 * The VAT category of a line whose rate no breakdown group names.
	 *
	 * @param float $rate The rate in percent.
	 *
	 * @return string `S` for a positive rate, else `Z`.
	 */
	private static function defaultCategory(float $rate): string {
		if ($rate > 0) {
			return 'S';
		}

		return 'Z';
	}//end defaultCategory()

	/**
	 * A cent amount as the decimal ARInvoice stores.
	 *
	 * @param mixed $cents Integer cents.
	 *
	 * @return float
	 */
	private static function amount(mixed $cents): float {
		return round(((int)$cents) / 100, 2);
	}//end amount()

	/**
	 * An earlier draft of the same self-billed invoice from the same sender.
	 *
	 * @param string              $administrationId The administration.
	 * @param array<string,mixed> $customer         The matched customer.
	 * @param array<string,mixed> $document         The parsed document.
	 * @param InboundDocument     $received         The delivery.
	 *
	 * @return array<string,mixed>|null
	 */
	private function existingDraft(string $administrationId, array $customer, array $document, InboundDocument $received): ?array {
		$rows = $this->findAll(
			schema: 'ARInvoice',
			filters: [
				'administrationId' => $administrationId,
				'customerId' => (string)($customer['customerId'] ?? ''),
				'invoiceNumber' => (string)$document['invoiceNumber'],
			]
		);
		foreach ($rows as $row) {
			$sender = (string)($row['selfBilling']['senderPeppolId'] ?? '');
			if (($row['selfBilled'] ?? false) === true && strcasecmp($sender, $received->senderPeppolId) === 0) {
				return $row;
			}
		}

		return null;
	}//end existingDraft()

	/**
	 * Hand an ordinary invoice to the supplier invoice intake.
	 *
	 * @param InboundDocument     $received The delivery.
	 * @param array<string,mixed> $document The parsed document.
	 * @param string              $ublXml   The UBL document.
	 *
	 * @return array{result:string,reason?:string,id?:string}
	 *
	 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-3.3
	 */
	private function toSupplierIntake(InboundDocument $received, array $document, string $ublXml): array {
		$administration = $this->administrationFor(party: $document['buyer']);
		if ($administration === null) {
			$this->logger->warning(
				'SelfBilledInvoiceIntake: an inbound invoice names no administration as its buyer; skipped',
				['senderPeppolId' => $received->senderPeppolId]
			);
			return ['result' => 'skipped', 'reason' => 'administration'];
		}

		$saved = $this->supplierInvoices->ingestUBLInvoice(
			administrationId: (string)($administration['id'] ?? ''),
			ublXml: $ublXml,
			context: [
				'ublSourceUri' => $received->payloadReference,
				'peppolReceivedAt' => $received->receivedAt,
			]
		);

		return ['result' => 'supplier', 'id' => (string)($saved['id'] ?? '')];
	}//end toSupplierIntake()

	/**
	 * Record why a received self-billed invoice did not become a sales invoice.
	 *
	 * A repeated delivery of the same refused document is recorded once.
	 *
	 * @param InboundDocument     $received         The delivery.
	 * @param array<string,mixed> $document         The parsed document, empty when unreadable.
	 * @param string              $reason           Key into {@see self::REASONS}.
	 * @param string              $administrationId The administration, when one matched.
	 *
	 * @return array{result:string,reason:string,id?:string}
	 */
	private function refuse(InboundDocument $received, array $document, string $reason, string $administrationId = ''): array {
		$refusal = [
			'receivedAt' => $received->receivedAt,
			'senderPeppolId' => $received->senderPeppolId,
			'buyerInvoiceNumber' => (string)($document['invoiceNumber'] ?? ''),
			'reason' => self::REASONS[$reason],
			'payloadFileUri' => $received->payloadReference,
			'administrationId' => $administrationId,
		];

		$earlier = $this->findAll(
			schema: 'SelfBilledInvoiceRefusal',
			filters: [
				'senderPeppolId' => $refusal['senderPeppolId'],
				'buyerInvoiceNumber' => $refusal['buyerInvoiceNumber'],
				'reason' => $refusal['reason'],
			]
		);
		if ($earlier !== []) {
			return ['result' => 'refused', 'reason' => $reason, 'id' => (string)($earlier[0]['id'] ?? '')];
		}

		$this->logger->info('SelfBilledInvoiceIntake: received self-billed invoice refused', ['reason' => $reason]);
		$saved = $this->save(schema: 'SelfBilledInvoiceRefusal', object: $refusal);

		return ['result' => 'refused', 'reason' => $reason, 'id' => ObjectIdentifier::resolve(saved: $saved)];
	}//end refuse()

	/**
	 * The administration a party names, by VAT id or KvK number.
	 *
	 * @param array{vatId:string,kvk:string,endpoint:string} $party The party.
	 *
	 * @return array<string,mixed>|null
	 */
	private function administrationFor(array $party): ?array {
		foreach ($this->findAll(schema: 'Administration', filters: []) as $administration) {
			$byVat = (self::sameId(left: $party['vatId'], right: ($administration['vatNumber'] ?? '')) === true
				|| self::sameId(left: $party['vatId'], right: ($administration['fiscalUnitVatId'] ?? '')) === true);
			if ($byVat === true || self::sameId(left: $party['kvk'], right: ($administration['kvkNumber'] ?? '')) === true) {
				return $administration;
			}
		}

		return null;
	}//end administrationFor()

	/**
	 * The administration's customer a party names, by Peppol id, VAT id or KvK number.
	 *
	 * @param string                                         $administrationId The administration.
	 * @param array{vatId:string,kvk:string,endpoint:string} $party            The party.
	 *
	 * @return array<string,mixed>|null
	 */
	private function customerFor(string $administrationId, array $party): ?array {
		foreach ($this->findAll(schema: 'CustomerMaster', filters: ['administrationId' => $administrationId]) as $customer) {
			$byPeppolOrVat = (self::sameId(left: $party['endpoint'], right: ($customer['peppolParticipantId'] ?? '')) === true
				|| self::sameId(left: $party['vatId'], right: ($customer['vatId'] ?? '')) === true);
			if ($byPeppolOrVat === true || self::sameId(left: $party['kvk'], right: ($customer['kvkNumber'] ?? '')) === true) {
				return $customer;
			}
		}

		return null;
	}//end customerFor()

	/**
	 * Whether two identifiers are the same, ignoring case and spacing; empty never matches.
	 *
	 * @param mixed $left  One identifier.
	 * @param mixed $right The other.
	 *
	 * @return bool
	 */
	private static function sameId(mixed $left, mixed $right): bool {
		$normalise = static fn (mixed $value): string => strtoupper((string)preg_replace('/[\s.]+/', '', (string)$value));
		$one       = $normalise($left);

		return $one !== '' && $one === $normalise($right);
	}//end sameId()

	/**
	 * The parties, the self-billing markers and the VAT breakdown of a UBL invoice.
	 *
	 * @param string $ublXml The UBL document.
	 *
	 * @return array{isInvoice:bool,selfBilled:bool,seller:array{vatId:string,kvk:string,endpoint:string},buyer:array{vatId:string,kvk:string,endpoint:string},vatBreakdown:array<int,array<string,mixed>>}
	 */
	private function readParties(string $ublXml): array {
		$previous = libxml_use_internal_errors(true);
		try {
			$xml = new SimpleXMLElement($ublXml, LIBXML_NONET);
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}

		foreach (self::NAMESPACES as $prefix => $uri) {
			$xml->registerXPathNamespace($prefix, $uri);
		}

		$customization = self::first(xml: $xml, path: '/*/cbc:CustomizationID');
		$typeCode      = self::first(xml: $xml, path: '/*/cbc:InvoiceTypeCode');

		return [
			'isInvoice' => ($xml->getName() === 'Invoice'),
			'selfBilled' => (str_contains($customization, 'poacc:selfbilling') === true || $typeCode === self::SELF_BILLED_TYPE_CODE),
			'seller' => self::party(xml: $xml, role: 'cac:AccountingSupplierParty'),
			'buyer' => self::party(xml: $xml, role: 'cac:AccountingCustomerParty'),
			'vatBreakdown' => self::breakdown(xml: $xml),
		];
	}//end readParties()

	/**
	 * One party's identifiers.
	 *
	 * @param SimpleXMLElement $xml  The document.
	 * @param string           $role The party element.
	 *
	 * @return array{vatId:string,kvk:string,endpoint:string}
	 */
	private static function party(SimpleXMLElement $xml, string $role): array {
		$base     = '/*/' . $role . '/cac:Party';
		$endpoint = self::first(xml: $xml, path: $base . '/cbc:EndpointID');
		$scheme   = self::first(xml: $xml, path: $base . '/cbc:EndpointID/@schemeID');
		if ($scheme !== '' && $endpoint !== '') {
			$endpoint = $scheme . ':' . $endpoint;
		}

		return [
			'vatId' => self::first(xml: $xml, path: $base . '/cac:PartyTaxScheme/cbc:CompanyID'),
			'kvk' => self::first(xml: $xml, path: $base . '/cac:PartyLegalEntity/cbc:CompanyID'),
			'endpoint' => $endpoint,
		];
	}//end party()

	/**
	 * The document's VAT breakdown (BG-23), in ARInvoice's shape.
	 *
	 * @param SimpleXMLElement $xml The document.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function breakdown(SimpleXMLElement $xml): array {
		$groups    = [];
		$subtotals = $xml->xpath('/*/cac:TaxTotal/cac:TaxSubtotal');
		if (is_array($subtotals) === false) {
			return $groups;
		}

		foreach ($subtotals as $subtotal) {
			foreach (self::NAMESPACES as $prefix => $uri) {
				$subtotal->registerXPathNamespace($prefix, $uri);
			}

			$groups[] = [
				'category' => self::first(xml: $subtotal, path: 'cac:TaxCategory/cbc:ID'),
				'taxableAmount' => round((float)self::first(xml: $subtotal, path: 'cbc:TaxableAmount'), 2),
				'taxAmount' => round((float)self::first(xml: $subtotal, path: 'cbc:TaxAmount'), 2),
				'rate' => (float)self::first(xml: $subtotal, path: 'cac:TaxCategory/cbc:Percent'),
			];
		}

		return $groups;
	}//end breakdown()

	/**
	 * The trimmed text of the first node an XPath finds, or ''.
	 *
	 * @param SimpleXMLElement $xml  The context node.
	 * @param string           $path The XPath.
	 *
	 * @return string
	 */
	private static function first(SimpleXMLElement $xml, string $path): string {
		$nodes = $xml->xpath($path);
		if (is_array($nodes) === false || $nodes === []) {
			return '';
		}

		return trim((string)$nodes[0]);
	}//end first()

	/**
	 * The received document's content, when the reference names a readable file.
	 *
	 * @param string $reference A Files path such as `/<user>/files/...`.
	 *
	 * @return string|null
	 */
	private function readPayload(string $reference): ?string {
		if (str_starts_with($reference, '/') === false) {
			return null;
		}

		try {
			$node = $this->rootFolder->get($reference);
			if ($node instanceof File === false) {
				return null;
			}

			$content = $node->getContent();
		} catch (Throwable $e) {
			$this->logger->info('SelfBilledInvoiceIntake: the received document could not be read', ['exception' => $e->getMessage()]);
			return null;
		}

		if (trim($content) === '') {
			return null;
		}

		return $content;
	}//end readPayload()

	/**
	 * Save an object in shillinq's register.
	 *
	 * @param string              $schema The schema slug.
	 * @param array<string,mixed> $object The object.
	 *
	 * @return mixed What the object service answered.
	 */
	private function save(string $schema, array $object): mixed {
		return $this->objectService
			->setRegister($this->register())
			->setSchema($schema)
			->saveObject($object);
	}//end save()

	/**
	 * Matching records of a schema in shillinq's register, entity rows included.
	 *
	 * @param string              $schema  The schema slug.
	 * @param array<string,mixed> $filters Equality filters.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function findAll(string $schema, array $filters): array {
		$rows = $this->objectService
			->setRegister($this->register())
			->setSchema($schema)
			->findAll(['filters' => $filters]);

		$records = [];
		foreach ($rows as $row) {
			$record = ObjectIdentifier::recordWithId(candidate: $row);
			if ($record !== null) {
				$records[] = $record;
			}
		}

		return $records;
	}//end findAll()

	/**
	 * The register slug from app config, defaulting to `shillinq`.
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');

		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
