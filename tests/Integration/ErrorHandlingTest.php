<?php

declare(strict_types=1);

use PhpOpcua\SymfonyOpcua\Tests\Integration\Helpers\TestHelper;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\StatusCode;

beforeAll(fn() => TestHelper::startDaemon());
afterAll(fn() => TestHelper::stopDaemon());

foreach (['direct' => 'createDirectManager', 'managed' => 'createManagedManager'] as $mode => $factory) {

    describe("Error handling via OpcuaManager ({$mode} mode)", function () use ($factory) {

        it('returns Bad status for non-existent node', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();
                $dv = $client->read('ns=99;i=99999');
                expect(StatusCode::isBad($dv->statusCode))->toBeTrue();
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('returns Bad status when writing to read-only node', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'ReadOnly', 'Boolean_RO']);
                $status = $client->write($nodeId, true, BuiltinType::Boolean);
                expect(StatusCode::isBad($status))->toBeTrue();
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

    })->group('integration');

}
