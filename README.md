# Runpen

A code playground. Paste a snippet, press Run, see what it prints — or switch to
the frontend pane and get a live preview as you type.

```
Script mode     PHP 8.5 · Python 3.14 · Node 22, each run inside a throwaway container
Frontend mode   HTML / CSS / JS with a live preview and console, plus optional Tailwind
Share           saving mints a /p/abc123 link; snippets are immutable, so a link never changes
Draft           whatever is in the editors survives a reload, in your browser only
```

It is `onlinephp.io` and CodePen with the interesting part kept: **running code
that someone else wrote, without handing it your machine.**

## Stack

| Layer    | Choice                                               |
| -------- | ---------------------------------------------------- |
| Backend  | Laravel 13, SQLite                                   |
| Frontend | Inertia 3 + React 19 + TypeScript, Tailwind 4        |
| Editors  | CodeMirror 6 (grammars loaded on demand)             |
| Sandbox  | rootless Podman, one throwaway container per run     |
| Tests    | Pest — 30 tests, half of them attacks that must fail |

---

# Setup

## 1. Prerequisites

| Requirement                                | Why                       | Check                                                         |
| ------------------------------------------ | ------------------------- | ------------------------------------------------------------- |
| PHP 8.3+ with `sqlite3`, `mbstring`, `xml` | the app                   | `php -v && php -m \| grep sqlite3`                            |
| Composer 2                                 | dependencies              | `composer --version`                                          |
| Node 20+ and npm                           | the frontend build        | `node -v`                                                     |
| **Podman 4+, rootless**                    | the sandbox               | `podman info --format '{{.Host.Security.Rootless}}'` → `true` |
| cgroups v2 with delegation                 | the memory/CPU/pid limits | see step 2                                                    |

Installing Podman:

```bash
sudo dnf install podman            # Fedora / RHEL
sudo apt install podman            # Debian / Ubuntu
brew install podman && podman machine init && podman machine start   # macOS
```

Docker is **not** used anywhere. If you only have Docker, `RUNNER_PODMAN=docker`
in `.env` gets most of the way there, but `--timeout` behaves differently and the
`,z` SELinux relabel is a no-op — the sandbox tests are the arbiter.

## 2. Confirm the sandbox can actually hold

Rootless Podman needs a subuid/subgid range and delegated cgroup controllers.
Both are default on a modern Fedora/Ubuntu desktop; check rather than assume,
because without them containers still run but their **limits do not bite**:

```bash
grep "^$(whoami)" /etc/subuid /etc/subgid
# expect a line from each, e.g. gillianray:524288:65536

cat /sys/fs/cgroup/user.slice/user-$(id -u).slice/user@$(id -u).service/cgroup.controllers
# expect: cpu io memory pids
```

Missing subuid/subgid: `sudo usermod --add-subuids 100000-165535 --add-subgids 100000-165535 $(whoami)`
then `podman system migrate`.

Missing `memory` or `pids`: your user slice has no delegation. On a systemd
system, `sudo systemctl edit user@.service` and add:

```
[Service]
Delegate=cpu cpuset io memory pids
```

then log out and back in.

## 3. Install the app

```bash
git clone <this repo> grd-runpen && cd grd-runpen

composer setup      # composer install, .env, app key, migrate, npm install, npm run build
```

`composer setup` creates `.env` from `.env.example` and an SQLite file at
`database/database.sqlite`. No database server, no queue worker, no Redis — the
runner executes inside the request.

## 4. Pull the runtime images

One image per language, ~280 MB in total. Nothing is pulled at request time (a
pull inside a request would be a denial-of-service lever), so this step is
required before anything will run:

```bash
podman pull docker.io/library/php:8.5-cli-alpine
podman pull docker.io/library/python:3.14-alpine
podman pull docker.io/library/node:22-alpine
```

## 5. Verify

```bash
php artisan runner:check
```

```
podman version 5.8.4

  INFO  PHP 8.5: ok (497ms).
  INFO  Python 3.14: ok (195ms).
  INFO  Node 22: ok (240ms).
```

This is the command to run whenever something is wrong: it reports whether
Podman is present, whether each image has been pulled, and whether a sample
snippet really executes in it.

Then run the sandbox tests, which are the actual proof the isolation works:

```bash
composer test
```

## 6. Start it

```bash
composer dev        # Laravel on :8000, Vite on :5173, logs, all in one terminal
```

Open <http://127.0.0.1:8000>.

## Troubleshooting

| Symptom                                                         | Cause and fix                                                                                                                |
| --------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------- |
| `The … image has not been pulled yet` in the output pane        | Step 4 was skipped for that language. The message includes the exact `podman pull` line.                                     |
| Every run fails instantly with exit 125                         | Podman cannot start containers at all. `podman run --rm docker.io/library/alpine echo ok` to see the real error.             |
| Runs work but a memory bomb never gets killed                   | cgroup controllers are not delegated — step 2.                                                                               |
| `permission denied` reading `/code/main.*` inside the container | The scratch directory lost its `0755`. It is set on every run; a restrictive `umask` in a service unit is the usual culprit. |
| SELinux denials in `journalctl`                                 | The `,z` mount flag handles relabelling; if you changed the mount options, put it back.                                      |
| Port 8000 or 5173 already in use                                | `php artisan serve --port=8010`, and `npm run dev -- --port=5174`.                                                           |
| The concurrency gate never seems to trigger                     | `php artisan serve` handles one request at a time — see Notes.                                                               |

---

## How the sandbox works

`app/Services/Runner/PodmanRunner.php` is the heart of the project. Every run
writes the snippet to a scratch directory, mounts it read-only at `/code`, and
starts a container that is thrown away when the process exits:

```
podman run --rm --name=runpen-<id> --pull=never --timeout=5
  --network=none --read-only --tmpfs /tmp:size=8m,noexec,nosuid
  --memory=128m --memory-swap=128m --cpus=0.5 --pids-limit=64
  --cap-drop=ALL --security-opt=no-new-privileges --user=65534:65534
  --workdir=/code --volume <scratch>:/code:ro,z
  <image> <command>
```

Each flag stops something specific:

| Flag                               | What it stops                                                                  |
| ---------------------------------- | ------------------------------------------------------------------------------ |
| `--network=none`                   | Exfiltrating your data, scanning your LAN, using your IP to attack someone     |
| `--read-only`                      | Writing anywhere in the container, including its own image                     |
| `--tmpfs /tmp:noexec`              | Dropping a binary in scratch space and executing it                            |
| `--memory` + `--memory-swap`       | Allocation bombs; the container is OOM-killed instead of swapping the host     |
| `--pids-limit=64`                  | Fork bombs                                                                     |
| `--cpus=0.5`                       | One snippet monopolising the machine                                           |
| `--cap-drop=ALL`, `--user=65534`   | Running as root inside the namespace with capabilities to abuse                |
| `--security-opt=no-new-privileges` | Regaining privileges through a setuid binary                                   |
| `--timeout=5`                      | Infinite loops — conmon kills the container, so ignoring signals does not help |
| `--pull=never`                     | An unpulled image turning a request into a multi-hundred-megabyte download     |
| `--name=runpen-<id>`               | Ghost containers — see below                                                   |

Three more limits live in PHP rather than in Podman:

- **Output cap** (64 KB per stream). Output is read incrementally and the run is
  killed the moment either stream goes over, so `while True: print("x")` can't
  exhaust the web process's memory.
- **Concurrency gate** (4 runs at once, `app/Services/Runner/RunSlots.php`). Rate
  limiting caps how often one visitor may run something; this caps what the whole
  machine can be doing at once. Beyond it, requests get a 429.
- **Explicit container kill.** Killing the `podman run` client does _not_ kill the
  container — conmon keeps it alive — so a truncated run would otherwise keep
  burning CPU after its slot had been handed to someone else. The container is
  named so it can be killed directly. Order matters: the client is stopped first,
  because while it is attached and blocked on a full output pipe it holds the
  container's lock, and a `podman kill` issued first waits on that lock instead.

### What it does not protect against

The container shares your kernel. This is a strong boundary against sloppy,
greedy or nosy code and a weak one against someone with a kernel exploit. A
snippet can still read the files _inside its own image_ — `RunnerTest` asserts
that `/etc/passwd` is readable, deliberately, to mark where the promise ends.
Run this on localhost or behind auth; do not put it on the open internet and
assume the flags above are enough.

Frontend-mode pens never reach the runner at all. They execute in the visitor's
own browser, in an iframe sandboxed with `allow-scripts` but deliberately
**without** `allow-same-origin`, so the pen gets a null origin and cannot touch
the parent page's DOM, cookies or storage. `console.*` output comes back over
`postMessage`, and the parent only accepts messages whose source is that iframe.

## Layout

```
app/Services/Runner/       CodeRunner interface, PodmanRunner, FakeRunner, RunSlots
app/Console/Commands/      runner:check (diagnose), runner:prune (clean up)
app/Http/Controllers/      Playground (page), Run (execute), Snippet (save)
config/runner.php          the language allowlist, limits, and sample snippets
resources/js/pages/        playground.tsx — all page state
resources/js/components/   code-editor, script-mode, frontend-mode, output-pane
resources/js/lib/          preview (iframe document + console shim), draft, api
tests/Feature/RunnerTest   the sandbox tests: each one asserts an attack fails
```

Adding a language means adding one entry to `config/runner.php` and pulling its
image. Nothing else knows the list — the dropdown, the validation rule and the
runner all read it from there, which is why a request can never name an image.

## Configuration

Defaults live in `config/runner.php`; all of them are overridable in `.env`.

```
RUNNER_TIMEOUT=5           seconds per run
RUNNER_MAX_OUTPUT=65536    bytes per stream
RUNNER_MAX_CONCURRENT=4    containers at once, host-wide
RUNNER_MEMORY=128m
RUNNER_CPUS=0.5
RUNNER_PIDS=64
RUNNER_PODMAN=podman       path to the binary
```

Raising any of them raises what a single snippet may cost the machine.

## Tests

```bash
composer test              # 30 tests, ~17s
composer ci:check          # formatting, lint, types, PHPStan, then the tests
```

`tests/Feature/RunnerTest.php` runs real containers and is the part worth
reading: it asserts that an infinite loop is killed, a fork bomb hits the pid
limit, a 400 MB allocation is OOM-killed, runaway output is truncated _and the
container killed with it_, outbound network fails, the filesystem is read-only,
a missing image explains itself, and no container or scratch directory survives
a run. `tests/Feature/PlaygroundTest.php` swaps in `FakeRunner`, so the routes,
validation and the 429 path are tested without containers.

## Housekeeping

`php artisan runner:prune` deletes scratch directories left behind when a PHP
process dies mid-run; it is scheduled hourly in `routes/console.php`. Normal and
failed runs clean up after themselves, so on a healthy machine it always prunes
zero.

## Notes

- `php artisan serve` handles one request at a time unless started with
  `--no-reload` and `PHP_CLI_SERVER_WORKERS`, so the concurrency gate looks
  inactive under a simple `curl` burst. It is not — `PlaygroundTest` covers it,
  and with several workers the fifth simultaneous run gets a 429 as designed.
- A run costs ~200–300 ms of container startup. Keeping a warm container per
  language and `exec`-ing into it would cut that to ~20 ms; the state-reset and
  lifecycle work is the reason it is not here yet.
- Drafts live in `localStorage` and are never sent anywhere. Opening a `/p/`
  link always shows that snippet rather than your draft; **Reset** discards the
  draft and returns to the language's sample.
