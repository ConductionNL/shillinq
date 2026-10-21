<?php

/**
 * Payment Block Controller
 *
 * The one endpoint behind "Block payment" and "Release payment" on a supplier
 * invoice or a supplier. #[NoAdminRequired]; the payment action, the
 * administration, the reason and four-eyes are checked in PaymentBlockService.
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
 * @spec openspec/specs/payment-control-guards/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\PaymentRun\PaymentBlockService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Blocks and releases payment of one invoice or supplier.
 *
 * @spec openspec/specs/payment-control-guards/spec.md
 */
class PaymentBlockController extends Controller {

	/**
	 * Construct the controller.
	 *
	 * @param IRequest            $request The request.
	 * @param PaymentBlockService $blocks  Sets and releases blocks.
	 * @param IL10N               $l10n    Translations.
	 * @param LoggerInterface     $logger  Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly PaymentBlockService $blocks,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Block or release payment.
	 *
	 * The body carries `blocked` (true to block, false to release) and `reason`.
	 *
	 * @param string $schema APTransaction or Payee.
	 * @param string $id     The record's id.
	 * @param string $reason Why; required both ways.
	 *
	 * @return JSONResponse The saved record, or the refusal with a message.
	 *
	 * @spec openspec/specs/payment-control-guards/spec.md
	 */
	#[NoAdminRequired]
	public function update(string $schema, string $id, string $reason=''): JSONResponse {
		$blocked = filter_var($this->request->getParam('blocked', true), FILTER_VALIDATE_BOOLEAN);
		try {
			$result = $this->blocks->setBlock(schema: $schema, id: $id, blocked: $blocked, reason: $reason);
		} catch (Throwable $e) {
			$this->logger->error('Shillinq: the payment block could not be saved', ['exception' => $e->getMessage()]);
			return new JSONResponse(
				['error' => 'save-failed', 'message' => $this->l10n->t('The payment block could not be saved.')],
				500
			);
		}

		if (isset($result['error']) === true) {
			return new JSONResponse(
				['error' => $result['error'], 'message' => $this->message(code: $result['error'])],
				$result['status']
			);
		}

		return new JSONResponse($result['object'] ?? []);

	}//end update()

	/**
	 * The sentence for a refusal.
	 *
	 * @param string $code The refusal code.
	 *
	 * @return string
	 */
	private function message(string $code): string {
		return match ($code) {
			'not-logged-in' => $this->l10n->t('Sign in to change a payment block.'),
			'not-allowed' => $this->l10n->t('You are not allowed to block or release payments.'),
			'reason-required' => $this->l10n->t('Give a reason.'),
			'already-blocked' => $this->l10n->t('Payment is already blocked.'),
			'not-blocked' => $this->l10n->t('Payment is not blocked.'),
			'four-eyes' => $this->l10n->t('You set this block, so someone else has to release it.'),
			default => $this->l10n->t('This record cannot be found.'),
		};

	}//end message()
}//end class
