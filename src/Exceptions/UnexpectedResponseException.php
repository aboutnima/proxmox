<?php

declare(strict_types=1);

namespace Proxmox\Exceptions;

use UnexpectedValueException;

class UnexpectedResponseException extends UnexpectedValueException implements ProxmoxException
{
    public static function expectedList(): self
    {
        return new self('Expected a list in the Proxmox API response "data" field.');
    }

    public static function expectedListOfArrays(): self
    {
        return new self('Expected array entries in the Proxmox API response "data" field.');
    }

    public static function expectedAttributes(): self
    {
        return new self('Expected an attribute object in the Proxmox API response "data" field.');
    }

    public static function expectedTaskId(): self
    {
        return new self('Expected a task ID in the Proxmox API response "data" field.');
    }

    public static function expectedVirtualMachineId(): self
    {
        return new self('Expected a VM ID in the Proxmox API response "data" field.');
    }

    public static function expectedImage(): self
    {
        return new self('Expected format and volid strings in the Proxmox image listing.');
    }

    public static function expectedTaskStatus(): self
    {
        return new self('Expected a running or stopped Proxmox task status.');
    }

    public static function expectedTaskExitStatus(): self
    {
        return new self('Expected an exitstatus in the Proxmox task response.');
    }
}
