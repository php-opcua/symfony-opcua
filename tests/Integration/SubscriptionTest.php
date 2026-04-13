<?php

declare(strict_types=1);

use PhpOpcua\SymfonyOpcua\Tests\Integration\Helpers\TestHelper;
use PhpOpcua\Client\Types\StatusCode;

beforeAll(fn() => TestHelper::startDaemon());
afterAll(fn() => TestHelper::stopDaemon());

foreach (['direct' => 'createDirectManager', 'managed' => 'createManagedManager'] as $mode => $factory) {

    describe("Subscriptions via OpcuaManager ({$mode} mode)", function () use ($factory) {

        it('creates and deletes a subscription', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();

                $sub = $client->createSubscription(publishingInterval: 500.0);
                expect($sub->subscriptionId)->toBeInt()->toBeGreaterThan(0);

                $status = $client->deleteSubscription($sub->subscriptionId);
                expect(StatusCode::isGood($status))->toBeTrue();
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

        it('creates monitored items and receives a publish response', function () use ($factory) {
            $manager = TestHelper::$factory();
            try {
                $client = $manager->connect();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'Dynamic', 'Counter']);

                $sub = $client->createSubscription(publishingInterval: 250.0);

                $monitored = $client->createMonitoredItems($sub->subscriptionId, [
                    ['nodeId' => $nodeId, 'clientHandle' => 1],
                ]);

                expect($monitored)->toHaveCount(1);
                expect(StatusCode::isGood($monitored[0]->statusCode))->toBeTrue();

                usleep(500_000);

                $pub = $client->publish();
                expect($pub->subscriptionId)->toBe($sub->subscriptionId);

                $client->deleteSubscription($sub->subscriptionId);
            } finally {
                TestHelper::safeDisconnect('default', $manager);
            }
        })->group('integration');

    })->group('integration');

}
