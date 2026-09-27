<?php

/**
 * Unit tests for PortalContributionDeclineController.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-013)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\PortalContributionDeclineController;
use OCA\Shillinq\Portal\PortalAssertionVerifier;
use OCA\Shillinq\Service\ContributionDeclineService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The receiver gates the assertion and the audience, then maps the service's
 * answer to a status.
 *
 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-013)
 */
final class PortalContributionDeclineControllerTest extends TestCase {
	private const SECRET = 'shillinq-test-secret-01234567890';
	private const INVOICE = '30000000-0000-4000-8000-000000000003';

	/**
	 * The mocked service.
	 *
	 * @var ContributionDeclineService&MockObject
	 */
	private ContributionDeclineService&MockObject $service;

	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(ContributionDeclineService::class);
	}//end setUp()

	/**
	 * A controller whose request carries this header and invoice id.
	 *
	 * @param string $header The X-Portal-Subject header.
	 * @param mixed $invoiceId The body's invoiceId.
	 *
	 * @return PortalContributionDeclineController
	 */
	private function controller(string $header, mixed $invoiceId = self::INVOICE): PortalContributionDeclineController {
		$request = $this->createStub(IRequest::class);
		$request->method('getHeader')->willReturn($header);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($key === 'invoiceId' ? $invoiceId : $default)
		);

		return new PortalContributionDeclineController(
			request: $request,
			verifier: new PortalAssertionVerifier(config: null, secretOverride: self::SECRET),
			declineService: $this->service,
			logger: new NullLogger(),
		);
	}//end controller()

	/**
	 * An assertion minted the way portaliq mints one.
	 *
	 * @param string $audience The audience claim.
	 *
	 * @return string The compact JWT.
	 */
	private function assertion(string $audience): string {
		$iat = time();
		$claims = [
			'sub' => '10000000-0000-4000-8000-000000000001',
			'audience' => $audience,
			'organisation' => '22222222-2222-2222-2222-222222222222',
			'trust' => 'low',
			'jti' => 'sessionjti0000000000000000000000',
			'use' => 'assertion',
			'iat' => $iat,
			'exp' => ($iat + 60),
			'iss' => 'portaliq',
		];
		$b64 = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
		$head = $b64((string)json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
		$body = $b64((string)json_encode($claims, JSON_UNESCAPED_SLASHES));

		return $head . '.' . $body . '.' . $b64(hash_hmac('sha256', $head . '.' . $body, self::SECRET, true));
	}//end assertion()

	/**
	 * No assertion is 401 and a supplier is 403, both before the service is
	 * asked; a parent reaches it and a decline is 200 (REQ-SCON-013).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-013)
	 */
	public function testTheReceiverGatesTheAssertionAndTheAudience(): void {
		$this->service->expects(self::once())
			->method('decline')
			->with(self::callback(static fn (array $claims): bool => $claims['audience'] === 'parent'), self::INVOICE)
			->willReturn(ContributionDeclineService::DECLINED);

		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(header: '')->decline()->getStatus());
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(header: 'not.a.jwt')->decline()->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller(header: $this->assertion(audience: 'supplier'))->decline()->getStatus());

		$response = $this->controller(header: $this->assertion(audience: 'parent'))->decline();
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['status' => 'declined'], $response->getData());
	}//end testTheReceiverGatesTheAssertionAndTheAudience()

	/**
	 * A forbidden answer is 403 with one body, a downstream error and an
	 * unexpected failure are 502 without internals, and a non-string invoice id
	 * reaches the service as an empty target (REQ-SCON-013).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-013)
	 */
	public function testTheServiceAnswerMapsToAStatus(): void {
		$this->service->method('decline')->willReturnCallback(
			static function (array $claims, string $target): string {
				if ($target === '') {
					return ContributionDeclineService::FORBIDDEN;
				}

				if ($target === 'boom') {
					throw new RuntimeException('SQLSTATE secret detail');
				}

				return ContributionDeclineService::DOWNSTREAM_ERROR;
			}
		);
		$parent = $this->assertion(audience: 'parent');

		$forbidden = $this->controller(header: $parent, invoiceId: ['not', 'a', 'string'])->decline();
		self::assertSame(Http::STATUS_FORBIDDEN, $forbidden->getStatus());
		self::assertSame(['error' => 'forbidden'], $forbidden->getData());

		self::assertSame(Http::STATUS_BAD_GATEWAY, $this->controller(header: $parent, invoiceId: self::INVOICE)->decline()->getStatus());

		$boom = $this->controller(header: $parent, invoiceId: 'boom')->decline();
		self::assertSame(Http::STATUS_BAD_GATEWAY, $boom->getStatus());
		self::assertStringNotContainsString('SQLSTATE', (string)json_encode($boom->getData()));
	}//end testTheServiceAnswerMapsToAStatus()
}//end class
