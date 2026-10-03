<?php

/**
 * Object Request Command Service
 *
 * Answers the refund and credit commands another app sends about a settled
 * payment request on one of its objects. The player who cancels is not a
 * finance user, so the check is on the request itself: it stands on an object
 * the asking app owns, it is settled, and it was not refunded or credited
 * before. Anything else is refused and nothing changes (REQ-ORC-001).
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
 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-requests/spec.md (REQ-ORC-001)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Event\PaymentCreditRequestedEvent;
use OCA\Shillinq\Event\PaymentRefundRequestedEvent;
use OCA\Shillinq\Event\PaymentSettlementCommandEvent;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Checks and carries out a refund or credit command.
 *
 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-requests/spec.md (REQ-ORC-001)
 */
class ObjectRequestCommandService {

	/**
	 * States a request cannot be refunded or credited from again.
	 *
	 * @var list<string>
	 */
	private const DONE_STATES = ['refund_requested', 'refunded', 'credited'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service (ADR-083).
	 * @param IAppConfig $appConfig The register slug.
	 * @param DebtorCreditService $credits Books and records a credit.
	 * @param LoggerInterface $logger Logs a command that failed after it was accepted.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly DebtorCreditService $credits,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Answer a refund command: the request waits for finance (REQ-ORC-002).
	 *
	 * @param PaymentRefundRequestedEvent $event The command.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-requests/spec.md (REQ-ORC-001)
	 */
	public function refund(PaymentRefundRequestedEvent $event): void {
		$this->run(
			event: $event,
			change: static function (array $request) use ($event): array {
				$refunds = $request['refunds'] ?? [];
				if (is_array($refunds) === false) {
					$refunds = [];
				}

				$refunds[] = [
					'amount' => round((float)($request['amount'] ?? 0), 2),
					'reason' => $event->getReason(),
					'requestedBy' => $event->getSourceApp(),
					'requestedAt' => gmdate('Y-m-d\TH:i:s\Z'),
					'state' => 'requested',
				];
				$request['refunds'] = array_values($refunds);
				$request['state'] = 'refund_requested';
				return $request;
			}
		);
	}//end refund()

	/**
	 * Answer a credit command: booked at once, kept per debtor (REQ-ORC-003).
	 *
	 * @param PaymentCreditRequestedEvent $event The command.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-requests/spec.md (REQ-ORC-003)
	 */
	public function credit(PaymentCreditRequestedEvent $event): void {
		$this->run(
			event: $event,
			change: fn (array $request): array => $this->credits->credit(request: $request)
		);
	}//end credit()

	/**
	 * Why a request may not be refunded or credited by this app, or null.
	 *
	 * @param array<string, mixed> $request The request.
	 * @param string $sourceApp The asking app.
	 *
	 * @return string|null The refusal, or null when the command may go ahead.
	 *
	 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-requests/spec.md (REQ-ORC-001)
	 */
	public static function refusal(array $request, string $sourceApp): ?string {
		if ((string)($request['subjectKind'] ?? '') !== 'object') {
			return 'Only a payment request on an object can be refunded or credited this way.';
		}

		if ($sourceApp === '') {
			return 'The payment request does not stand on an object of an unnamed app.';
		}

		if (self::ownedBy(request: $request, app: $sourceApp) === false) {
			return sprintf('The payment request does not stand on an object of %s.', $sourceApp);
		}

		if (in_array((string)($request['state'] ?? ''), self::DONE_STATES, true) === true) {
			return sprintf('The payment request is already %s.', str_replace('_', ' ', (string)$request['state']));
		}

		if ((string)($request['settledAt'] ?? '') === '') {
			return 'The payment request is not settled, so there is no money to refund or keep.';
		}

		return null;
	}//end refusal()

	/**
	 * Whether an app owns the request's subject: named on it, the app that
	 * raised it through the leaf, or the owner of the subject's register.
	 *
	 * @param array<string, mixed> $request The request.
	 * @param string $app The app id.
	 *
	 * @return bool True when the app owns the subject.
	 */
	private static function ownedBy(array $request, string $app): bool {
		$subject = $request['subject'] ?? [];
		if (is_array($subject) === false) {
			$subject = [];
		}

		return (string)($subject['app'] ?? '') === $app
			|| (string)($request['requestedBy'] ?? '') === 'app:' . $app
			|| (string)($subject['register'] ?? '') === $app;
	}//end ownedBy()

	/**
	 * Load the request, check it, apply the change, save it and answer.
	 *
	 * @param PaymentSettlementCommandEvent $event The command.
	 * @param callable(array<string, mixed>): array<string, mixed> $change The change to apply.
	 *
	 * @return void
	 */
	private function run(PaymentSettlementCommandEvent $event, callable $change): void {
		$request = $this->find(id: $event->getPaymentRequestId());
		if ($request === null) {
			$event->refuse(error: 'No payment request with that id exists.');
			return;
		}

		$refusal = self::refusal(request: $request, sourceApp: $event->getSourceApp());
		if ($refusal !== null) {
			$event->refuse(error: $refusal);
			return;
		}

		try {
			$changed = $change($request);
			$this->objectService->saveObject(
				object: $changed,
				register: $this->registerSlug(),
				schema: 'PaymentRequest',
				_rbac: false,
				_multitenancy: false,
			);
		} catch (InvalidArgumentException $e) {
			$event->refuse(error: $e->getMessage());
			return;
		} catch (Throwable $e) {
			$this->logger->error('Shillinq: a refund or credit command could not be carried out', ['exception' => $e->getMessage()]);
			$event->refuse(error: 'The command could not be carried out: ' . $e->getMessage());
			return;
		}

		$event->accept(state: (string)$changed['state']);
	}//end run()

	/**
	 * Read one PaymentRequest as the system.
	 *
	 * @param string $id The uuid.
	 *
	 * @return array<string, mixed>|null The request with its id, or null.
	 */
	private function find(string $id): ?array {
		if ($id === '') {
			return null;
		}

		try {
			$found = $this->objectService
				->setRegister($this->registerSlug())
				->setSchema('PaymentRequest')
				->find($id, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			return null;
		}

		$record = ObjectIdentifier::recordWithId(candidate: $found);
		if ($record === null || (string)($record['id'] ?? '') === '') {
			return null;
		}

		return $record;
	}//end find()

	/**
	 * The register slug holding shillinq's own objects.
	 *
	 * @return string The slug.
	 */
	private function registerSlug(): string {
		$register = $this->appConfig->getValueString('shillinq', 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end registerSlug()
}//end class
