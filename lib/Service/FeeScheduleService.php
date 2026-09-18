<?php

/**
 * Fee Schedule Service
 *
 * What a case type costs. A fee is a council decision published in a
 * verordening, so it is administered as configuration with a validity window,
 * never typed into code: reading it rather than retyping it is the whole
 * difference the competitor register is pointing at.
 *
 * Two rules carry the weight. Resolution prefers the schedule for the intake
 * channel the application came in through, and falls back to the channel-less
 * default; a counter and a website may charge differently and a schedule that
 * ignored the channel would quietly charge one of them wrong. And an overlap is
 * refused by name: two schedules valid for one type on one day is a question
 * nothing can answer, so the second one is rejected and told which one it
 * collides with.
 *
 * Where pipelinq is installed the fee originates as a product (ADR-107
 * decision 3). A schedule with a `productRef` reads its amount from the
 * product, so the price lives in one place; a schedule without one holds the
 * amount itself, which is the case on an instance with no pipelinq.
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
 * @spec openspec/changes/leges-at-intake/specs/object-payment-requests/spec.md (REQ-SOPR-006)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Resolves and validates fee schedules.
 *
 * @spec openspec/changes/leges-at-intake/specs/object-payment-requests/spec.md (REQ-SOPR-006)
 */
final class FeeScheduleService {
	/**
	 * The schema holding fee schedules.
	 *
	 * @var string
	 */
	public const SCHEMA = 'FeeSchedule';

	/**
	 * The schema holding pipelinq products, read when a schedule references one.
	 *
	 * @var string
	 */
	public const PRODUCT_SCHEMA = 'Product';

	/**
	 * The parts that identify which type a schedule applies to. The intake
	 * channel is deliberately absent: a channel-specific row and the default row
	 * for the same type are two rows of the SAME tuple, and an overlap check that
	 * counted the channel would let a second default slip past.
	 *
	 * @var array<int, string>
	 */
	public const TUPLE_PARTS = ['targetApp', 'register', 'schema', 'typeProperty', 'typeValue'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service (ADR-083).
	 * @param IAppConfig $appConfig App config, for the register slug.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The schedule that applies to a type value on a day, or null when the type
	 * carries no fee.
	 *
	 * Prefers the schedule for the given intake channel; falls back to the
	 * channel-less default. Returns null rather than zero, because "this type is
	 * free" and "this type costs nothing yet" are different answers and only the
	 * caller knows which one to act on.
	 *
	 * @param array<string, mixed> $tuple The tuple parts: targetApp, register, schema, typeProperty, typeValue.
	 * @param string $intakeChannel Where the application came in, or an empty string.
	 * @param string $onDate The day to resolve for, as Y-m-d; today when empty.
	 *
	 * @return array<string, mixed>|null The schedule with its amount resolved, or null.
	 *
	 * @spec openspec/changes/leges-at-intake/specs/object-payment-requests/spec.md (REQ-SOPR-006)
	 */
	public function resolve(array $tuple, string $intakeChannel = '', string $onDate = ''): ?array {
		$day = ($onDate === '' ? gmdate('Y-m-d') : $onDate);

		$valid = [];
		foreach ($this->schedulesFor($tuple) as $schedule) {
			if ($this->isValidOn($schedule, $day) === true) {
				$valid[] = $schedule;
			}
		}

		if ($valid === []) {
			return null;
		}

		$match = null;
		foreach ($valid as $schedule) {
			$channel = (string)($schedule['intakeChannel'] ?? '');
			if ($intakeChannel !== '' && $channel === $intakeChannel) {
				$match = $schedule;
				break;
			}

			if ($channel === '' && $match === null) {
				$match = $schedule;
			}
		}

		if ($match === null) {
			return null;
		}

		return $this->withResolvedAmount($match);
	}//end resolve()

	/**
	 * The schedule that applies to an object the caller already has in hand.
	 *
	 * The leaf knows a register, a schema and an object; it does not know which
	 * property on that schema carries the type, because that is the schedule's
	 * own declaration. So the schedules for the register are read first and each
	 * one is asked which property it looks at, rather than the caller guessing a
	 * property name that would silently resolve nothing.
	 *
	 * @param string $register The object's register slug.
	 * @param string $schema The object's schema slug.
	 * @param array<string, mixed> $object The object itself.
	 * @param string $intakeChannel Where the application came in, or an empty string.
	 * @param string $onDate The day to resolve for, as Y-m-d; today when empty.
	 *
	 * @return array<string, mixed>|null The schedule with its amount resolved, or null.
	 *
	 * @spec openspec/changes/leges-at-intake/specs/object-payment-requests/spec.md (REQ-SOPR-008)
	 */
	public function resolveForObject(
		string $register,
		string $schema,
		array $object,
		string $intakeChannel = '',
		string $onDate = '',
	): ?array {
		$rows = $this->objectService
			->setRegister($this->registerSlug())
			->setSchema(self::SCHEMA)
			->findAll(['filters' => ['register' => $register], 'limit' => 500]);

		if (is_array($rows) === false) {
			return null;
		}

		$seen = [];
		foreach ($rows as $row) {
			if (is_array($row) === false
				|| (string)($row['register'] ?? '') !== $register
				|| (string)($row['schema'] ?? '') !== $schema
			) {
				continue;
			}

			$typeProperty = (string)($row['typeProperty'] ?? '');
			if ($typeProperty === '' || isset($seen[$typeProperty]) === true) {
				continue;
			}

			$seen[$typeProperty] = true;

			$typeValue = (string)($object[$typeProperty] ?? '');
			if ($typeValue === '') {
				continue;
			}

			$resolved = $this->resolve(
				tuple: [
					'targetApp' => (string)($row['targetApp'] ?? $register),
					'register' => $register,
					'schema' => $schema,
					'typeProperty' => $typeProperty,
					'typeValue' => $typeValue,
				],
				intakeChannel: $intakeChannel,
				onDate: $onDate,
			);

			if ($resolved !== null) {
				return $resolved;
			}
		}

		return null;
	}//end resolveForObject()

	/**
	 * Refuse a schedule whose validity window overlaps an existing one for the
	 * same tuple and channel, naming the schedule it collides with.
	 *
	 * @param array<string, mixed> $schedule The schedule about to be written.
	 * @param array<int, array<string, mixed>>|null $existing Schedules already stored, or null to read them.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the window overlaps, or the schedule names no window.
	 *
	 * @spec openspec/changes/leges-at-intake/specs/object-payment-requests/spec.md (REQ-SOPR-006)
	 */
	public function assertNoOverlap(array $schedule, ?array $existing = null): void {
		$from = (string)($schedule['validFrom'] ?? '');
		if ($from === '') {
			throw new InvalidArgumentException('A fee schedule needs a validFrom; a fee without a start date cannot be applied to a day.');
		}

		$to = (string)($schedule['validTo'] ?? '');
		$channel = (string)($schedule['intakeChannel'] ?? '');
		$selfId = (string)($schedule['id'] ?? '');

		$candidates = ($existing ?? $this->schedulesFor($schedule));

		foreach ($candidates as $candidate) {
			$candidateId = (string)($candidate['id'] ?? '');
			if ($selfId !== '' && $candidateId === $selfId) {
				continue;
			}

			if ((string)($candidate['intakeChannel'] ?? '') !== $channel) {
				continue;
			}

			if ($this->windowsOverlap($from, $to, (string)($candidate['validFrom'] ?? ''), (string)($candidate['validTo'] ?? '')) === false) {
				continue;
			}

			throw new InvalidArgumentException(
				sprintf(
					'This fee overlaps the schedule valid from %s%s%s; close that one before opening this.',
					(string)($candidate['validFrom'] ?? '?'),
					((string)($candidate['validTo'] ?? '') === '' ? '' : ' to ' . (string)$candidate['validTo']),
					($candidateId === '' ? '' : sprintf(' (%s)', $candidateId))
				)
			);
		}
	}//end assertNoOverlap()

	/**
	 * The tuple key of a schedule or a lookup, for comparing two of them.
	 *
	 * @param array<string, mixed> $tuple The tuple parts.
	 *
	 * @return string The key.
	 */
	public function tupleKey(array $tuple): string {
		$parts = [];
		foreach (self::TUPLE_PARTS as $part) {
			$parts[] = (string)($tuple[$part] ?? '');
		}

		return implode('|', $parts);
	}//end tupleKey()

	/**
	 * True when the schedule applies on the given day.
	 *
	 * @param array<string, mixed> $schedule The schedule.
	 * @param string $day The day, as Y-m-d.
	 *
	 * @return bool True when it applies.
	 */
	public function isValidOn(array $schedule, string $day): bool {
		$from = (string)($schedule['validFrom'] ?? '');
		if ($from === '' || $day < $from) {
			return false;
		}

		$to = (string)($schedule['validTo'] ?? '');

		return ($to === '' || $day <= $to);
	}//end isValidOn()

	/**
	 * Read the amount from the referenced pipelinq product where one is named,
	 * so the price lives in one place (ADR-107 decision 3).
	 *
	 * A schedule that names a product nothing answers for is returned WITHOUT an
	 * amount rather than with its own stale one: the caller then reports a fee it
	 * cannot price, which is visible, instead of charging a number the product
	 * no longer agrees with.
	 *
	 * @param array<string, mixed> $schedule The schedule.
	 *
	 * @return array<string, mixed> The schedule with `amount` and `amountSource` set.
	 *
	 * @spec openspec/changes/leges-at-intake/specs/object-payment-requests/spec.md (REQ-SOPR-006)
	 */
	private function withResolvedAmount(array $schedule): array {
		$productRef = (string)($schedule['productRef'] ?? '');
		if ($productRef === '') {
			$schedule['amountSource'] = 'schedule';

			return $schedule;
		}

		$product = $this->readProduct($productRef);
		if ($product === null) {
			unset($schedule['amount']);
			$schedule['amountSource'] = 'product-missing';
			$this->logger->warning(
				'Shillinq: a fee schedule references a product that cannot be read',
				['productRef' => $productRef]
			);

			return $schedule;
		}

		$schedule['amount'] = (float)($product['price'] ?? ($product['amount'] ?? 0));
		$schedule['currency'] = (string)($product['currency'] ?? ($schedule['currency'] ?? 'EUR'));
		$schedule['amountSource'] = 'product';

		return $schedule;
	}//end withResolvedAmount()

	/**
	 * Read a pipelinq product by reference.
	 *
	 * @param string $productRef The product reference.
	 *
	 * @return array<string, mixed>|null The product, or null when it cannot be read.
	 */
	private function readProduct(string $productRef): ?array {
		try {
			$rows = $this->objectService
				->setRegister($this->registerSlug())
				->setSchema(self::PRODUCT_SCHEMA)
				->findAll(['filters' => ['id' => $productRef], 'limit' => 1]);
		} catch (\Throwable $e) {
			return null;
		}

		if (is_array($rows) === false || $rows === []) {
			return null;
		}

		return (is_array($rows[0]) === true ? $rows[0] : null);
	}//end readProduct()

	/**
	 * Every stored schedule for the tuple of the given lookup.
	 *
	 * @param array<string, mixed> $tuple The tuple parts.
	 *
	 * @return array<int, array<string, mixed>> The schedules.
	 */
	private function schedulesFor(array $tuple): array {
		$rows = $this->objectService
			->setRegister($this->registerSlug())
			->setSchema(self::SCHEMA)
			->findAll(['filters' => ['targetApp' => (string)($tuple['targetApp'] ?? '')], 'limit' => 500]);

		if (is_array($rows) === false) {
			return [];
		}

		$key = $this->tupleKey($tuple);

		$mine = [];
		foreach ($rows as $row) {
			if (is_array($row) === true && $this->tupleKey($row) === $key) {
				$mine[] = $row;
			}
		}

		return $mine;
	}//end schedulesFor()

	/**
	 * True when two closed or open-ended day windows share a day.
	 *
	 * @param string $aFrom Start of the first window.
	 * @param string $aTo End of the first window, or empty for open ended.
	 * @param string $bFrom Start of the second window.
	 * @param string $bTo End of the second window, or empty for open ended.
	 *
	 * @return bool True when they overlap.
	 */
	private function windowsOverlap(string $aFrom, string $aTo, string $bFrom, string $bTo): bool {
		if ($bFrom === '') {
			return false;
		}

		$aEnd = ($aTo === '' ? '9999-12-31' : $aTo);
		$bEnd = ($bTo === '' ? '9999-12-31' : $bTo);

		return ($aFrom <= $bEnd && $bFrom <= $aEnd);
	}//end windowsOverlap()

	/**
	 * The register slug holding shillinq's own objects.
	 *
	 * @return string The slug.
	 */
	private function registerSlug(): string {
		return $this->appConfig->getValueString('shillinq', 'register', 'shillinq');
	}//end registerSlug()
}//end class
