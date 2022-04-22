<?php

declare(strict_types=1);

namespace ADS\ClientMock;

use ADS\Util\ArrayUtil;
use ADS\ValueObjects\ValueObject;
use EventEngine\Data\ImmutableRecord;
use Mockery;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

use function array_map;
use function gettype;
use function is_array;
use function is_scalar;
use function json_encode;
use function method_exists;
use function preg_match;
use function preg_quote;
use function sprintf;
use function str_replace;

abstract class ClientMock
{
    public const COULD_BE_ANYTHING = '__**__';

    /** @var array<string, array<array<string, array<mixed>>>> */
    private static array $mocks = [];

    protected static Mockery\MockInterface|Mockery\LegacyMockInterface|null $client = null;

    protected static function client(): Mockery\LegacyMockInterface|Mockery\MockInterface
    {
        if (static::$client) {
            return static::$client;
        }

        static::$client = Mockery::mock('fakeClient');

        Mockery::getConfiguration()->setObjectFormatter(
            ImmutableRecord::class,
            static fn ($object) => ['properties' => $object->toArray()]
        );

        /** @var Mockery\ExpectationInterface  $clientFactory */
        $clientFactory = static::clientFactory();
        $clientFactory->andReturn(static::$client);

        return static::$client;
    }

    protected static function clientFactory(): Mockery\ExpectationInterface|Mockery\HigherOrderMessage
    {
        /** @var Mockery\MockInterface $expectation */
        $expectation = Mockery::mock(
            sprintf(
                'overload:%s',
                static::factoryClass()
            )
        );

        return static::describeFactoryMock($expectation);
    }

    public static function clearClientMock(): void
    {
        static::$client = null;
    }

    abstract protected static function describeFactoryMock(
        Mockery\MockInterface $expectation
    ): Mockery\ExpectationInterface|Mockery\HigherOrderMessage;

    public static function registerMock(string $method, mixed $response = null, mixed ...$requestParameters): void
    {
        self::checkClientMethodExists($method);

        if (! isset(static::$mocks[$method])) {
            static::$mocks[$method] = [];
        }

        foreach (static::$mocks[$method] as $index => $mockForMethodPerRequest) {
            if (self::equalRequestParameterList($mockForMethodPerRequest['requestParameters'], $requestParameters)) {
                static::$mocks[$method][$index]['response'][] = $response;

                return;
            }
        }

        static::$mocks[$method][] = [
            'requestParameters' => $requestParameters,
            'response' => [$response],
        ];
    }

    private static function checkClientMethodExists(string $method): void
    {
        $clientClass = static::clientClass();

        if (method_exists($clientClass, $method)) {
            return;
        }

        throw new RuntimeException(
            sprintf(
                'Can\'t mock method \'%s\' for class \'%s\' because it doesn\'t exists.',
                $method,
                $clientClass
            )
        );
    }

    /**
     * @param array<mixed> $existingRequestParameters
     * @param array<mixed> $newRequestParameters
     */
    private static function equalRequestParameterList(
        array $existingRequestParameters,
        array $newRequestParameters
    ): bool {
        $index = -1;
        foreach ($existingRequestParameters as $index => $existingRequestParameter) {
            if (! isset($newRequestParameters[$index])) {
                return false;
            }

            if (! static::equalRequestParameters($existingRequestParameter, $newRequestParameters[$index])) {
                return false;
            }
        }

        // if the new request parameters contain more parameters than the existing request parameters does
        return ! isset($newRequestParameters[$index + 1]);
    }

    public static function buildMocks(): void
    {
        $client = self::client();

        foreach (static::$mocks as $method => $differentRequests) {
            foreach ($differentRequests as $differentRequest) {
                /** @var Mockery\Expectation $requestExpectation */
                $requestExpectation = $client->shouldReceive($method);

                if (! empty($differentRequest['requestParameters'])) {
                    $requestParameters = array_map(
                        static fn ($expectedRequestParameter) => Mockery::on(
                            static function ($givenRequestParameter) use ($expectedRequestParameter) {
                                return static::equalRequestParameters(
                                    $expectedRequestParameter,
                                    $givenRequestParameter
                                );
                            }
                        ),
                        $differentRequest['requestParameters']
                    );

                    $requestExpectation->with(...$requestParameters);
                }

                $requestExpectation->andReturnValues(
                    array_map(
                        static fn ($response) => static::buildResponse($method, $response),
                        $differentRequest['response']
                    )
                );
            }
        }

        static::$mocks = [];
    }

    protected static function equalRequestParameters(mixed $requestParameterOfCall, mixed $requestParameterOfMock): bool
    {
        if ($requestParameterOfCall instanceof ImmutableRecord) {
            $requestParameterOfCall = $requestParameterOfCall->toArray();
        }

        if ($requestParameterOfMock instanceof ImmutableRecord) {
            $requestParameterOfMock = $requestParameterOfMock->toArray();
        }

        if ($requestParameterOfCall instanceof ValueObject) {
            $requestParameterOfCall = $requestParameterOfCall->toValue();
        }

        if ($requestParameterOfMock instanceof ValueObject) {
            $requestParameterOfMock = $requestParameterOfMock->toValue();
        }

        if (gettype($requestParameterOfCall) !== gettype($requestParameterOfMock)) {
            return false;
        }

        if (is_array($requestParameterOfCall) && is_array($requestParameterOfMock)) {
            return self::sameArrays($requestParameterOfCall, $requestParameterOfMock);
        }

        if (is_scalar($requestParameterOfCall)) {
            return $requestParameterOfCall === $requestParameterOfMock;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $first
     * @param array<string, mixed> $second
     */
    private static function sameArrays(array $first, array $second): bool
    {
        ArrayUtil::ksortRecursive($first);
        ArrayUtil::ksortRecursive($second);

        $encodedFirst = json_encode($first);
        $encodedSecond = json_encode($second);

        if ($encodedFirst === false || $encodedSecond === false) {
            return false;
        }

        $quotedSecond = str_replace(['"__\*\*__"', '__\*\*__'], '.*', preg_quote($encodedSecond, '/'));

        return (bool) preg_match('/' . $quotedSecond . '/', $encodedFirst);
    }

    abstract protected static function buildResponse(string $method, mixed $response): mixed;

    /**
     * @return class-string
     */
    abstract protected static function factoryClass(): string;

    /**
     * @return class-string
     */
    abstract protected static function clientClass(): string;

    /**
     * @return ReflectionClass<object>|null
     */
    protected static function clientReflectionClass(): ?ReflectionClass
    {
        return new ReflectionClass(static::clientClass());
    }

    protected static function reflectionMethod(string $method): ReflectionMethod
    {
        /** @var ReflectionClass<object> $clientReflectionClass */
        $clientReflectionClass = static::clientReflectionClass();

        if (! $clientReflectionClass->hasMethod($method)) {
            throw new RuntimeException(
                sprintf(
                    'Method \'%s\' not found in class \'%s\'.',
                    $method,
                    $clientReflectionClass->getName()
                )
            );
        }

        return $clientReflectionClass->getMethod($method);
    }

    /**
     * @param array<mixed> $arguments
     */
    public static function __callStatic(string $name, array $arguments): void
    {
        self::registerMock($name, ...$arguments);
    }
}