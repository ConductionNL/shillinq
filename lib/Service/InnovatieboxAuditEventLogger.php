<?php

/**
 * Innovatiebox Audit Event Logger
 *
 * Append-only writer of `InnovatieboxAuditEvent` records (REQ-IBA-008 +
 * REQ-IBA-009). Every nexus calculation, profit-attribution write, year-end
 * finalisation, VSO-locked amendment attempt, doorsnijdingsverbod check, and
 * forfaitair-cap application is captured here so the Belastingdienst VSO
 * defence can reproduce the calculation chain end-to-end.
 *
 * The underlying schema is `x-openregister.immutable`, so every write is a
 * createObject (never an updateObject — OR rejects updates on immutable
 * schemas). The logger is intentionally fail-soft: a logging failure must
 * never bubble into the write path of the subject schema, because the schema
 * mutation itself is the source of legal truth (the audit log merely defends
 * it). On failure we log to Psr to keep the trace alive in NC logs.
 *
 * Event types are constants on this class to keep listener call-sites
 * type-stable and grep-discoverable. They mirror the enum in the schema.
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
 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-008
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Shillinq\AppInfo\AppIdentity;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Append-only writer of InnovatieboxAuditEvent records (REQ-IBA-008/009).
 *
 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-008
 */
class InnovatieboxAuditEventLogger {

	/**
	 * The optional event fields, copied in this order when set.
	 *
	 * @var array<int,string>
	 */
	private const OPTIONAL_FIELDS = ['financialYear', 'qualifying_asset_id', 'subject_schema', 'subject_id', 'reason', 'details'];
	/**
	 * Event type constants — mirror the enum on the InnovatieboxAuditEvent
	 * schema (lib/Settings/register.d/bookkeeping-innovatiebox-administratie.json).
	 *
	 * @var string
	 */
	public const EVENT_NEXUS_CALCULATED = 'NexusCalculation.calculated';
	public const EVENT_PROFIT_CREATED = 'IBProfitAttribution.created';
	public const EVENT_PROFIT_FINALIZED = 'IBProfitAttribution.finalized';
	public const EVENT_PROFIT_AMENDMENT_BLOCKED = 'IBProfitAttribution.amendment_attempt_blocked';
	public const EVENT_LOSS_CREATED = 'CarryForwardLoss.created';
	public const EVENT_LOSS_OFFSET_APPLIED = 'CarryForwardLoss.offset_applied';
	public const EVENT_DOORSNIJDINGSVERBOD_CHECK_RUN = 'cross_cutting_prohibition_check_run';
	public const EVENT_FORFAITAIR_CAP_APPLIED = 'flatRateCapApplied';

	/**
	 * Construct the logger.
	 *
	 * @param ContainerInterface $container DI container — OR ObjectService is fetched lazily so
	 *                                      we don't bind a hard dependency on a not-yet-loaded
	 *                                      peer app during boot.
	 * @param IAppConfig $appConfig App config (register slug).
	 * @param IUserSession $userSession Active session — used to stamp the actor uid.
	 * @param LoggerInterface $logger Psr logger for fail-soft warnings.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Append a single audit event.
	 *
	 * Required keys in $options: event_type, administrationId. Optional keys:
	 * boekjaar, qualifying_asset_id, subject_schema, subject_id, reason,
	 * details. The actor uid + UTC timestamp are stamped automatically.
	 *
	 * @param array<string,mixed> $options Event payload.
	 *
	 * @return bool TRUE on successful append, FALSE on a logged failure.
	 *
	 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-008
	 */
	public function record(array $options): bool {
		if ((string)($options['event_type'] ?? '') === '' || (string)($options['administrationId'] ?? '') === '') {
			$this->logger->warning('InnovatieboxAuditEventLogger: missing required event_type or administrationId', ['options' => $options]);
			return false;
		}

		return $this->persist(event: $this->eventOf(options: $options));
	}//end record()

	/**
	 * The event to append: type, UTC timestamp, administration, the acting
	 * user when there is one, then every optional field that is set.
	 *
	 * @param array<string,mixed> $options Event payload.
	 *
	 * @return array<string,mixed>
	 */
	private function eventOf(array $options): array {
		$event = [
			'event_type' => (string)$options['event_type'],
			'event_timestamp' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
			'administrationId' => (string)$options['administrationId'],
		];
		$user = $this->userSession->getUser();
		if ($user !== null) {
			$event['actor_uid'] = $user->getUID();
		}

		return ($event + $this->optionalFields(options: $options));
	}//end eventOf()

	/**
	 * The optional fields that are set, in OPTIONAL_FIELDS order.
	 *
	 * @param array<string,mixed> $options Event payload.
	 *
	 * @return array<string,mixed>
	 */
	private function optionalFields(array $options): array {
		$fields = [];
		foreach (self::OPTIONAL_FIELDS as $optional) {
			if (isset($options[$optional]) === true) {
				$fields[$optional] = $options[$optional];
			}
		}

		return $fields;
	}//end optionalFields()

	/**
	 * Append the event. Never let an audit-log write break the subject
	 * schema's write path: the schema mutation is the source of legal truth,
	 * the audit log is the defence — a failure is logged and answered false.
	 *
	 * @param array<string,mixed> $event The event.
	 *
	 * @return boolean
	 */
	private function persist(array $event): bool {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$objectService->setRegister($this->register())->setSchema('InnovatieboxAuditEvent')->saveObject($event);
		} catch (Throwable $e) {
			$context = ['event_type' => $event['event_type'], 'exception' => $e->getMessage()];
			$this->logger->warning('InnovatieboxAuditEventLogger: failed to persist audit event', $context);
			return false;
		}

		return true;
	}//end persist()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string The register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
