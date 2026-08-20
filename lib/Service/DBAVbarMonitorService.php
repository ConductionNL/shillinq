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
use OCA\Shillinq\AppInfo\Application;
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
		$vbarGrens = DBAConstants::vbarGrensCents($this->appConfig, Application::APP_ID, $administrationId);
		$mode = DBAConstants::complianceMode($this->appConfig, Application::APP_ID, $administrationId);

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
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning(
				'DBAVbarMonitorService: OpenRegister not available, skipping flag.',
				['exception' => $e->getMessage()]
			);
			return false;
		}

		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			$register = 'shillinq';
		}

		try {
			$existing = $objectService->setRegister($register)->setSchema('DBARisicoflag')->findAll(
				[
					'filters' => [
						'assignmentId' => $assignmentId,
						'type' => 'VBAR_GRENS_BELOW_THRESHOLD',
						'status' => 'OPEN',
					],
					'limit' => 10,
				]
			);
			foreach ($existing as $entity) {
				$arr = null;
				if (is_array($entity) === true) {
					$arr = $entity;
				} elseif (method_exists($entity, 'getObject') === true) {
					$arr = $entity->getObject();
				}

				if (is_array($arr) === true
					&& (string)(($arr['details'] ?? [])['invoiceId'] ?? '') === $invoiceId
				) {
					return false;
				}
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				'DBAVbarMonitorService: idempotency check failed.',
				['exception' => $e->getMessage()]
			);
		}//end try

		try {
			$objectService->setRegister($register)->setSchema('DBARisicoflag')->saveObject(
				[
					'administrationId' => $administrationId,
					'assignmentId' => $assignmentId,
					'type' => 'VBAR_GRENS_BELOW_THRESHOLD',
					'detectionMoment' => (new DateTimeImmutable())->format('c'),
					'severity' => 'MEDIUM',
					'details' => [
						'invoiceId' => $invoiceId,
						'uurtariefCents' => $hourlyRateCents,
						'vbarGrensCents' => $vbarGrensCents,
						'peiljaar' => DBAConstants::VBAR_GRENS_PEILJAAR,
					],
					'fiscalSource' => 'REQ-DBA-016; VBAR-wetsvoorstel uurtariefgrens (peil ' . DBAConstants::VBAR_GRENS_PEILJAAR . ')',
					'actionSuggestion' => 'Verhoog het uurtarief of leg een schriftelijke onderbouwing vast '
						. '(motivatie EUR-grens uitzondering).',
					'status' => 'OPEN',
					'displayedInUser' => true,
				]
			);
			return true;
		} catch (Throwable $e) {
			$this->logger->error(
				'DBAVbarMonitorService: failed to write VBAR flag.',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end emitFlag()
}//end class
