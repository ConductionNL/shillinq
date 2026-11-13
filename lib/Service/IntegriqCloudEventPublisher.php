<?php

/**
 * Integriq CloudEvent publisher.
 *
 * The integriq app takes its commands as CloudEvent objects saved in its own register
 * (`integriq`, schema `event`) and reacts to OpenRegister's ObjectCreatedEvent
 * for them. This class writes one such object, and says whether integriq is
 * there to read it (REQ-SEIX-001).
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
 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Listener\IntegriqCloudEventListener;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Saves a CloudEvent in integriq's event register.
 *
 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-1.2
 */
class IntegriqCloudEventPublisher {

	/**
	 * The app that reads the events.
	 *
	 * @var string
	 */
	public const APP_ID = 'integriq';

	/**
	 * CloudEvents specification version written.
	 *
	 * @var string
	 */
	public const SPEC_VERSION = '1.0';

	/**
	 * Constructor.
	 *
	 * @param IAppManager            $appManager    Tells whether integriq is installed.
	 * @param ObjectServiceInterface $objectService Writes the event object.
	 * @param ITimeFactory           $time          Stamps the event.
	 * @param LoggerInterface        $logger        Logs a failed write.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ObjectServiceInterface $objectService,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Whether integriq is installed on this instance.
	 *
	 * @return bool True when integriq can read a published event.
	 *
	 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-1.2
	 */
	public function isAvailable(): bool {
		try {
			return $this->appManager->isInstalled(self::APP_ID);
		} catch (Throwable $e) {
			$this->logger->warning(
				'IntegriqCloudEventPublisher: could not tell whether integriq is installed',
				['exception' => $e->getMessage()]
			);
			return false;
		}

	}//end isAvailable()

	/**
	 * Save one CloudEvent in integriq's event register.
	 *
	 * @param string              $type    The CloudEvent type.
	 * @param string              $source  The CloudEvent source.
	 * @param string              $subject The CloudEvent subject.
	 * @param array<string,mixed> $data    The CloudEvent data.
	 *
	 * @return bool True when the event object was saved.
	 *
	 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-1.2
	 */
	public function publish(string $type, string $source, string $subject, array $data): bool {
		$event = [
			'specversion' => self::SPEC_VERSION,
			'id' => $this->eventId(),
			'type' => $type,
			'source' => $source,
			'subject' => $subject,
			'time' => $this->time->getDateTime()->format(DateTimeInterface::ATOM),
			'datacontenttype' => 'application/json',
			'data' => $data,
		];

		try {
			$this->objectService
				->setRegister(IntegriqCloudEventListener::REGISTER_SLUG)
				->setSchema(IntegriqCloudEventListener::SCHEMA_SLUG)
				->saveObject($event);
		} catch (Throwable $e) {
			$this->logger->error(
				'IntegriqCloudEventPublisher: failed to save the CloudEvent in integriq\'s register',
				['type' => $type, 'subject' => $subject, 'exception' => $e->getMessage()]
			);
			return false;
		}

		return true;

	}//end publish()

	/**
	 * A random version 4 UUID for the event id.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-1.2
	 */
	private function eventId(): string {
		$bytes    = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));

	}//end eventId()
}//end class
