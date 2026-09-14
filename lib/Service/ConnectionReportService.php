<?php

/**
 * Shillinq connection report service.
 *
 * Tells integriq's connection registry which adapter answers for each adapter
 * family that shillinq really calls. Integriq owns the rows the External
 * Connections page lists and works out each status itself (hydra change
 * connection-registry, design D4). Shillinq only reports what it alone can
 * see: whether the class bound to a port is the log-only adapter.
 *
 * Only three families are reported. The other twelve are declared not
 * available in `lib/Settings/connections.json`, because nothing in shillinq
 * calls them, and the contract's rule 2 outranks any report.
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
 * @spec openspec/changes/adopt-connection-registry/specs/external-connections/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\External\DepositPayment\DepositPaymentAdapterInterface;
use OCA\Shillinq\Service\External\Mollie\MolliePaymentAdapterInterface;
use OCA\Shillinq\Service\External\TreasuryRate\TreasuryRateAdapterInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends one connection report per called adapter family to integriq.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/external-connections/spec.md
 */
class ConnectionReportService {

	/**
	 * Integriq's report event (ADR-041). Named by string so shillinq stays
	 * installable without integriq: the class is only there when integriq is.
	 *
	 * @var string
	 */
	public const STATUS_EVENT = 'OCA\Integriq\Event\ConnectionStatusReportedEvent';

	/**
	 * The adapter families shillinq calls, keyed by their connection key.
	 *
	 * `consequence` finishes the Simulated message: what does not happen while
	 * the log-only adapter answers. A unit test keeps these keys equal to the
	 * entries of `lib/Settings/connections.json` that are not declared
	 * unavailable.
	 *
	 * @var array<string, array{interface: class-string, consequence: string}>
	 */
	public const FAMILIES = [
		'mollie' => [
			'interface' => MolliePaymentAdapterInterface::class,
			'consequence' => 'No payment reaches Mollie.',
		],
		'treasury-rates' => [
			'interface' => TreasuryRateAdapterInterface::class,
			'consequence' => 'No rates are fetched from the ECB or a market data provider.',
		],
		'deposit-payment' => [
			'interface' => DepositPaymentAdapterInterface::class,
			'consequence' => 'Deposits stay pending and no payment provider is called.',
		],
	];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container       Resolves each port's bound adapter.
	 * @param IEventDispatcher   $eventDispatcher Sends the integriq event (ADR-041).
	 * @param LoggerInterface    $logger          Records what could not be sent.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IEventDispatcher $eventDispatcher,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Report the bound adapter of every called family to integriq.
	 *
	 * Never throws. Without integriq nothing is sent and nothing is logged,
	 * because a missing optional app is not a fault.
	 *
	 * @return array<string, string> The status sent, keyed by connection key.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/external-connections/spec.md
	 */
	public function reportAdapterBindings(): array {
		$eventClass = $this->resolveEventClass(eventClass: self::STATUS_EVENT);
		if ($eventClass === null) {
			return [];
		}

		$sent = [];
		foreach (self::FAMILIES as $key => $family) {
			[$status, $message] = $this->observeBinding(
				interface: $family['interface'],
				consequence: $family['consequence']
			);

			$delivered = $this->send(
				key: $key,
				build: static fn (): object => new $eventClass(
					app: Application::APP_ID,
					key: $key,
					status: $status,
					message: $message,
				)
			);
			if ($delivered === true) {
				$sent[$key] = $status;
			}
		}

		return $sent;
	}//end reportAdapterBindings()

	/**
	 * What shillinq sees bound to one port, as a status and a message.
	 *
	 * @param string $interface   The port interface to resolve.
	 * @param string $consequence What does not happen while a log-only adapter answers.
	 *
	 * @return array{0: string, 1: string} The status and the message.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/external-connections/spec.md
	 */
	private function observeBinding(string $interface, string $consequence): array {
		try {
			$adapter = $this->container->get($interface);
		} catch (Throwable $e) {
			return ['error', 'Shillinq could not load the adapter: ' . $e->getMessage()];
		}

		if (is_object($adapter) === true
			&& method_exists($adapter, 'isDormant') === true
			&& $adapter->isDormant() === true
		) {
			return ['simulated', 'A log-only adapter answers here. ' . $consequence];
		}

		$bound = 'an unknown value';
		if (is_object($adapter) === true) {
			$bound = get_class($adapter);
		}

		return ['configured', 'A real adapter is bound: ' . $bound . '. Shillinq does not test the connection itself.'];
	}//end observeBinding()

	/**
	 * The event class to instantiate, or null when integriq does not ship it.
	 *
	 * @param string $eventClass The fully qualified class name, without a leading backslash.
	 *
	 * @return string|null The class name to instantiate, or null when absent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/external-connections/spec.md
	 */
	protected function resolveEventClass(string $eventClass): ?string {
		$qualified = '\\' . $eventClass;
		if (class_exists($qualified) === false) {
			return null;
		}

		return $qualified;
	}//end resolveEventClass()

	/**
	 * Build and dispatch one event, swallowing anything a listener throws.
	 *
	 * @param string            $key   The connection the event is about, for the log.
	 * @param callable(): object $build Builds the event.
	 *
	 * @return bool True when the event was dispatched without an exception.
	 */
	private function send(string $key, callable $build): bool {
		try {
			$event = $build();
			if ($event instanceof Event === false) {
				return false;
			}

			$this->eventDispatcher->dispatchTyped($event);
			return true;
		} catch (Throwable $e) {
			$this->logger->warning(
				'Shillinq: could not send a connection report to integriq',
				['key' => $key, 'exception' => $e->getMessage()]
			);
			return false;
		}
	}//end send()
}//end class
