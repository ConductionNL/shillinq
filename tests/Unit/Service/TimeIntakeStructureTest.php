<?php

/**
 * Pins how TimeIntakeService reads a time-intake body: the field tuple it
 * returns for a valid body and the refusal, in order, for each malformed one.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\InvoiceGenerationService;
use OCA\Shillinq\Service\TimeIntakeService;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * The body parser before it is split into named steps.
 *
 * @covers \OCA\Shillinq\Service\TimeIntakeService
 */
final class TimeIntakeStructureTest extends TestCase {

	/**
	 * Parse a body with the service's own parser.
	 *
	 * @param array<string,mixed> $body The request body.
	 *
	 * @return array<int,mixed>
	 */
	private function parse(array $body): array {
		$service = new TimeIntakeService(
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(InvoiceGenerationService::class),
			$this->createMock(ObjectServiceInterface::class),
		);
		$method = new ReflectionMethod(TimeIntakeService::class, 'parseAndAssertStructure');

		return $method->invoke($service, $body);

	}//end parse()

	/**
	 * A valid body.
	 *
	 * @return array<string,mixed>
	 */
	private static function body(): array {
		return [
			'batchId' => ' b-1 ',
			'organisationRef' => ' org-1 ',
			'period' => ['start' => '2026-05-01', 'end' => '2026-05-31'],
			'entries' => [
				'first' => ['externalId' => ' e-1 ', 'date' => '2026-05-02', 'minutes' => 90, 'description' => 'Work', 'hourlyRate' => 95.5, 'rateRef' => 'r-1', 'projectRef' => 'p-1'],
				['externalId' => 'e-2', 'date' => '2026-05-03'],
			],
			'billingModel' => 't_and_m',
			'rateCardId' => '',
			'projectRef' => 'p-0',
			'notes' => 'n',
		];

	}//end body()

	/**
	 * A valid body yields the pinned tuple.
	 *
	 * @return void
	 */
	public function testValidBody(): void {
		self::assertSame(
			[
				'b-1',
				'org-1',
				't_and_m',
				'EUR',
				'2026-05-01',
				'2026-05-31',
				null,
				'p-0',
				'n',
				[
					['externalId' => 'e-1', 'date' => '2026-05-02', 'minutes' => 90, 'description' => 'Work', 'hourlyRate' => 95.5, 'rateRef' => 'r-1', 'projectRef' => 'p-1'],
					['externalId' => 'e-2', 'date' => '2026-05-03', 'minutes' => null, 'description' => '', 'hourlyRate' => null, 'rateRef' => null, 'projectRef' => null],
				],
			],
			$this->parse(self::body())
		);

		$withCard = $this->parse(['rateCardId' => 'rc-9', 'currency' => 'USD'] + self::body());
		self::assertSame(['USD', 'rc-9'], [$withCard[3], $withCard[6]]);

	}//end testValidBody()

	/**
	 * Malformed bodies and the refusal each gets.
	 *
	 * @return array<string, array{0: array<string,mixed>, 1: string}>
	 */
	public static function malformed(): array {
		$body = self::body();
		return [
			'no batchId'              => [['batchId' => ' '] + $body, 'batchId is required.'],
			'no organisationRef'      => [['organisationRef' => null] + $body, 'organisationRef is required.'],
			'no batchId nor org'      => [['batchId' => '', 'organisationRef' => ''] + $body, 'batchId is required.'],
			'entries missing'         => [['entries' => null] + $body, 'entries must be a non-empty array.'],
			'entries empty'           => [['entries' => []] + $body, 'entries must be a non-empty array.'],
			'entries bad, period bad' => [['entries' => [], 'period' => 'x'] + $body, 'entries must be a non-empty array.'],
			'period not an object'    => [['period' => 'May'] + $body, 'period.start and period.end must be valid ISO dates.'],
			'period end invalid'      => [['period' => ['start' => '2026-05-01', 'end' => 'end of May']] + $body, 'period.start and period.end must be valid ISO dates.'],
			'entry not an object'     => [['entries' => ['x']] + $body, 'Each entry must be an object.'],
			'entry without id'        => [['entries' => [['externalId' => ' ', 'date' => '2026-05-02']]] + $body, 'Each entry requires a non-empty externalId and a valid ISO date.'],
			'entry with a bad date'   => [['entries' => [['externalId' => 'e', 'date' => '2/5/2026']]] + $body, 'Each entry requires a non-empty externalId and a valid ISO date.'],
			'second entry bad'        => [['entries' => [['externalId' => 'e', 'date' => '2026-05-02'], 7]] + $body, 'Each entry must be an object.'],
		];

	}//end malformed()

	/**
	 * Each malformed body is refused with its message.
	 *
	 * @param array<string,mixed> $body    The body.
	 * @param string              $message The refusal.
	 *
	 * @return void
	 */
	#[DataProvider('malformed')]
	public function testMalformedBody(array $body, string $message): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);
		$this->parse($body);

	}//end testMalformedBody()
}//end class
