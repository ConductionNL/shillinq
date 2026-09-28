<?php

/**
 * E-invoice Notifier.
 *
 * Renders the `einvoice_delivery_rejected` notification that
 * {@see \OCA\Shillinq\Listener\PeppolDeliveryStatusListener} raises for every
 * `ar-controller` of the administration when the Peppol network rejects an
 * invoice. Nextcloud drops a notification that no registered INotifier
 * prepares, so without this class the rejection was raised and never shown
 * (issue #1111, sales-einvoice-exchange design D4, REQ-SEIX-004).
 *
 * Subject parameters: invoiceNumber, detail (the rejection reason integriq
 * reported) and invoiceId (the ARInvoice uuid, for the link). English source
 * strings; Dutch translations live in l10n/nl.json.
 *
 * @category Notification
 * @package  OCA\Shillinq\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-2.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Notification;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Listener\PeppolDeliveryStatusListener;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Prepares `einvoice_delivery_rejected` notifications for display.
 *
 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-2.2
 */
class EInvoiceNotifier implements INotifier {
	/**
	 * The SPA path of an AR invoice's detail page (`ARInvoiceDetail` in src/manifest.json).
	 *
	 * @var string
	 */
	private const INVOICE_DETAIL_PATH = 'bookkeeping/accounts-receivable/';

	/**
	 * Construct the notifier.
	 *
	 * @param IFactory $l10nFactory The l10n factory for per-language rendering.
	 * @param IURLGenerator $urlGenerator Builds the link to the invoice.
	 */
	public function __construct(
		private readonly IFactory $l10nFactory,
		private readonly IURLGenerator $urlGenerator,
	) {
	}//end __construct()

	/**
	 * The notifier id (app id).
	 *
	 * @return string The identifier.
	 *
	 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-2.2
	 */
	public function getID(): string {
		return Application::APP_ID;
	}//end getID()

	/**
	 * The human-readable notifier name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-2.2
	 */
	public function getName(): string {
		return $this->l10nFactory->get(Application::APP_ID)->t('Shillinq');
	}//end getName()

	/**
	 * Prepare an `einvoice_delivery_rejected` notification for display.
	 *
	 * @param INotification $notification The raw notification.
	 * @param string $languageCode The language to render in.
	 *
	 * @return INotification The prepared notification.
	 *
	 * @throws UnknownNotificationException When the notification is not ours.
	 *
	 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-2.2
	 */
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID
			|| $notification->getSubject() !== PeppolDeliveryStatusListener::NOTIFICATION_SUBJECT_REJECTED
		) {
			throw new UnknownNotificationException();
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$parameters = $notification->getSubjectParameters();
		$invoiceNumber = (string)($parameters['invoiceNumber'] ?? '');
		$detail = trim((string)($parameters['detail'] ?? ''));
		$invoiceId = (string)($parameters['invoiceId'] ?? '');

		$notification->setParsedSubject($l->t('E-invoice %1$s was rejected', [$invoiceNumber]));

		$reason = $l->t('The Peppol network gave no reason.');
		if ($detail !== '') {
			$reason = $l->t('Reason: %1$s', [$detail]);
		}

		$notification->setParsedMessage($reason . ' ' . $l->t('Open the invoice, correct it and send it again.'));

		if ($invoiceId !== '') {
			$notification->setLink(
				rtrim($this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.dashboard.page'), '/')
				. '/' . self::INVOICE_DETAIL_PATH . rawurlencode($invoiceId)
			);
		}

		return $notification;
	}//end prepare()
}//end class
