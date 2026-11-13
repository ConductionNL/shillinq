<?php

/**
 * Offers the shillinq helper agent template to hermiq.
 *
 * @category Listener
 * @package  OCA\Shillinq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/platform-help-agent/tasks.md#task-3.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\Shillinq\AppInfo\AppIdentity;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Answers hermiq's CollectAgentTemplatesEvent with the "Shillinq helper"
 * package (REQ-PHA-002). Hermiq imports an offer quarantined and scanned,
 * and an administrator instantiates it; nothing is forced on a tenant.
 *
 * The event is matched by its `offer()` method rather than its class, so
 * shillinq has no compile-time dependency on hermiq.
 *
 * @spec openspec/changes/platform-help-agent/specs/shillinq-mcp-tool-surface/spec.md
 *
 * @template-implements IEventListener<Event>
 */
class OfferHelperAgentTemplate implements IEventListener {

	/**
	 * The hermiq event this listens to, by name.
	 *
	 * @var string
	 */
	public const EVENT = 'OCA\Hermiq\Event\CollectAgentTemplatesEvent';

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger       Reports a package that cannot be read.
	 * @param string          $templateFile The packaged template.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly string $templateFile = __DIR__ . '/../Settings/hermiq-agent-templates/shillinq-helper.json',
	) {
	}//end __construct()

	/**
	 * Offer the template when hermiq collects them.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-help-agent/tasks.md#task-3.1
	 */
	public function handle(Event $event): void {
		if (method_exists($event, 'offer') === false) {
			return;
		}

		$package = $this->package();
		if ($package !== null) {
			$event->offer(AppIdentity::APP_ID, $package);
		}
	}//end handle()

	/**
	 * The packaged template, or null (logged) when it is missing or not a JSON object.
	 *
	 * @return string|null The package.
	 *
	 * @spec openspec/changes/platform-help-agent/tasks.md#task-3.1
	 */
	public function package(): ?string {
		$package = false;
		if (is_file($this->templateFile) === true) {
			$package = file_get_contents($this->templateFile);
		}

		if (is_string($package) === false || is_array(json_decode($package, true)) === false) {
			$this->logger->warning('Shillinq: the helper agent template could not be read; nothing offered to hermiq.', ['file' => $this->templateFile]);
			return null;
		}

		return $package;
	}//end package()
}//end class
