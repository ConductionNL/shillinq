<?php

/**
 * VAT supplementary return filing service.
 *
 * The last step of correcting a filed period (REQ-VPS-002): a prepared
 * correction becomes a supplementary return document with its per-box
 * differences, which is filed through the Digipoort port when a connection
 * is bound, and downloaded to file by hand while none is.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Tax
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-2.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Tax;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Service\External\Digipoort\DigipoortSbrAdapterInterface;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Builds, downloads and files the supplementary return of a prepared correction.
 *
 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-2.2
 */
class VatSuppletieFilingService {

	/**
	 * The filing type handed to the Digipoort port.
	 *
	 * @var string
	 */
	public const FILING_TYPE = 'btw-suppletie';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface       $objectService The object store.
	 * @param DigipoortSbrAdapterInterface $digipoort     The Digipoort port.
	 * @param IAppConfig                   $appConfig     The register slug.
	 * @param LoggerInterface              $logger        Logger.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly DigipoortSbrAdapterInterface $digipoort,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether a supplementary return can be filed from here: a Digipoort connection is bound.
	 *
	 * @return bool False while only the log adapter is bound.
	 *
	 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-2.2
	 */
	public function canFile(): bool {
		return $this->digipoort->isDormant() === false;
	}//end canFile()

	/**
	 * The supplementary return of a prepared correction, as filed or downloaded.
	 *
	 * @param array<string,mixed> $correction The VatCorrection.
	 *
	 * @return array<string,mixed> The return it corrects, its period, the per-box differences,
	 *                             the net correction and the threshold decision.
	 *
	 * @throws RuntimeException When the correction was not prepared.
	 *
	 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-2.2
	 */
	public function document(array $correction): array {
		if (($correction['preparedAt'] ?? null) === null || $correction['preparedAt'] === '') {
			throw new RuntimeException('Prepare the supplementary return before downloading or filing it.');
		}

		$boxes = [];
		foreach ((array)($correction['categoryDeltas'] ?? []) as $delta) {
			$boxes[] = [
				'returnBox' => (string)($delta['returnBox'] ?? ''),
				'type' => (string)($delta['type'] ?? ''),
				'taxRate' => (float)($delta['taxRate'] ?? 0.0),
				'deltaTaxableAmount' => (float)($delta['deltaTaxableAmount'] ?? 0.0),
				'deltaVATAmount' => (float)($delta['deltaVATAmount'] ?? 0.0),
			];
		}

		return [
			'filingType' => self::FILING_TYPE,
			'correctionId' => (string)($correction['id'] ?? ''),
			'administrationId' => (string)($correction['administrationId'] ?? ''),
			'originalReturnId' => (string)($correction['originalVatReturnId'] ?? ($correction['originalReturnId'] ?? '')),
			'periodType' => (string)($correction['periodType'] ?? ''),
			'periodYear' => (int)($correction['periodYear'] ?? 0),
			'periodQuarter' => ($correction['periodQuarter'] ?? null),
			'periodMonth' => ($correction['periodMonth'] ?? null),
			'currency' => (string)($correction['currency'] ?? 'EUR'),
			'boxes' => $boxes,
			'netCorrection' => (float)($correction['correctionAmount'] ?? ($correction['adjustmentAmount'] ?? 0.0)),
			'thresholdExceeded' => (bool)($correction['thresholdExceeded'] ?? false),
			'filingDeadline' => ($correction['filingDeadline'] ?? null),
			'preparedAt' => (string)$correction['preparedAt'],
		];
	}//end document()

	/**
	 * File a prepared correction's supplementary return through the Digipoort port.
	 *
	 * On success the correction is submitted, with when and the delivery reference.
	 *
	 * @param array<string,mixed> $correction The VatCorrection.
	 *
	 * @return array<string,mixed> The correction as saved.
	 *
	 * @throws SuppletieFilingUnavailableException When no Digipoort connection is bound.
	 * @throws RuntimeException When the correction is not a prepared draft, or the delivery failed.
	 *
	 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-2.2
	 */
	public function file(array $correction): array {
		if ((string)($correction['state'] ?? '') !== 'draft') {
			throw new RuntimeException('Only a supplementary return still in draft can be filed.');
		}

		$document = $this->document(correction: $correction);
		if ($this->canFile() === false) {
			throw new SuppletieFilingUnavailableException(
				'Digipoort is not connected. Download the supplementary return and file it with the Belastingdienst.'
			);
		}

		$bytes = (string)json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		$result = $this->digipoort->submit(
			payload: [
				'filingType' => self::FILING_TYPE,
				'correlationId' => $document['correctionId'],
				'suppletie' => $document,
				'xbrlInstanceBytes' => $bytes,
				'xbrlChecksum' => hash('sha256', $bytes),
			]
		);
		if (in_array(strtoupper($result->deliveryStatus), ['FAILED', 'REJECTED', 'ERROR'], true) === true) {
			throw new RuntimeException('Digipoort did not accept the supplementary return (' . $result->deliveryStatus . ').');
		}

		$correction['state'] = 'submitted';
		$correction['submittedAt'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
		$correction['digipoortMessageId'] = $result->reference;

		$this->logger->info(
			'VatSuppletieFilingService: supplementary return handed to Digipoort',
			['correctionId' => $document['correctionId'], 'reference' => $result->reference]
		);

		$saved = $this->objectService
			->setRegister($this->register())
			->setSchema('VatCorrection')
			->saveObject($correction);

		return $saved->jsonSerialize();
	}//end file()

	/**
	 * The configured register slug, 'shillinq' when unset.
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
