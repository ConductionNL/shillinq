<?php

/**
 * Payment Request Action Controller
 *
 * The two things a handler does from the payment-requests panel on a case:
 * send the payment link to the debtor, and record that the money arrived
 * another way. Both run in shillinq's own DI context, which is the point of
 * the render-surface half being shillinq's rather than the case app's.
 *
 * Both are gated on `payment.administer`, and both refuse before they touch
 * anything: a handler who may see a case is not automatically a handler who
 * may settle its money.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-004)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\PaymentActionAuthorizer;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

/**
 * Sends a payment link and records a settlement by other means.
 *
 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-004)
 */
class PaymentRequestActionController extends Controller {
	/**
	 * The schema holding payment requests.
	 *
	 * @var string
	 */
	private const SCHEMA = 'PaymentRequest';

	/**
	 * Constructor.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The request.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param PaymentActionAuthorizer $authorizer The payment action matrix.
	 * @param IMailer $mailer Nextcloud's mailer.
	 * @param IAppConfig $appConfig App config, for the register slug.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ObjectServiceInterface $objectService,
		private readonly PaymentActionAuthorizer $authorizer,
		private readonly IMailer $mailer,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}//end __construct()

	/**
	 * Mail the payment link to the debtor and record when it was sent.
	 *
	 * @param string $id The payment request id.
	 *
	 * @return JSONResponse The outcome.
	 *
	 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-004)
	 */
	#[NoAdminRequired]
	public function send(string $id): JSONResponse {
		if ($this->authorizer->may(PaymentActionAuthorizer::ACTION_ADMINISTER) === false) {
			return new JSONResponse(['error' => 'Sending a payment link needs the payment.administer action.'], Http::STATUS_FORBIDDEN);
		}

		$request = $this->loadRequest(id: $id);
		if ($request === null) {
			return new JSONResponse(['error' => 'No payment request with that id.'], Http::STATUS_NOT_FOUND);
		}

		$email = (string)(($request['debtor']['email'] ?? '') ?: '');
		if ($email === '') {
			return new JSONResponse(['error' => 'This request has no debtor email to send the link to.'], Http::STATUS_BAD_REQUEST);
		}

		$link = (string)($request['paymentLink'] ?? '');
		if ($link === '') {
			return new JSONResponse(['error' => 'This request has no payment link; only a pending request carries one.'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$message = $this->mailer->createMessage();
			$message->setTo([$email]);
			$message->setSubject('Your payment request');
			$message->setPlainBody(
				sprintf(
					"%s\n\nAmount: %s %s\nPay here: %s\n",
					(string)($request['description'] ?? 'A payment has been requested.'),
					(string)($request['currency'] ?? 'EUR'),
					number_format((float)($request['amount'] ?? 0), 2, ',', '.'),
					$link
				)
			);
			$this->mailer->send($message);
		} catch (\Throwable $e) {
			$this->logger->error('Shillinq: could not send a payment link', ['exception' => $e->getMessage()]);
			return new JSONResponse(['error' => 'The payment link could not be sent.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$request['linkSentAt'] = gmdate('Y-m-d\TH:i:s\Z');
		$this->objectService->saveObject(
			object: $request,
			register: $this->registerSlug(),
			schema: self::SCHEMA,
		);

		return new JSONResponse(['sent' => true, 'linkSentAt' => $request['linkSentAt']]);
	}//end send()

	/**
	 * Record that the money arrived another way: at the desk, by bank transfer,
	 * through an ERP. The reference is typed by the handler and is what a later
	 * bank reconciliation matches on, so it is required.
	 *
	 * @param string $id The payment request id.
	 * @param string $settlementReference The reference the handler types.
	 * @param string $method How the money arrived.
	 *
	 * @return JSONResponse The outcome.
	 *
	 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-004)
	 */
	#[NoAdminRequired]
	public function settle(string $id, string $settlementReference = '', string $method = 'other'): JSONResponse {
		if ($this->authorizer->may(PaymentActionAuthorizer::ACTION_ADMINISTER) === false) {
			return new JSONResponse(['error' => 'Settling a payment request needs the payment.administer action.'], Http::STATUS_FORBIDDEN);
		}

		if (trim($settlementReference) === '') {
			return new JSONResponse(['error' => 'A settlement by other means needs a reference.'], Http::STATUS_BAD_REQUEST);
		}

		$request = $this->loadRequest(id: $id);
		if ($request === null) {
			return new JSONResponse(['error' => 'No payment request with that id.'], Http::STATUS_NOT_FOUND);
		}

		if ((string)($request['state'] ?? '') !== 'pending') {
			return new JSONResponse(
				['error' => sprintf('This request is %s, so it cannot be settled by other means.', (string)($request['state'] ?? 'unknown'))],
				Http::STATUS_CONFLICT
			);
		}

		$request['state'] = 'captured';
		$request['capturedAt'] = gmdate('Y-m-d\TH:i:s\Z');
		$request['settlementReference'] = trim($settlementReference);
		$request['settlementMethod'] = $method;
		$request['settledBy'] = $this->authorizer->callerId();

		$this->objectService->saveObject(
			object: $request,
			register: $this->registerSlug(),
			schema: self::SCHEMA,
		);

		return new JSONResponse(['settled' => true, 'state' => 'captured']);
	}//end settle()

	/**
	 * Load one payment request by id.
	 *
	 * @param string $id The request id.
	 *
	 * @return array<string, mixed>|null The request, or null when there is none.
	 */
	private function loadRequest(string $id): ?array {
		$rows = $this->objectService
			->setRegister($this->registerSlug())
			->setSchema(self::SCHEMA)
			->findAll(['filters' => ['id' => $id], 'limit' => 1]);

		if (is_array($rows) === false || $rows === []) {
			return null;
		}

		$row = $rows[0];

		return (is_array($row) === true ? $row : null);
	}//end loadRequest()

	/**
	 * The register slug holding shillinq's own objects.
	 *
	 * @return string The slug.
	 */
	private function registerSlug(): string {
		return $this->appConfig->getValueString('shillinq', 'register', 'shillinq');
	}//end registerSlug()
}//end class
