<?php

/**
 * DBA VBAR uurtarief monitoring service.
 *
 * Computes effective hourly rate per factuur and emits a VBAR_GRENS_ONDERSCHREDEN
 * flag when the rate falls below the configured threshold (REQ-DBA-016). Hard-mode
 * adjudication (block-vs-warn) is delegated to the compliance-mode resolver.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/dba-compliance-marker/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use OCA\Shillinq\AppInfo\AppIdentity;
use OCA\Shillinq\Enums\DBAConstants;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Service that audits factuur lines for VBAR uurtarief-onderschrijding (REQ-DBA-016).
 *
 * @spec openspec/specs/dba-compliance-marker/spec.md
 */
class DBAVbarMonitorService {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Lazy ObjectService binding.
	 * @param IAppConfig $appConfig App config.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Result codes for `assess()`.
	 */
	public const RESULT_OK = 'OK';
	public const RESULT_WARN = 'WARN';
	public const RESULT_BLOCK = 'BLOCK';

	/**
	 * Assess a factuur line against the VBAR grens (REQ-DBA-016).
	 *
	 * Returns one of:
	 *   - OK: rate >= threshold;
	 *   - WARN: rate < threshold and compliance-mode is soft/intermediair;
	 *   - BLOCK: rate < threshold and compliance-mode is hard.
	 *
	 * @param int $amountCents Factuurbedrag (eurocenten).
	 * @param float $hours Aantal gefactureerde uren.
	 * @param string $administrationId Per-administration scoping.
	 *
	 * @return array<string,mixed> { result, uurtariefCents, vbarGrensCents, message }
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	public function assess(int $amountCents, float $hours, string $administrationId): array {
		$vbarGrens = DBAConstants::vbarGrensCents($this->appConfig, AppIdentity::APP_ID, $administrationId);
		$mode = DBAConstants::complianceMode($this->appConfig, AppIdentity::APP_ID, $administrationId);

		if ($hours <= 0.0 || $amountCents <= 0) {
			return [
				'result' => self::RESULT_OK,
				'uurtariefCents' => null,
				'vbarGrensCents' => $vbarGrens,
				'message' => 'Onvoldoende data voor VBAR-toets (uren of bedrag = 0).',
			];
		}

		$hourlyRate = (int)round($amountCents / $hours);
		if ($hourlyRate >= $vbarGrens) {
			return [
				'result' => self::RESULT_OK,
				'uurtariefCents' => $hourlyRate,
				'vbarGrensCents' => $vbarGrens,
				'message' => sprintf(
					'Effectief uurtarief EUR %.2f voldoet aan VBAR-rechtsvermoeden-grens EUR %.2f.',
					$hourlyRate / 100,
					$vbarGrens / 100
				),
			];
		}

		$result = self::RESULT_WARN;
		if ($mode === DBAConstants::COMPLIANCE_MODE_HARD) {
			$result = self::RESULT_BLOCK;
		}

		return [
			'result' => $result,
			'uurtariefCents' => $hourlyRate,
			'vbarGrensCents' => $vbarGrens,
			'message' => sprintf(
				'Effectief uurtarief EUR %.2f onder VBAR-rechtsvermoeden-grens EUR %.2f.',
				$hourlyRate / 100,
				$vbarGrens / 100
			),
		];
	}//end assess()

	/**
	 * Emit a VBAR_GRENS_ONDERSCHREDEN flag against a DBAOpdracht (REQ-DBA-016).
	 *
	 * Idempotent: a single OPEN flag per (opdrachtId, factuurId) is enforced via
	 * a details-payload check.
	 *
	 * @param string $assignmentId FK to DBAOpdracht.
	 * @param string $administrationId Administration scoping.
	 * @param string $invoiceId The factuur that triggered the check.
	 * @param int $hourlyRateCents The computed effective rate.
	 * @param int $vbarGrensCents The threshold applied.
	 *
	 * @return bool True when a flag record was written.
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	public function emitFlag(
		string $assignmentId,
		string $administrationId,
		string $invoiceId,
		int $hourlyRateCents,
		int $vbarGrensCents,
	): bool {
		$objectService = $this->resolveObjectService();
		if ($objectService === null) {
			return false;
		}

		$flag = new VbarFlag(assignmentId: $assignmentId, invoiceId: $invoiceId);
		$flag->administrationId = $administrationId;
		$flag->hourlyRateCents = $hourlyRateCents;
		$flag->vbarGrensCents = $vbarGrensCents;
		$flag->register = $this->registerSlug();
		if ($this->openFlagExists(flag: $flag, objectService: $objectService) === true) {
			return false;
		}

		return $this->saveFlag(flag: $flag, objectService: $objectService);
	}//end emitFlag()

	/**
	 * OpenRegister's object service, or null (with a warning) when it is not
	 * available.
	 *
	 * @return object|null The object service.
	 */
	private function resolveObjectService(): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning(
				'DBAVbarMonitorService: OpenRegister not available, skipping flag.',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end resolveObjectService()

	/**
	 * The configured register slug, defaulting to 'shillinq'.
	 *
	 * @return string The register slug.
	 */
	private function registerSlug(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end registerSlug()

	/**
	 * Idempotency: true when the assignment already has an OPEN flag for the
	 * same invoice. A failing check is logged and lets the flag through.
	 *
	 * @param VbarFlag $flag          The flag to raise.
	 * @param object   $objectService OpenRegister's object service.
	 *
	 * @return bool Whether an open flag for the invoice exists.
	 */
	private function openFlagExists(VbarFlag $flag, object $objectService): bool {
		try {
			$existing = $objectService->setRegister($flag->register)->setSchema('DBARisicoflag')->findAll(
				[
					'filters' => [
						'assignmentId' => $flag->assignmentId,
						'type' => 'VBAR_GRENS_BELOW_THRESHOLD',
						'status' => 'OPEN',
					],
					'limit' => 10,
				]
			);
			return $this->flagsInvoice(rows: $existing, invoiceId: $flag->invoiceId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'DBAVbarMonitorService: idempotency check failed.',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end openFlagExists()

	/**
	 * True when one of the flag rows (arrays or entities) names the invoice.
	 *
	 * @param iterable<mixed> $rows      The open flag rows.
	 * @param string          $invoiceId The invoice.
	 *
	 * @return bool Whether a row flags the invoice.
	 */
	private function flagsInvoice(iterable $rows, string $invoiceId): bool {
		foreach ($rows as $entity) {
			$arr = $this->flagRow(entity: $entity);
			if (is_array($arr) === true
				&& (string)(($arr['details'] ?? [])['invoiceId'] ?? '') === $invoiceId
			) {
				return true;
			}
		}

		return false;
	}//end flagsInvoice()

	/**
	 * A flag row as an array: as it is, or the entity's object payload.
	 *
	 * @param mixed $entity The row.
	 *
	 * @return mixed The row as an array, or what the entity yields.
	 */
	private function flagRow(mixed $entity): mixed {
		if (is_array($entity) === true) {
			return $entity;
		}

		if (method_exists($entity, 'getObject') === true) {
			return $entity->getObject();
		}

		return null;
	}//end flagRow()

	/**
	 * Write the flag; a failing write is logged and reported as not written.
	 *
	 * @param VbarFlag $flag          The flag to raise.
	 * @param object   $objectService OpenRegister's object service.
	 *
	 * @return bool True when the flag was written.
	 */
	private function saveFlag(VbarFlag $flag, object $objectService): bool {
		try {
			$objectService->setRegister($flag->register)->setSchema('DBARisicoflag')->saveObject($this->flagPayload(flag: $flag));
			return true;
		} catch (Throwable $e) {
			$this->logger->error(
				'DBAVbarMonitorService: failed to write VBAR flag.',
				['exception' => $e->getMessage()]
			);
			return false;
		}
	}//end saveFlag()

	/**
	 * The DBARisicoflag payload.
	 *
	 * @param VbarFlag $flag The flag to raise.
	 *
	 * @return array<string,mixed> The payload.
	 */
	private function flagPayload(VbarFlag $flag): array {
		return [
			'administrationId' => $flag->administrationId,
			'assignmentId' => $flag->assignmentId,
			'type' => 'VBAR_GRENS_BELOW_THRESHOLD',
			'detectionMoment' => (new DateTimeImmutable())->format('c'),
			'severity' => 'MEDIUM',
			'details' => [
				'invoiceId' => $flag->invoiceId,
				'uurtariefCents' => $flag->hourlyRateCents,
				'vbarGrensCents' => $flag->vbarGrensCents,
				'peiljaar' => DBAConstants::VBAR_GRENS_PEILJAAR,
			],
			'fiscalSource' => 'REQ-DBA-016; VBAR-wetsvoorstel uurtariefgrens (peil ' . DBAConstants::VBAR_GRENS_PEILJAAR . ')',
			'actionSuggestion' => 'Verhoog het uurtarief of leg een schriftelijke onderbouwing vast '
				. '(motivatie EUR-grens uitzondering).',
			'status' => 'OPEN',
			'displayedInUser' => true,
		];
	}//end flagPayload()
}//end class
