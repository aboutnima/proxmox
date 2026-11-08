<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Proxmox\Exceptions\TaskFailedException;
use Proxmox\Exceptions\TaskTimeoutException;
use Proxmox\Exceptions\UnexpectedResponseException;
use Proxmox\Facades\Cluster;
use Proxmox\Resources\Tasks\Task;

const TASK_UPID = 'UPID:pve1:0001F2A3:01D0C8F4:6650A1B2:qmcreate:101:root@pam:';

beforeEach(function () {
    Cluster::connectUsing('default', [
        'url' => 'https://proxmox.example.test:8006/api2/json',
        'api_token_id' => 'laravel@pam!package',
        'api_token_secret' => 'token-secret',
    ]);

    Http::preventStrayRequests();
    Carbon::setTestNow('2026-01-01 00:00:00');
    Sleep::fake(syncWithCarbon: true);
});

afterEach(function () {
    Sleep::fake(false);
    Carbon::setTestNow();
});

function taskStatusUrl(): string
{
    return 'https://proxmox.example.test:8006/api2/json/nodes/pve1/tasks/'.rawurlencode(TASK_UPID).'/status';
}

it('reads the node from a full UPID', function () {
    $task = Cluster::task(TASK_UPID);

    expect($task)->toBeInstanceOf(Task::class)
        ->and($task->node)->toBe('pve1')
        ->and($task->upid)->toBe(TASK_UPID)
        ->and((string) $task)->toBe(TASK_UPID);
});

it('rejects values that are not UPIDs', function (string $upid) {
    expect(fn () => Cluster::task($upid))->toThrow(InvalidArgumentException::class, 'UPID');
})->with(['', 'pve1:0001', 'UPID:', 'UPID::x']);

it('selects a task on an explicit node', function () {
    $task = Cluster::node('pve1')->task(TASK_UPID);

    expect($task->node)->toBe('pve1')
        ->and(fn () => Cluster::node('pve1')->task(' '))->toThrow(InvalidArgumentException::class, 'UPID');
});

it('reads the task status', function () {
    Http::fake([taskStatusUrl() => Http::response(['data' => ['status' => 'running', 'type' => 'qmcreate']])]);

    $task = Cluster::task(TASK_UPID);

    expect($task->status())->toBe(['status' => 'running', 'type' => 'qmcreate'])
        ->and($task->isRunning())->toBeTrue();
});

it('waits until a task stops successfully', function (string $exitStatus) {
    Http::fake([taskStatusUrl() => Http::sequence()
        ->push(['data' => ['status' => 'running']])
        ->push(['data' => ['status' => 'running']])
        ->push(['data' => ['status' => 'stopped', 'exitstatus' => $exitStatus]]),
    ]);

    $task = Cluster::task(TASK_UPID);

    expect($task->wait(timeout: 10, interval: 2))->toBe($task);

    Sleep::assertSleptTimes(2);
    Sleep::assertSequence([Sleep::for(2)->seconds(), Sleep::for(2)->seconds()]);
    Http::assertSentCount(3);
})->with(['OK', 'WARNINGS: 2']);

it('throws when a task stops with an error', function () {
    Http::fake([taskStatusUrl() => Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'unable to create VM 101']])]);

    try {
        Cluster::task(TASK_UPID)->wait();
        $this->fail('The task should have failed.');
    } catch (TaskFailedException $exception) {
        expect($exception->upid)->toBe(TASK_UPID)
            ->and($exception->exitStatus)->toBe('unable to create VM 101')
            ->and($exception->getMessage())->toContain('unable to create VM 101');
    }

    Sleep::assertNeverSlept();
});

it('throws when a task does not stop before the timeout', function () {
    Http::fake([taskStatusUrl() => Http::response(['data' => ['status' => 'running']])]);

    try {
        Cluster::task(TASK_UPID)->wait(timeout: 3);
        $this->fail('The task should have timed out.');
    } catch (TaskTimeoutException $exception) {
        expect($exception->upid)->toBe(TASK_UPID)
            ->and($exception->timeout)->toBe(3)
            ->and($exception->getMessage())->toContain(TASK_UPID);
    }

    Sleep::assertSleptTimes(3);
    Http::assertSentCount(3);
});

it('rejects invalid wait arguments before polling', function (int $timeout, int $interval) {
    Http::fake();

    expect(fn () => Cluster::task(TASK_UPID)->wait($timeout, $interval))
        ->toThrow(InvalidArgumentException::class, 'positive');

    Http::assertNothingSent();
})->with([[0, 1], [10, 0], [-1, 1]]);

it('rejects malformed task status responses', function (array $data) {
    Http::fake([taskStatusUrl() => Http::response(['data' => $data])]);

    expect(fn () => Cluster::task(TASK_UPID)->wait())->toThrow(UnexpectedResponseException::class);
})->with([
    'missing status' => [['type' => 'qmcreate']],
    'unknown status' => [['status' => 'paused']],
    'missing exit status' => [['status' => 'stopped']],
    'empty exit status' => [['status' => 'stopped', 'exitstatus' => '']],
]);

it('reads task log lines with pagination', function () {
    Http::fake(['*' => Http::response(['data' => [['n' => 21, 't' => 'transferred 1.0 GiB']]])]);

    expect(Cluster::task(TASK_UPID)->log(start: 20, limit: 10))->toBe([['n' => 21, 't' => 'transferred 1.0 GiB']]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://proxmox.example.test:8006/api2/json/nodes/pve1/tasks/'.rawurlencode(TASK_UPID).'/log?start=20&limit=10');
});
