<?php

/**
 * Posts a draft StockMove through OpenRegister's transition engine.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://shillinq.app
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Create a StockMove as a draft, then drive it to `posted` through the
 * transition engine.
 *
 * OpenRegister dispatches no transition event for a state set at create, so a
 * move created straight at `posted` never reaches the listeners that act on
 * the `post` transition, among them the valuation engine that adds a cost
 * layer. Creating the draft and transitioning it makes that event fire. When
 * the engine is unavailable the move is written as posted directly, the
 * behaviour before this class, and a warning says the transition listeners
 * did not run for it.
 *
 * @spec openspec/changes/inventory-receipt-cost-layers/specs/inventory-valuation-fifo-avg/spec.md#requirement-an-accepted-receipt-adds-its-cost-layer-req-ircl-001
 */
final class StockMovePosting {

	/**
	 * The transition engine's service id.
	 *
	 * @var string
	 */
	public const ENGINE_CLASS = 'OCA\\OpenRegister\\Service\\Lifecycle\\TransitionEngine';

	/**
	 * The StockMove schema.
	 *
	 * @var string
	 */
	private const SCHEMA = 'StockMove';

	/**
	 * Constructor.
	 *
	 * @param SchemaRecordStore       $records   The record store on the app's register.
	 * @param ContainerInterface|null $container The container the engine is resolved from, or null when there is none.
	 * @param LoggerInterface         $logger    Logger.
	 * @param string                  $source    The calling service, named in log lines.
	 *
	 * @spec openspec/changes/inventory-receipt-cost-layers/tasks.md#1-receipts
	 */
	public function __construct(
		private readonly SchemaRecordStore $records,
		private readonly ?ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly string $source,
	) {
	}//end __construct()

	/**
	 * Save the move as a draft and post it.
	 *
	 * @param array<string,mixed> $move The StockMove, in any state; it is saved as an unlocked draft.
	 *
	 * @return array<string,mixed> The posted StockMove as stored.
	 *
	 * @spec openspec/changes/inventory-receipt-cost-layers/specs/inventory-valuation-fifo-avg/spec.md#requirement-an-accepted-receipt-adds-its-cost-layer-req-ircl-001
	 */
	public function post(array $move): array {
		$move['postedAt']       = null;
		$move['locked']         = false;
		$move['lifecycleState'] = 'draft';

		$draft  = $this->records->saveObject(schema: self::SCHEMA, object: $move);
		$moveId = (string)($draft['id'] ?? ($draft['@self']['id'] ?? ''));
		if ($moveId !== '' && $this->transition(moveId: $moveId) === true) {
			return ($this->records->findOne(schema: self::SCHEMA, filters: ['id' => $moveId]) ?? $draft);
		}

		$this->logger->warning(
			$this->source . ': StockMove posted without the transition engine; its post listeners did not run',
			['stockMoveId' => $moveId, 'movementNumber' => ($move['movementNumber'] ?? null)]
		);

		$draft['postedAt']       = ($draft['draftedAt'] ?? null);
		$draft['locked']         = true;
		$draft['lifecycleState'] = 'posted';

		return $this->records->saveObject(schema: self::SCHEMA, object: $draft);
	}//end post()

	/**
	 * Fire the `post` transition on a saved draft.
	 *
	 * @param string $moveId The draft's id.
	 *
	 * @return bool True when the engine ran the transition.
	 */
	private function transition(string $moveId): bool {
		if ($this->container === null || $this->container->has(self::ENGINE_CLASS) === false) {
			return false;
		}

		try {
			$engine = $this->container->get(self::ENGINE_CLASS);
			if (is_object($engine) === false || method_exists($engine, 'transition') === false) {
				return false;
			}

			$engine->transition($moveId, 'post');
			return true;
		} catch (Throwable $e) {
			$this->logger->error(
				$this->source . ': StockMove post transition failed',
				['stockMoveId' => $moveId, 'exception' => $e->getMessage()]
			);
			return false;
		}
	}//end transition()
}//end class
