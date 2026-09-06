<?php

declare(strict_types=1);

namespace App\Services\Runner;

/**
 * Reads a container's memory accounting straight from cgroups v2.
 *
 * `podman stats` only reports current usage and costs a process launch, and by
 * the time a run is over its container is gone — so the numbers are read from
 * the kernel while the container is still alive. Everything here degrades to
 * null rather than throwing: cgroup layout differs between cgroup managers and
 * podman versions, and a missing figure is worth far less than a failed run.
 */
final class CgroupMemory
{
    private ?string $path;

    public function __construct(string $containerId)
    {
        $this->path = $this->resolve($containerId);
    }

    /**
     * High-water mark in bytes. Monotonic while the container lives, so the
     * last successful read before teardown is the run's peak.
     */
    public function peak(): ?int
    {
        $value = $this->read('memory.peak');

        return $value === null ? null : (int) $value;
    }

    /**
     * How many times the kernel OOM-killed something in this container.
     *
     * More trustworthy than reading exit code 137, which also means "killed by
     * SIGKILL" — including the kill this runner sends on a timeout.
     */
    public function oomKills(): int
    {
        $events = $this->read('memory.events');

        if ($events === null || preg_match('/^oom_kill (\d+)$/m', $events, $matches) !== 1) {
            return 0;
        }

        return (int) $matches[1];
    }

    private function read(string $file): ?string
    {
        if ($this->path === null) {
            return null;
        }

        $contents = @file_get_contents($this->path.'/'.$file);

        return $contents === false ? null : trim($contents);
    }

    private function resolve(string $containerId): ?string
    {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();

        $candidates = [
            // Rootless podman with the systemd cgroup manager — the usual case.
            "/sys/fs/cgroup/user.slice/user-{$uid}.slice/user@{$uid}.service/user.slice/libpod-{$containerId}.scope",
            // Rootful podman, systemd manager.
            "/sys/fs/cgroup/machine.slice/libpod-{$containerId}.scope",
            // The cgroupfs manager.
            "/sys/fs/cgroup/libpod_parent/libpod-{$containerId}",
        ];

        foreach ($candidates as $candidate) {
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
