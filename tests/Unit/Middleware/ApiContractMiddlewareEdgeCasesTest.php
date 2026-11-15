<?php

/**
 * Edge-case unit tests for the API contract middleware.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Middleware
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Middleware;

use OCA\Shillinq\Middleware\ApiContractMiddleware;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Pins the boundaries of the problem details rewrite: which statuses count as
 * errors, the title of every mapped status, which member becomes `detail`,
 * bodies that cannot take the shape, the app the version is read for, and the
 * successor link when the request URI does not carry the path info.
 *
 * @covers \OCA\Shillinq\Middleware\ApiContractMiddleware
 * @uses   \OCA\Shillinq\Middleware\Support\ApiPathAliases
 * @uses   \OCA\Shillinq\Middleware\Support\StandardProtocolPaths
 */
class ApiContractMiddlewareEdgeCasesTest extends TestCase {

	/**
	 * A versioned API path.
	 *
	 * @var string
	 */
	private const VERSIONED = '/apps/shillinq/api/v1/things';

	/**
	 * Run the middleware for a request.
	 *
	 * @param string   $path     The request path info.
	 * @param Response $response The controller response.
	 * @param string   $uri      The request URI.
	 *
	 * @return Response The decorated response.
	 */
	private function decorate(string $path, Response $response, string $uri=''): Response {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getPathInfo')->willReturn($path);
		if ($uri === '') {
			$uri = '/index.php'.$path;
		}

		$request->method('getRequestUri')->willReturn($uri);
		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->expects($this->once())->method('getAppVersion')->with('shillinq')->willReturn('4.5.6');

		return (new ApiContractMiddleware(request: $request, appManager: $appManager))
			->afterController(controller: null, methodName: 'any', response: $response);
	}//end decorate()

	/**
	 * Each mapped error status, with the reason phrase it is titled with.
	 *
	 * @return array<string,array{0:int,1:string}> Status and title.
	 */
	public static function titledStatuses(): array {
		return [
			'400' => [400, 'Bad Request'],
			'401' => [401, 'Unauthorized'],
			'402' => [402, 'Payment Required'],
			'403' => [403, 'Forbidden'],
			'404' => [404, 'Not Found'],
			'405' => [405, 'Method Not Allowed'],
			'406' => [406, 'Not Acceptable'],
			'409' => [409, 'Conflict'],
			'410' => [410, 'Gone'],
			'412' => [412, 'Precondition Failed'],
			'413' => [413, 'Content Too Large'],
			'415' => [415, 'Unsupported Media Type'],
			'422' => [422, 'Unprocessable Content'],
			'423' => [423, 'Locked'],
			'424' => [424, 'Failed Dependency'],
			'428' => [428, 'Precondition Required'],
			'429' => [429, 'Too Many Requests'],
			'500' => [500, 'Internal Server Error'],
			'501' => [501, 'Not Implemented'],
			'502' => [502, 'Bad Gateway'],
			'503' => [503, 'Service Unavailable'],
			'504' => [504, 'Gateway Timeout'],
			'411' => [411, 'Error'],
			'599' => [599, 'Error'],
		];
	}//end titledStatuses()

	/**
	 * Every mapped status is titled with its reason phrase, any other with `Error`.
	 *
	 * @param int    $status The response status.
	 * @param string $title  The expected title.
	 *
	 * @return void
	 *
	 * @dataProvider titledStatuses
	 */
	public function testEachErrorStatusIsTitled(int $status, string $title): void {
		$response = $this->decorate(path: self::VERSIONED, response: new JSONResponse(data: ['error' => 'x'], statusCode: $status));

		$this->assertSame(
			expected: ['type' => 'about:blank', 'title' => $title, 'status' => $status, 'detail' => 'x', 'error' => 'x'],
			actual: $response->getData()
		);
		$this->assertSame(expected: ApiContractMiddleware::PROBLEM_CONTENT_TYPE, actual: $this->headersOf(response: $response)['Content-Type']);
	}//end testEachErrorStatusIsTitled()

	/**
	 * A response below 400 on a versioned path keeps its body and content type, even one that looks like an error.
	 *
	 * @return void
	 */
	public function testNonErrorStatusesAreNotRewritten(): void {
		foreach ([200, 201, 302, 399] as $status) {
			$response = $this->decorate(path: self::VERSIONED, response: new JSONResponse(data: ['error' => 'x'], statusCode: $status));

			$this->assertSame(expected: ['error' => 'x'], actual: $response->getData(), message: (string) $status);
			$this->assertSame(
				expected: 'application/json; charset=utf-8',
				actual: $this->headersOf(response: $response)['Content-Type'],
				message: (string) $status
			);
			$this->assertSame(expected: '4.5.6', actual: $this->headersOf(response: $response)['API-Version']);
		}
	}//end testNonErrorStatusesAreNotRewritten()

	/**
	 * Without its own `detail`, the body's `message` is the detail, else its `error`; empty or non-string values are skipped.
	 *
	 * @return void
	 */
	public function testDetailIsTakenFromTheFirstUsableMember(): void {
		$cases = [
			[['message' => 'M', 'error' => 'E'], 'M'],
			[['message' => '', 'error' => 'E'], 'E'],
			[['message' => ['nested'], 'error' => 'E'], 'E'],
		];
		foreach ($cases as [$body, $detail]) {
			$data = $this->decorate(path: self::VERSIONED, response: new JSONResponse(data: $body, statusCode: 422))->getData();

			$this->assertSame(expected: $detail, actual: $data['detail'], message: json_encode($body));
			$this->assertSame(expected: ['type', 'title', 'status', 'detail'], actual: array_slice(array_keys($data), 0, 4));
		}
	}//end testDetailIsTakenFromTheFirstUsableMember()

	/**
	 * A body's own `detail` is kept as it was, even when empty or not a string.
	 *
	 * @return void
	 */
	public function testOwnDetailIsKept(): void {
		foreach (['D', '', 7] as $own) {
			$data = $this->decorate(
				path: self::VERSIONED,
				response: new JSONResponse(data: ['detail' => $own, 'message' => 'M'], statusCode: 409)
			)->getData();

			$this->assertSame(
				expected: ['type' => 'about:blank', 'title' => 'Conflict', 'status' => 409, 'detail' => $own, 'message' => 'M'],
				actual: $data
			);
		}
	}//end testOwnDetailIsKept()

	/**
	 * A body with no usable explanation gets no `detail` member.
	 *
	 * @return void
	 */
	public function testBodyWithoutExplanationGetsNoDetail(): void {
		$data = $this->decorate(
			path: self::VERSIONED,
			response: new JSONResponse(data: ['code' => 'E_LOCKED', 'error' => ''], statusCode: 423)
		)->getData();

		$this->assertSame(
			expected: ['type' => 'about:blank', 'title' => 'Locked', 'status' => 423, 'code' => 'E_LOCKED', 'error' => ''],
			actual: $data
		);
	}//end testBodyWithoutExplanationGetsNoDetail()

	/**
	 * A body that is not an array is left as it is, with its content type.
	 *
	 * @return void
	 */
	public function testScalarBodyIsLeftAlone(): void {
		$response = $this->decorate(path: self::VERSIONED, response: new JSONResponse(data: 'Not here', statusCode: 404));

		$this->assertSame(expected: 'Not here', actual: $response->getData());
		$this->assertSame(expected: 'application/json; charset=utf-8', actual: $this->headersOf(response: $response)['Content-Type']);
	}//end testScalarBodyIsLeftAlone()

	/**
	 * Each problem member that differs from what it would be blocks the rewrite; matching ones do not.
	 *
	 * @return void
	 */
	public function testEachConflictingMemberBlocksTheRewrite(): void {
		foreach ([['type' => 'https://example.org/err'], ['title' => 'Nope'], ['status' => '404']] as $body) {
			$data = $this->decorate(path: self::VERSIONED, response: new JSONResponse(data: $body, statusCode: 404))->getData();
			$this->assertSame(expected: $body, actual: $data);
		}

		$matching = $this->decorate(
			path: self::VERSIONED,
			response: new JSONResponse(data: ['status' => 404, 'error' => 'gone'], statusCode: 404)
		)->getData();
		$this->assertSame(
			expected: ['type' => 'about:blank', 'title' => 'Not Found', 'status' => 404, 'detail' => 'gone', 'error' => 'gone'],
			actual: $matching
		);
	}//end testEachConflictingMemberBlocksTheRewrite()

	/**
	 * A body keyed by numbers that is not a list is still an object and takes the shape; the merge renumbers its keys.
	 *
	 * @return void
	 */
	public function testNumericKeyedObjectIsNotAList(): void {
		$data = $this->decorate(path: self::VERSIONED, response: new JSONResponse(data: [1 => 'a'], statusCode: 400))->getData();

		$this->assertSame(expected: ['type' => 'about:blank', 'title' => 'Bad Request', 'status' => 400, 0 => 'a'], actual: $data);
	}//end testNumericKeyedObjectIsNotAList()

	/**
	 * The successor link has no prefix when the request URI does not contain the path info.
	 *
	 * @return void
	 */
	public function testSuccessorLinkWithoutPrefixWhenUriDiffers(): void {
		$response = $this->decorate(
			path: '/apps/shillinq/api/things/7',
			response: new JSONResponse(data: []),
			uri: '/rewritten/elsewhere'
		);

		$this->assertSame(
			expected: '</apps/shillinq/api/v1/things/7>; rel="successor-version"',
			actual: $this->headersOf(response: $response)['Link']
		);
	}//end testSuccessorLinkWithoutPrefixWhenUriDiffers()

	/**
	 * The successor link keeps a sub-directory install's prefix.
	 *
	 * @return void
	 */
	public function testSuccessorLinkKeepsTheInstallPrefix(): void {
		$response = $this->decorate(
			path: '/apps/shillinq/api/things',
			response: new JSONResponse(data: []),
			uri: '/cloud/index.php/apps/shillinq/api/things?page=2'
		);

		$this->assertSame(
			expected: '</cloud/index.php/apps/shillinq/api/v1/things>; rel="successor-version"',
			actual: $this->headersOf(response: $response)['Link']
		);
	}//end testSuccessorLinkKeepsTheInstallPrefix()

	/**
	 * The headers a response set, read without the server container that
	 * Response::getHeaders() needs.
	 *
	 * @param Response $response The response.
	 *
	 * @return array<string,string> The headers by name.
	 */
	private function headersOf(Response $response): array {
		$property = new ReflectionProperty(Response::class, 'headers');

		return $property->getValue($response);
	}//end headersOf()
}//end class
