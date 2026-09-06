<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Code runner
|--------------------------------------------------------------------------
|
| Everything the sandbox needs to know. The language list is an *allowlist*:
| a request may only name a key from it, and the image and command are read
| from here — never from user input. That is the single most important
| property of this file. If an attacker could choose the image, none of the
| flags below would matter.
|
*/

return [

    // Wall-clock seconds a snippet may run. Enforced by `podman run --timeout`,
    // which has conmon kill the container itself, so a program that ignores
    // signals still dies. Symfony's own timeout below is only a backstop for a
    // podman client that hangs.
    'timeout' => (int) env('RUNNER_TIMEOUT', 5),

    // Per-stream output cap. A `while (true) echo "x";` loop would otherwise
    // stream until PHP runs out of memory, so we stop reading and kill the
    // process once either stream passes this.
    'max_output_bytes' => (int) env('RUNNER_MAX_OUTPUT', 64 * 1024),

    // How many containers may run at once, host-wide. Each one is allowed half
    // a core, so four is a deliberate ceiling on what the playground can cost
    // the machine it runs on.
    'max_concurrent' => (int) env('RUNNER_MAX_CONCURRENT', 4),

    'podman' => env('RUNNER_PODMAN', 'podman'),

    // Per-run container limits. Comments explain what each one actually stops;
    // the README repeats them in prose.
    'memory' => env('RUNNER_MEMORY', '128m'),
    'cpus' => env('RUNNER_CPUS', '0.5'),
    'pids' => (int) env('RUNNER_PIDS', 64),

    /*
    |----------------------------------------------------------------------
    | Warm spares
    |----------------------------------------------------------------------
    |
    | Starting a container costs ~200ms, which is most of a short snippet's
    | round trip. The pool keeps a few already-started containers waiting so a
    | run only pays for `podman exec` (~100ms).
    |
    | Each container is still used for exactly ONE run and then destroyed: the
    | isolation story does not change, the startup just happens before the
    | request rather than during it. `ttl` is passed as the container's own
    | --timeout, so a spare that is never claimed (or one leaked by a PHP
    | process that died mid-run) removes itself.
    |
    */
    'pool' => [
        'enabled' => (bool) env('RUNNER_POOL', true),
        'size' => (int) env('RUNNER_POOL_SIZE', 2),
        'ttl' => (int) env('RUNNER_POOL_TTL', 300),
    ],

    'languages' => [

        'php' => [
            'label' => 'PHP',
            'version' => '8.5',
            'image' => 'docker.io/library/php:8.5-cli-alpine',
            'filename' => 'main.php',
            'command' => ['php', '/code/main.php'],
            'editor' => 'php',
            'sample' => "<?php\n\n\$versions = ['php' => PHP_VERSION];\n\nforeach (\$versions as \$name => \$version) {\n    printf(\"%s %s\\n\", \$name, \$version);\n}\n",
        ],

        'python' => [
            'label' => 'Python',
            'version' => '3.14',
            'image' => 'docker.io/library/python:3.14-alpine',
            'filename' => 'main.py',
            'command' => ['python', '/code/main.py'],
            'editor' => 'python',
            'sample' => "import sys\n\nprint(sys.version)\nprint(sum(n * n for n in range(10)))\n",
        ],

        'node' => [
            'label' => 'Node',
            'version' => '22',
            'image' => 'docker.io/library/node:22-alpine',
            'filename' => 'main.js',
            'command' => ['node', '/code/main.js'],
            'editor' => 'javascript',
            'sample' => "console.log(process.version);\nconsole.log([1, 2, 3].map((n) => n ** 2));\n",
        ],

    ],

];
