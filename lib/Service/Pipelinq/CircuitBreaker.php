<?php

/**
 * Circuit breaker for the pipelinq HTTP adapter.
 *
 * Encapsulates the three-state circuit breaker used by
 * {@see PipelinqContactAdapter}: CLOSED (normal traffic), OPEN (fail fast),
 * and HALF_OPEN (one probe call allowed after the cooldown). Calls to
 * `recordFailure()` accumulate; once the configured consecutive-failure
 * threshold is hit the breaker opens. Once `cooldownSeconds` have elapsed,
 * the next `allowRequest()` flips the breaker to HALF_OPEN so a single probe
 * can run; that probe must call `recordSuccess()` or `recordFailure()` to
 * either close the breaker again or re-open it.
 *
 * State transitions are surfaced via a callable so the adapter can log them at
 * WARNING (ADR-005 ops-visibility requirement). The class itself never logs —
 * keeping it pure and unit-testable with a fake clock.
 *
 * Spec: bookings-pipelinq-integration / "The adapter SHALL fail fast via a
 * circuit breaker" (5 consecutive failures, 5-minute cooldown to half-open).
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Pipelinq
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-02-http-adapter-core/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Pipelinq;

/**
 * Three-state circuit breaker (CLOSED / OPEN / HALF_OPEN).
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-02-http-adapter-core/tasks.md
 */
final class CircuitBreaker
{
    public const STATE_CLOSED    = 'closed';
    public const STATE_OPEN      = 'open';
    public const STATE_HALF_OPEN = 'half_open';

    /**
     * @var string Current breaker state.
     */
    private string $state = self::STATE_CLOSED;

    /**
     * @var int Count of consecutive failures since the last success.
     */
    private int $consecutiveFailures = 0;

    /**
     * @var int|null Unix timestamp of the failure that opened the breaker.
     */
    private ?int $openedAt = null;

    /**
     * Constructor.
     *
     * @param int           $failureThreshold Consecutive failures that trip the breaker (default 5).
     * @param int           $cooldownSeconds  Seconds to remain OPEN before moving to HALF_OPEN (default 300 = 5 minutes).
     * @param \Closure|null $clock            Callable returning the current unix timestamp; defaults to time(). Injecting a fake clock keeps the breaker unit-testable.
     * @param \Closure|null $onTransition     Callable invoked on every state transition with (string $from, string $to, string $reason); used for WARNING-level logging.
     */
    public function __construct(
        private readonly int $failureThreshold = 5,
        private readonly int $cooldownSeconds = 300,
        private readonly ?\Closure $clock = null,
        private readonly ?\Closure $onTransition = null
    ) {

    }//end __construct()

    /**
     * Return the current breaker state.
     *
     * Side effect: if the breaker is OPEN and the cooldown has elapsed it is
     * transitioned to HALF_OPEN before the state is returned, so a single
     * probe call may run.
     *
     * @return string One of STATE_CLOSED, STATE_OPEN, STATE_HALF_OPEN.
     */
    public function state(): string
    {
        if ($this->state === self::STATE_OPEN && $this->cooldownElapsed() === true) {
            $this->transitionTo(self::STATE_HALF_OPEN, 'cooldown elapsed');
        }

        return $this->state;

    }//end state()

    /**
     * Decide whether a request may proceed.
     *
     * @return bool TRUE when the breaker is CLOSED or HALF_OPEN; FALSE when OPEN (fail fast).
     */
    public function allowRequest(): bool
    {
        return $this->state() !== self::STATE_OPEN;

    }//end allowRequest()

    /**
     * Record a successful call.
     *
     * Closes a HALF_OPEN breaker and resets the failure counter.
     *
     * @return void
     */
    public function recordSuccess(): void
    {
        $this->consecutiveFailures = 0;
        if ($this->state !== self::STATE_CLOSED) {
            $this->transitionTo(self::STATE_CLOSED, 'success');
            $this->openedAt = null;
        }

    }//end recordSuccess()

    /**
     * Record a failed call.
     *
     * Trips the breaker once `failureThreshold` consecutive failures have
     * been observed, and re-opens a HALF_OPEN breaker on a single failed
     * probe.
     *
     * @return void
     */
    public function recordFailure(): void
    {
        $this->consecutiveFailures += 1;

        if ($this->state === self::STATE_HALF_OPEN) {
            $this->openedAt = $this->now();
            $this->transitionTo(self::STATE_OPEN, 'half-open probe failed');
            return;
        }

        if ($this->state === self::STATE_CLOSED && $this->consecutiveFailures >= $this->failureThreshold) {
            $this->openedAt = $this->now();
            $this->transitionTo(self::STATE_OPEN, sprintf('%d consecutive failures', $this->consecutiveFailures));
        }

    }//end recordFailure()

    /**
     * Current consecutive-failure counter (exposed for tests / observability).
     *
     * @return int
     */
    public function consecutiveFailures(): int
    {
        return $this->consecutiveFailures;

    }//end consecutiveFailures()

    /**
     * Return the current unix timestamp via the injected clock or PHP time().
     *
     * @return int
     */
    private function now(): int
    {
        if ($this->clock !== null) {
            return (int) ($this->clock)();
        }

        return time();

    }//end now()

    /**
     * Has the cooldown window elapsed since the breaker opened?
     *
     * @return bool
     */
    private function cooldownElapsed(): bool
    {
        if ($this->openedAt === null) {
            return false;
        }

        return ($this->now() - $this->openedAt) >= $this->cooldownSeconds;

    }//end cooldownElapsed()

    /**
     * Apply a state transition and notify the optional callback.
     *
     * @param string $to     New state.
     * @param string $reason Short human-readable cause (for the WARNING log).
     *
     * @return void
     */
    private function transitionTo(string $to, string $reason): void
    {
        if ($this->state === $to) {
            return;
        }

        $from        = $this->state;
        $this->state = $to;

        if ($this->onTransition !== null) {
            ($this->onTransition)($from, $to, $reason);
        }

    }//end transitionTo()
}//end class
