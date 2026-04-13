<?php

declare(strict_types=1);

use PhpOpcua\SymfonyOpcua\Tests\Integration\Helpers\TestHelper;
use PhpOpcua\Client\Types\ConnectionState;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\StatusCode;

beforeAll(fn() => TestHelper::startDaemon());
afterAll(fn() => TestHelper::stopDaemon());

foreach (['direct' => 'createDirectManager', 'managed' => 'createManagedManager'] as $mode => $factory) {

    describe("ConnectionState via OpcuaManager ({$mode} mode)", function () use ($factory) {

        it('reports Connected after connect()', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();
                expect($client->isConnected())->toBeTrue();
                expect($client->getConnectionState())->toBe(ConnectionState::Connected);
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('reconnects successfully', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();
                $client->reconnect();
                expect($client->isConnected())->toBeTrue();

                $dv = $client->read(NodeId::numeric(0, 2259));
                expect($dv->statusCode)->toBe(StatusCode::Good);
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

    })->group('integration');

}
