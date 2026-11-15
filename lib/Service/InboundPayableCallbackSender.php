<?php

/**
 * Inbound Payable Callback Sender
 *
 * Tells the app that raised a payable what finance did with it: a JSON post
 * to the payable's callback URL, signed with HMAC-SHA256 under the app's own
 * secret in the X-Shillinq-Signature header. Without a secret nothing is
 * sent: an unsigned money callback is never an implicit pass (REQ-IPY-004).
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
 * @spec openspec/changes/inbound-payable-from-another-app/specs/inbound-payables/spec.md (REQ-IPY-004)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Posts one signed step to the raising app.
 *
 * @spec openspec/changes/inbound-payable-from-another-app/specs/inbound-payables/spec.md (REQ-IPY-004)
 */
class InboundPayableCallbackSender {

	/**
	 * App config key: a JSON map of app id to callback secret.
	 * @var string
	 */
	public const CONFIG_SECRETS = 'inboundPayableCallbackSecrets';

	/**
	 * The signature header.
	 * @var string
	 */
	public const SIGNATURE_HEADER = 'X-Shillinq-Signature';

	/**
	 * Constructor.
	 *
	 * @param IClientService  $clientService Nextcloud's HTTP client factory.
	 * @param IAppConfig      $appConfig     The secrets.
	 * @param LoggerInterface $logger        Logs a callback that did not arrive.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IClientService $clientService,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Post the payable's current step to its callback and record the outcome on it.
	 *
	 * @param array<string, mixed> $payable The payable after the step.
	 *
	 * @return array<string, mixed> The payable with callbackDeliveredAt or callbackError set.
	 *
	 * @spec openspec/changes/inbound-payable-from-another-app/specs/inbound-payables/spec.md (REQ-IPY-004)
	 */
	public function send(array $payable): array {
		$url = (string)($payable['callbackUrl'] ?? '');
		if ($url === '') {
			return $payable;
		}

		$sourceApp = (string)($payable['sourceApp'] ?? '');
		$secret = $this->secretFor(app: $sourceApp);
		if ($secret === '') {
			$payable['callbackError'] = sprintf('No callback secret is set for %s in %s, so the step was not sent.', $sourceApp, self::CONFIG_SECRETS);
			return $payable;
		}

		$body = (string)json_encode(self::body(payable: $payable), JSON_UNESCAPED_SLASHES);
		try {
			$this->clientService->newClient()->post(
				$url,
				[
					'body' => $body,
					'headers' => [
						'Content-Type' => 'application/json',
						self::SIGNATURE_HEADER => hash_hmac('sha256', $body, $secret),
					],
					'timeout' => 10,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Shillinq: a payable callback did not arrive',
				['reference' => $payable['reference'] ?? '', 'exception' => $e->getMessage()]
			);
			$payable['callbackError'] = 'The callback did not arrive: ' . $e->getMessage();
			return $payable;
		}

		$payable['callbackDeliveredAt'] = gmdate('Y-m-d\TH:i:s\Z');
		unset($payable['callbackError']);
		return $payable;
	}//end send()

	/**
	 * The callback body: what an app needs to update its own record.
	 *
	 * @param array<string, mixed> $payable The payable.
	 *
	 * @return array<string, mixed> The body.
	 */
	private static function body(array $payable): array {
		return [
			'contractVersion' => 1,
			'payableId' => (string)($payable['id'] ?? ''),
			'reference' => (string)($payable['reference'] ?? ''),
			'correlationId' => (string)($payable['correlationId'] ?? ''),
			'status' => (string)($payable['state'] ?? ''),
			'amount' => (float)($payable['amount'] ?? 0),
			'currency' => (string)($payable['currency'] ?? 'EUR'),
			'actualPaymentDate' => (string)($payable['paidOn'] ?? ''),
			'bankReference' => (string)($payable['bankReference'] ?? ''),
			'reason' => (string)($payable['rejectedReason'] ?? ''),
		];
	}//end body()

	/**
	 * The secret an app's callbacks are signed with.
	 *
	 * @param string $app The app id.
	 *
	 * @return string The secret, or empty.
	 */
	private function secretFor(string $app): string {
		$map = json_decode($this->appConfig->getValueString('shillinq', self::CONFIG_SECRETS, ''), true);
		if (is_array($map) === false || $app === '') {
			return '';
		}

		return trim((string)($map[$app] ?? ''));
	}//end secretFor()
}//end class
