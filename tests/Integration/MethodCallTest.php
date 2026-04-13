<?php

declare(strict_types=1);

use PhpOpcua\SymfonyOpcua\Tests\Integration\Helpers\TestHelper;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\StatusCode;
use PhpOpcua\Client\Types\Variant;

beforeAll(fn() => TestHelper::startDaemon());
afterAll(fn() => TestHelper::stopDaemon());

foreach (['direct' => 'createDirectManager', 'managed' => 'createManagedManager'] as $mode => $factory) {

    describe("Method calls via OpcuaManager ({$mode} mode)", function () use ($factory) {

        it('calls Add method (3 + 4 = 7)', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();
                $methodsNodeId = TestHelper::browseToNode($client, ['TestServer', 'Methods']);

                $addRef = TestHelper::findRefByName($client->browse($methodsNodeId), 'Add');
                expect($addRef)->not->toBeNull();

                $result = $client->call(
                    $methodsNodeId,
                    $addRef->nodeId,
                    [
                        new Variant(BuiltinType::Double, 3.0),
                        new Variant(BuiltinType::Double, 4.0),
                    ],
                );

                expect(StatusCode::isGood($result->statusCode))->toBeTrue();
                expect(abs($result->outputArguments[0]->value - 7.0))->toBeLessThan(0.001);
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('calls Multiply method (6 * 7 = 42)', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();
                $methodsNodeId = TestHelper::browseToNode($client, ['TestServer', 'Methods']);

                $mulRef = TestHelper::findRefByName($client->browse($methodsNodeId), 'Multiply');
                expect($mulRef)->not->toBeNull();

                $result = $client->call(
                    $methodsNodeId,
                    $mulRef->nodeId,
                    [
                        new Variant(BuiltinType::Double, 6.0),
                        new Variant(BuiltinType::Double, 7.0),
                    ],
                );

                expect(StatusCode::isGood($result->statusCode))->toBeTrue();
                expect(abs($result->outputArguments[0]->value - 42.0))->toBeLessThan(0.001);
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

    })->group('integration');

}
