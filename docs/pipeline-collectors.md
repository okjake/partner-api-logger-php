# Sending Partner API logs through your own pipeline

This guide is for the engineer who runs your log pipeline. It shows how to
forward the Partner API SDK's log lines from an OpenTelemetry Collector, Fluent
Bit or Vector you already operate, and how to write those lines yourself if
you do not use the SDK. Every configuration below was run as printed (see
[What was tested](#what-was-tested)). Logstash is not supported yet: it has no
OTLP/HTTP output that this guide could test.

The guide is the same in both SDK packages: the TypeScript package
`@partner-api/logger` and the PHP package `partner-api/logger`.

- [Push or pipeline?](#push-or-pipeline)
- [How it works](#how-it-works)
- [Before you start](#before-you-start)
- [Choose a configuration](#choose-a-configuration)
- [OpenTelemetry Collector](#opentelemetry-collector)
- [Fluent Bit](#fluent-bit)
- [Vector](#vector)
- [What to know](#what-to-know)
- [Logging webhooks](#logging-webhooks)
- [Troubleshooting](#troubleshooting)
- [Limits](#limits)
- [Without the SDK](#without-the-sdk)
- [What was tested](#what-was-tested)

## Push or pipeline?

**Push** is the SDK's default. The SDK sends each batch of log lines straight
to Partner API from inside your process, with its own buffer and retries. Stay
on push for serverless functions, edge runtimes and small deployments, and
anywhere you do not run a log collector: push is the only mode in which the
SDK delivers anything by itself.

**Pipeline** suits teams that already ship standard output through a
collector at volume and do not want a second shipper inside every process.
The SDK writes each log call as one line, your collector forwards those lines
to Partner API, and everything else your collector does stays as it is.

Moving between the two is a configuration change in the SDK; your log calls
stay as they are. Metric calls (`metric()` / `metrics()`) always use push,
in both modes.

## How it works

In pipeline mode the SDK writes every log call as one JSON line to standard
output (or to a file you choose). Each line starts with exactly these bytes:

```text
{"partnerapi_line":
```

Your collector reads the lines, keeps those that start with that prefix, and
sends them to `POST https://ingest.partnerapi.com/v1/logs` over OTLP/HTTP with
your tenant token in an `x-tenant-token` header. The line names its partner
with a **partner reference**, a keyed hash of the partner's app key, never the
key itself; Partner API resolves the reference to the partner inside your
tenant. Lines that do not start with the prefix never leave your pipeline.

A line looks like this (one line, shortened here):

```text
{"partnerapi_line":"1.6.0","partnerapi_partner_ref":"v1:716768d2…c676","partnerapi_timestamp":"1700000200000000000","partnerapi_level":"info","level":"info","message":"Charge retried","charge":"ch_1","attempt":2}
```

## Before you start

1. **Your tenant token.** The same token goes to your app (the SDK uses it to
   compute each partner reference) and to your collector (it authenticates
   the export). Keep it in a secret store and give it to both from there: an
   environment variable, `PARTNER_API_TENANT_TOKEN` below, or for Vector a
   secret file. A line written under one token and sent under another matches
   no partner and is dropped.
2. **The SDK in stdout mode.** Only the constructor changes:
   - **TypeScript** (`@partner-api/logger` 3.2.0 or later):

     ```ts
     const logger = new Logger({
       tenantToken: process.env.PARTNER_API_TENANT_TOKEN,
       delivery: 'stdout',
       // Optional: a function that receives each line, '\n' included,
       // instead of process.stdout — for example to write a file.
       // sink: (line) => out.write(line),
     });
     ```

     Under Node `cluster` or PM2, several processes share one stdout and a
     long line can be interleaved with another process's output; give each
     process its own file through `sink`.

   - **PHP** (`partner-api/logger` 2.2.0 or later). With Laravel, in `.env`:

     ```env
     PARTNER_API_LOG_MODE=stdout
     PARTNER_API_LOG_STDOUT_SINK=/var/log/app/partner-api.jsonl
     ```

     Without Laravel:

     ```php
     $logger = new Logger(
         tenantToken: getenv('PARTNER_API_TENANT_TOKEN'),
         options: [
             'mode' => Logger::MODE_STDOUT,
             'stdoutSink' => '/var/log/app/partner-api.jsonl',
         ],
     );
     ```

     **Under PHP-FPM and Octane the sink must be a file**, by absolute path:
     FPM prefixes and splits its workers' output, and Octane re-encodes it,
     so neither reaches a collector intact. Use the default sink
     (`php://stdout`) only for a plain CLI process whose output your container
     runtime captures, such as `queue:work` run directly. The file must be
     readable by your collector: on Kubernetes, a volume both containers
     mount.

   - **No SDK?** Write the lines yourself: see
     [Without the SDK](#without-the-sdk).

3. **On Kubernetes and Docker, the lines go to standard output.** The
   container configurations below drop everything a container writes to
   standard error, so that a stderr line can never be joined into a Partner
   API line. The SDKs write to stdout by default; do not point them at
   `php://stderr` or `process.stderr`.
4. **The partner's app is approved.** A reference resolves only to an app
   in the `APPROVED` state in your tenant.
5. **Your collector can reach `https://ingest.partnerapi.com`** on port 443.
6. **Add it to a collector you already run with care.** Every component here
   is suffixed `/partnerapi` so that it can sit beside yours, but none of the
   three tools merges configuration files safely:
   - **OpenTelemetry Collector:** add our components to your existing
     configuration **by hand**. Copy our extension, receiver, processors and
     exporter in beside yours, **append** `file_storage/partnerapi` to your
     existing `service.extensions` list, and add our `logs/partnerapi`
     pipeline under `service.pipelines`. Never pass our file as a second
     `--config`: the collector merges files by replacing lists, so your
     `service.extensions` would lose your own extensions (a `health_check`
     your Kubernetes probes call, say), and `validate` does not catch it.
   - **Fluent Bit:** its `service` section is global. Run ours as a separate
     instance, or reconcile `flush`, `storage.path` and
     `storage.max_chunks_up` with yours by hand, and copy our `parsers`
     section across too: without it Fluent Bit does not start
     (`requested parser 'partnerapi_json' not found`), and `--dry-run` does
     not catch that.
   - **Vector:** `data_dir` is global, and a merged configuration moves every
     source's read positions to ours, so your own sources start over. Run
     ours as a separate instance, or keep your `data_dir` and `secret`
     settings and add only our sources, transforms and sink.

   Then run the tool's own check (`otelcol-contrib validate --config …`,
   `fluent-bit -c … --dry-run`, `vector validate …`), deploy to one node, and
   check its log before rolling out everywhere: a mistake in our part (an
   unwritable state directory, say) can stop the whole collector.

## Choose a configuration

Pick the row for where your app's lines end up, and the column for your
collector. Each configuration is a separate pipeline that reads the same files
as your existing pipelines.

| Where the lines are                                              | OpenTelemetry Collector                              | Fluent Bit                              | Vector                              |
| ---------------------------------------------------------------- | ---------------------------------------------------- | --------------------------------------- | ----------------------------------- |
| Container stdout on Kubernetes (containerd, CRI-O)               | [Kubernetes](#opentelemetry-collector-on-kubernetes) | [Kubernetes](#fluent-bit-on-kubernetes) | [Kubernetes](#vector-on-kubernetes) |
| Container stdout on Docker, `json-file` logging driver (default) | [Docker](#opentelemetry-collector-on-docker)         | [Docker](#fluent-bit-on-docker)         | [Docker](#vector-on-docker)         |
| A file the SDK writes (PHP-FPM, Octane, or a `sink` of your own) | [File](#opentelemetry-collector-reading-a-file)      | [File](#fluent-bit-reading-a-file)      | [File](#vector-reading-a-file)      |

Every configuration does the same things, and the notes under each say how
that tool does them:

1. **Reads the lines exactly as your app wrote them.** Container runtimes wrap
   each line (Kubernetes runtimes prefix `<time> stdout F `; Docker wraps it in
   `{"log":"…","stream":…}`) and split a line longer than 16 KiB into parts.
   The configuration drops stderr, removes the wrapper and rejoins the parts
   **before** it filters, or the filter matches nothing and long lines arrive
   broken.
2. **Forwards only whole Partner API lines, and fails closed.** A line is kept
   only when it starts with `{"partnerapi_line":`, ends with `}` and parses
   as a JSON object. If a check itself fails, nothing it was looking at is
   forwarded: the line is dropped (with the OpenTelemetry Collector's prefix
   filter, so is every line read with it, so a failing filter loses lines
   rather than leak them).
3. **Keeps every export within Partner API's limits:** at most 100 lines per
   export (Partner API takes 10,000) and well under 5 MiB, with two exports in
   flight at most. Partner API refuses an over-size export whole, and answers
   `503` to a collector that sends too many at once. A single line over 1 MiB
   is dropped, so it cannot make its export too large.
4. **Sends the token from outside the file:** an environment variable, or for
   Vector a secret file.
5. **Bounds memory and survives outages and restarts.** Exports waiting for
   Partner API go to disk, within a cap; the read position of every file is
   saved, so a restarted collector carries on where it stopped. In our tests a
   45-second outage with a restart in the middle lost nothing with any of the
   three (see [What to know](#what-to-know) for what each does past its cap).
6. **On first start, reads existing files from the beginning,** skipping files
   untouched for a day, so lines your app wrote before the collector was
   deployed are sent too. Partner API drops any that are more than about 7
   days old.

**If you can choose, use the OpenTelemetry Collector or Fluent Bit.** Both
log every refused export and every line Partner API drops, and send lines as
they are written. Vector's configuration works and lost nothing in our
tests, but it logs refused exports unreliably and Partner API's dropped-line
counts not at all (see [Vector](#vector)), it can hold a burst of lines until
the next one is written, and its JSON check drops two kinds of valid line.

## OpenTelemetry Collector

Use the **contrib** distribution (`otelcol-contrib`, image
`otel/opentelemetry-collector-contrib`): it has the `file_log` receiver, the
`filter` processor and the `file_storage` extension. The configurations were
tested on 0.161.0.

Deploy notes, for all three:

- Give the collector `PARTNER_API_TENANT_TOKEN` in its environment (on
  Kubernetes, from a Secret).
- **Mount a writable state directory.** `file_storage` keeps the read
  positions and the exports waiting to be sent in
  `/var/lib/otelcol/partnerapi`. Mount it from the node (Kubernetes
  `hostPath`) or the host, owned by the user the collector runs as
  (10001:10001 in the official image). Without the mount the collector does
  not start (`failed to create extension "file_storage/partnerapi": mkdir /var: permission denied`);
  with a directory owned by root, which is what a `hostPath` of
  `type: DirectoryOrCreate` gives you, it does not start either
  (`failed to start "otlp_http/partnerapi" exporter: open /var/lib/otelcol/partnerapi/…: permission denied`).
  Create the directory with that owner first, for example with an init
  container that runs `chown 10001:10001` on it.
- **Give the collector container a memory limit.** `memory_limiter` works in
  percentages of the container's limit; without one, 80% means 80% of the
  node's memory.
- **During an outage**, exports wait in the queue on disk: at most 50, each
  under 4 MiB, so at most about 200 MiB (the state file grows to its
  largest size and does not shrink). When the queue is full the receiver
  stops reading until there is room, and the lines stay in your files;
  retries continue with no time limit (`max_elapsed_time: 0`). Partner API's
  `429` and `503` are retried; other errors are not. A 45-second outage with
  a restart in the middle lost nothing. A restart in the middle of an outage
  can send again what was in the queue: up to 50 exports of 100 lines, about
  5,000 lines (we measured exactly 5,000). Lines are lost only if your
  runtime deletes a log file the collector has not read yet.
- **Losing the state directory** (a replaced node, a cleared `hostPath`)
  makes the collector start over: it re-sends what is still in files touched
  in the last 24 hours.
- **The JSON check.** After the prefix filter, a `transform` marks each line
  that parses as a JSON object, a second `filter` drops every unmarked line,
  and the mark is removed before sending. A line that does not parse (a line
  cut off mid-write and joined to the next one, even when that one is a JSON
  log ending in `}`) stays unmarked and is dropped; so is every line if the
  check itself fails, because nothing is marked.
- `send_batch_max_size` must be set: its default, 0, sets no maximum. The
  exporter's `sending_queue.batch` then splits any export over 4 MiB.
- The `filter` processor's `error_mode: propagate` is what makes it fail
  closed. With `ignore` (or `silent`), a condition that fails to evaluate
  counts as false, and because this condition is negated ("drop unless it is
  a whole Partner API line"), the line would be **forwarded**. Under
  `propagate`, the whole batch read with the failing line is dropped and the
  collector logs `failed to eval condition`.
- On first start the receiver reads existing files from the beginning
  (`start_at: beginning`), skipping files untouched for 24 hours
  (`exclude_older_than`); after that it resumes from `file_storage`.
- The Kubernetes configuration expects the containerd / CRI-O log format.
  Kubernetes 1.23 and older with dockershim writes Docker's JSON format
  instead: use the Docker configuration with the Kubernetes paths there.

### OpenTelemetry Collector on Kubernetes

Run as a DaemonSet with the node's `/var/log/pods` mounted read-only.

<!-- tested-config: otelcol/kubernetes-cri -->

```yaml
# OpenTelemetry Collector (contrib distribution), tested on 0.161.0.
# Older releases call file_log and otlp_http filelog and otlphttp,
# and may not support sending_queue.batch or block_on_overflow.
# Kubernetes nodes (containerd or CRI-O), run as a DaemonSet.
# Needs PARTNER_API_TENANT_TOKEN in the collector's environment: the
# same token your app's SDK holds.
extensions:
  # Keeps how far each file was read, and the exports waiting to be
  # sent. Mount it from the node (hostPath), writable by the collector's user.
  file_storage/partnerapi:
    directory: /var/lib/otelcol/partnerapi
    create_directory: true

receivers:
  file_log/partnerapi:
    include: [/var/log/pods/*/*/*.log]
    storage: file_storage/partnerapi
    # First start: read existing files from the beginning (later
    # starts resume where they stopped), skipping files untouched for a
    # day.
    start_at: beginning
    exclude_older_than: 24h
    # The container operator needs the file path to rejoin each file's
    # split lines; it is removed again below.
    include_file_path: true
    include_file_name: false
    # Otherwise a space at the end of a split part is trimmed, and lost
    # from the rejoined line.
    preserve_trailing_whitespaces: true
    operators:
      # Partner API lines are on stdout. Dropping stderr first means a
      # stderr line can never be joined into a split stdout line.
      - type: filter
        expr: 'body matches "^[^ ]+ stderr "'
      # Removes the CRI wrapper and rejoins lines the runtime split at
      # 16 KiB.
      - type: container
        add_metadata_from_filepath: false
      # The path names the pod, namespace and container: keep it out
      # of what is sent to Partner API.
      - type: remove
        field: attributes["log.file.path"]

processors:
  # First, so an ingest outage cannot grow this pipeline without bound.
  memory_limiter/partnerapi:
    check_interval: 1s
    limit_percentage: 80
    spike_limit_percentage: 25
  # 1. Keep only lines that start with the prefix and end with "}". A
  # filter DROPS what its condition matches, so the condition is
  # negated. propagate, not ignore: under ignore an erroring condition
  # would KEEP the line.
  filter/partnerapi:
    error_mode: propagate
    logs:
      log_record:
        - 'not IsMatch(body, "^\\{\"partnerapi_line\":.*\\}\\n?$")'
  # 2. Mark a line that parses as a JSON object. One that does not (a
  # line cut off mid-write and joined to the next) stays unmarked: its
  # error is logged and ignored.
  transform/partnerapi_json:
    error_mode: ignore
    log_statements:
      - 'set(log.attributes["partnerapi.json"], true) where IsMap(ParseJSON(log.body))'
  # 3. Drop every unmarked line, then remove the mark.
  filter/partnerapi_json:
    error_mode: propagate
    logs:
      log_record:
        - 'attributes["partnerapi.json"] != true'
  transform/partnerapi:
    error_mode: propagate
    log_statements:
      - 'delete_key(log.attributes, "partnerapi.json")'
  # At most 100 lines per export (Partner API takes 10,000). The
  # default, 0, sets no maximum at all.
  batch/partnerapi:
    send_batch_size: 100
    send_batch_max_size: 100

exporters:
  otlp_http/partnerapi:
    logs_endpoint: https://ingest.partnerapi.com/v1/logs
    headers:
      x-tenant-token: ${env:PARTNER_API_TENANT_TOKEN}
    # Retry 429, 503 and network errors until they succeed (0: no time
    # limit); other errors are not retried.
    retry_on_failure:
      enabled: true
      max_elapsed_time: 0
    sending_queue:
      enabled: true
      # Two exports in flight at most.
      num_consumers: 2
      # Waiting exports are kept on disk, at most 50; when the queue is
      # full the receiver stops reading until there is room, so nothing
      # is dropped during an outage.
      storage: file_storage/partnerapi
      queue_size: 50
      block_on_overflow: true
      # Splits an export over 4 MiB (Partner API takes 5 MiB) and never
      # merges exports (min_size 0).
      batch:
        sizer: bytes
        min_size: 0
        max_size: 4194304
        flush_timeout: 1s

service:
  extensions: [file_storage/partnerapi]
  pipelines:
    logs/partnerapi:
      receivers: [file_log/partnerapi]
      processors:
        [
          memory_limiter/partnerapi,
          filter/partnerapi,
          transform/partnerapi_json,
          filter/partnerapi_json,
          transform/partnerapi,
          batch/partnerapi,
        ]
      exporters: [otlp_http/partnerapi]
```

The `container` operator rejoins split lines per file, not per stream: a stderr
line written between the parts of a split stdout line would be joined into it,
so the `filter` operator drops stderr first. `include_file_path: true` lets the
`container` operator rejoin each file's parts separately; without it the
operator pools parts from every container on the node together.
`preserve_trailing_whitespaces: true` matters because the receiver otherwise
trims each part's trailing spaces, and a space that falls just before a 16 KiB
boundary inside a string value would be lost from the rejoined line.

### OpenTelemetry Collector on Docker

Mount `/var/lib/docker/containers` read-only. The `container` operator removes
Docker's wrapper but does **not** rejoin a line Docker split at 16 KiB, so a
`recombine` operator does that, after a `filter` operator has dropped stderr.

<!-- tested-config: otelcol/docker-json-file -->

```yaml
# OpenTelemetry Collector (contrib distribution), tested on 0.161.0.
# Older releases call file_log and otlp_http filelog and otlphttp,
# and may not support sending_queue.batch or block_on_overflow.
# Docker hosts using the json-file logging driver (Docker's default).
# Needs PARTNER_API_TENANT_TOKEN in the collector's environment: the
# same token your app's SDK holds.
extensions:
  # Keeps how far each file was read, and the exports waiting to be
  # sent. Mount it from the host, writable by the collector's user.
  file_storage/partnerapi:
    directory: /var/lib/otelcol/partnerapi
    create_directory: true

receivers:
  file_log/partnerapi:
    include: [/var/lib/docker/containers/*/*-json.log]
    storage: file_storage/partnerapi
    # First start: read existing files from the beginning (later
    # starts resume where they stopped), skipping files untouched for a
    # day.
    start_at: beginning
    exclude_older_than: 24h
    include_file_path: true
    include_file_name: false
    operators:
      # Removes Docker's JSON wrapper. It does NOT rejoin a line Docker
      # split at 16 KiB: the recombine operator below does that.
      - type: container
        format: docker
        add_metadata_from_filepath: false
      # Partner API lines are on stdout. Dropping stderr before rejoining
      # means a stderr line can never be joined into a stdout line.
      - type: filter
        expr: 'attributes["log.iostream"] == "stderr"'
      # Every part of a split line but the last lacks the final newline.
      - type: recombine
        combine_field: body
        combine_with: ''
        is_last_entry: body endsWith "\n"
        source_identifier: attributes["log.file.path"]
        max_log_size: 1048576
      - type: remove
        field: attributes["log.file.path"]

processors:
  # First, so an ingest outage cannot grow this pipeline without bound.
  memory_limiter/partnerapi:
    check_interval: 1s
    limit_percentage: 80
    spike_limit_percentage: 25
  # 1. Keep only lines that start with the prefix and end with "}". A
  # filter DROPS what its condition matches, so the condition is
  # negated. propagate, not ignore: under ignore an erroring condition
  # would KEEP the line.
  filter/partnerapi:
    error_mode: propagate
    logs:
      log_record:
        - 'not IsMatch(body, "^\\{\"partnerapi_line\":.*\\}\\n?$")'
  # 2. Mark a line that parses as a JSON object. One that does not (a
  # line cut off mid-write and joined to the next) stays unmarked: its
  # error is logged and ignored.
  transform/partnerapi_json:
    error_mode: ignore
    log_statements:
      - 'set(log.attributes["partnerapi.json"], true) where IsMap(ParseJSON(log.body))'
  # 3. Drop every unmarked line, then remove the mark and the newline
  # Docker keeps.
  filter/partnerapi_json:
    error_mode: propagate
    logs:
      log_record:
        - 'attributes["partnerapi.json"] != true'
  transform/partnerapi:
    error_mode: propagate
    log_statements:
      - 'delete_key(log.attributes, "partnerapi.json")'
      - 'replace_pattern(log.body, "\\n$", "")'
  # At most 100 lines per export (Partner API takes 10,000). The
  # default, 0, sets no maximum at all.
  batch/partnerapi:
    send_batch_size: 100
    send_batch_max_size: 100

exporters:
  otlp_http/partnerapi:
    logs_endpoint: https://ingest.partnerapi.com/v1/logs
    headers:
      x-tenant-token: ${env:PARTNER_API_TENANT_TOKEN}
    # Retry 429, 503 and network errors until they succeed (0: no time
    # limit); other errors are not retried.
    retry_on_failure:
      enabled: true
      max_elapsed_time: 0
    sending_queue:
      enabled: true
      # Two exports in flight at most.
      num_consumers: 2
      # Waiting exports are kept on disk, at most 50; when the queue is
      # full the receiver stops reading until there is room, so nothing
      # is dropped during an outage.
      storage: file_storage/partnerapi
      queue_size: 50
      block_on_overflow: true
      # Splits an export over 4 MiB (Partner API takes 5 MiB) and never
      # merges exports (min_size 0).
      batch:
        sizer: bytes
        min_size: 0
        max_size: 4194304
        flush_timeout: 1s

service:
  extensions: [file_storage/partnerapi]
  pipelines:
    logs/partnerapi:
      receivers: [file_log/partnerapi]
      processors:
        [
          memory_limiter/partnerapi,
          filter/partnerapi,
          transform/partnerapi_json,
          filter/partnerapi_json,
          transform/partnerapi,
          batch/partnerapi,
        ]
      exporters: [otlp_http/partnerapi]
```

### OpenTelemetry Collector reading a file

Point `include` at the file your SDK's sink writes. No operator is needed.

<!-- tested-config: otelcol/file-sink -->

```yaml
# OpenTelemetry Collector (contrib distribution), tested on 0.161.0.
# Older releases call file_log and otlp_http filelog and otlphttp,
# and may not support sending_queue.batch or block_on_overflow.
# Your app's SDK writes to a file (a sink) the collector can read: a
# shared volume, or a file on the same host.
# Needs PARTNER_API_TENANT_TOKEN in the collector's environment: the
# same token your app's SDK holds.
extensions:
  # Keeps how far each file was read, and the exports waiting to be
  # sent. Mount it from the host or node, writable by the collector's user.
  file_storage/partnerapi:
    directory: /var/lib/otelcol/partnerapi
    create_directory: true

receivers:
  file_log/partnerapi:
    # The path your SDK's sink writes to. A file needs no container
    # operator: the lines are exactly as the SDK wrote them.
    include: [/var/log/app/partner-api*.jsonl]
    storage: file_storage/partnerapi
    # First start: read existing files from the beginning (later
    # starts resume where they stopped), skipping files untouched for a
    # day.
    start_at: beginning
    exclude_older_than: 24h
    include_file_name: false

processors:
  # First, so an ingest outage cannot grow this pipeline without bound.
  memory_limiter/partnerapi:
    check_interval: 1s
    limit_percentage: 80
    spike_limit_percentage: 25
  # 1. Keep only lines that start with the prefix and end with "}". A
  # filter DROPS what its condition matches, so the condition is
  # negated. propagate, not ignore: under ignore an erroring condition
  # would KEEP the line.
  filter/partnerapi:
    error_mode: propagate
    logs:
      log_record:
        - 'not IsMatch(body, "^\\{\"partnerapi_line\":.*\\}\\n?$")'
  # 2. Mark a line that parses as a JSON object. One that does not (a
  # line cut off mid-write and joined to the next) stays unmarked: its
  # error is logged and ignored.
  transform/partnerapi_json:
    error_mode: ignore
    log_statements:
      - 'set(log.attributes["partnerapi.json"], true) where IsMap(ParseJSON(log.body))'
  # 3. Drop every unmarked line, then remove the mark.
  filter/partnerapi_json:
    error_mode: propagate
    logs:
      log_record:
        - 'attributes["partnerapi.json"] != true'
  transform/partnerapi:
    error_mode: propagate
    log_statements:
      - 'delete_key(log.attributes, "partnerapi.json")'
  # At most 100 lines per export (Partner API takes 10,000). The
  # default, 0, sets no maximum at all.
  batch/partnerapi:
    send_batch_size: 100
    send_batch_max_size: 100

exporters:
  otlp_http/partnerapi:
    logs_endpoint: https://ingest.partnerapi.com/v1/logs
    headers:
      x-tenant-token: ${env:PARTNER_API_TENANT_TOKEN}
    # Retry 429, 503 and network errors until they succeed (0: no time
    # limit); other errors are not retried.
    retry_on_failure:
      enabled: true
      max_elapsed_time: 0
    sending_queue:
      enabled: true
      # Two exports in flight at most.
      num_consumers: 2
      # Waiting exports are kept on disk, at most 50; when the queue is
      # full the receiver stops reading until there is room, so nothing
      # is dropped during an outage.
      storage: file_storage/partnerapi
      queue_size: 50
      block_on_overflow: true
      # Splits an export over 4 MiB (Partner API takes 5 MiB) and never
      # merges exports (min_size 0).
      batch:
        sizer: bytes
        min_size: 0
        max_size: 4194304
        flush_timeout: 1s

service:
  extensions: [file_storage/partnerapi]
  pipelines:
    logs/partnerapi:
      receivers: [file_log/partnerapi]
      processors:
        [
          memory_limiter/partnerapi,
          filter/partnerapi,
          transform/partnerapi_json,
          filter/partnerapi_json,
          transform/partnerapi,
          batch/partnerapi,
        ]
      exporters: [otlp_http/partnerapi]
```

## Fluent Bit

Fluent Bit's YAML configuration format, tested on 5.1.3. Deploy notes, for all
three:

- Give Fluent Bit `PARTNER_API_TENANT_TOKEN` in its environment.
- Keep `/var/lib/fluent-bit/partnerapi` on the node or host, writable by
  Fluent Bit: it holds the read positions (`db`) and the chunks waiting to be
  sent.
- **The filters, in order:** on Kubernetes and Docker a `grep` keeps stdout
  only; a `grep` keeps a line that starts with the prefix and ends with `}`;
  a `lua` filter drops a line over 1 MiB; the line is copied
  (`partnerapi_body`, which is what is sent, byte for byte) and parsed as
  JSON; a last `grep` keeps only lines whose parse produced a
  `partnerapi_line` field. A line that does not parse (a line cut off
  mid-write and joined to the next one, even when that one is a JSON log
  ending in `}`) has no such field and is dropped.
- `grep`'s `regex` keeps only what matches, and drops a record that lacks the
  key. Do not turn one into an `exclude` rule: `exclude` keeps every record
  it cannot read, so other lines would be forwarded.
- **A rejoined line has no size limit in Fluent Bit,** so the `lua` filter
  drops any line over 1 MiB. Without it, one 3 MB line was sent whole, and a
  line over 5 MiB makes its whole export a `413`, losing the lines beside it.
- `buffer_max_size` must stay above your longest physical line. At the default
  (32 KiB) Fluent Bit stops reading a file at its first longer line; here a
  line over 1 MiB is skipped instead.
- `refresh_interval: 1` makes Fluent Bit look for new files every second
  (the default is 60). A container whose log file appears and is deleted
  between two looks is never read: with the default, a container that lived
  3 seconds sent nothing; with 1 second, every line arrived.
- `compress: gzip`, never `zstd`: Partner API refuses zstd.
- Fluent Bit sends one chunk (about 2 MB) per flush, in exports of at most
  `batch_size` lines.
- **During an outage**, chunks wait on disk and are retried with no limit
  (`retry_limit: no_limits`); `429`, `503` and network errors are retried,
  other errors are not. A 45-second outage with a restart in the middle lost
  nothing. Once `storage.total_limit_size` (512 MB here) is waiting, Fluent
  Bit drops **new** lines and logs
  `[error] [input chunk] fail to drop enough chunks in order to place new data`.
- On first start Fluent Bit reads existing files from the beginning
  (`read_from_head`), skipping files untouched for a day (`ignore_older`).
  Losing its state directory makes it start over the same way.

### Fluent Bit on Kubernetes

Run as a DaemonSet with the node's `/var/log/pods` mounted read-only.

<!-- tested-config: fluent-bit/kubernetes-cri -->

```yaml
# Fluent Bit (YAML configuration), tested on 5.1.3.
# Kubernetes nodes (containerd or CRI-O), run as a DaemonSet.
# Needs PARTNER_API_TENANT_TOKEN in Fluent Bit's environment: the same
# token your app's SDK holds.
service:
  flush: 1
  # Chunks waiting to be sent are kept on disk, and at most 32 chunks
  # (about 64 MB) are held in memory. Mount this directory from the
  # node (hostPath).
  storage.path: /var/lib/fluent-bit/partnerapi/buffer
  storage.max_chunks_up: 32

pipeline:
  inputs:
    - name: tail
      tag: partnerapi
      path: /var/log/pods/*/*/*.log
      # Removes the CRI wrapper and rejoins lines the runtime split at
      # 16 KiB.
      multiline.parser: cri
      # Remembers how far each file was read, across restarts.
      db: /var/lib/fluent-bit/partnerapi/tail.db
      # First start: read existing files from the beginning (later
      # starts resume from db), skipping files untouched for a day.
      read_from_head: true
      ignore_older: 1d
      # The default (32 KiB) stops reading a file at its first longer
      # line. Here a line over 1 MiB is skipped instead.
      buffer_chunk_size: 64K
      buffer_max_size: 1M
      skip_long_lines: on
      storage.type: filesystem
      # Look for new files (a new pod, a rotated log) every second, not
      # every 60: a file that appears and is deleted between two looks
      # is never read.
      refresh_interval: 1

  filters:
    # Partner API lines are on stdout: drop what the container wrote
    # to stderr.
    - name: grep
      match: partnerapi
      regex: 'stream ^stdout$'
    # Keep only lines that start with the prefix and end with "}".
    # grep's regex KEEPS what matches, and drops a record that has no
    # log key.
    - name: grep
      match: partnerapi
      regex: 'log \A\{"partnerapi_line":.*\}\n?\z'
    # A rejoined line can grow without limit; drop one over 1 MiB, so
    # it cannot make its whole export too large (and take the lines
    # beside it with it).
    - name: lua
      match: partnerapi
      call: drop_long
      code: |
        function drop_long(tag, timestamp, record)
          local line = record["log"]
          if type(line) == "string" and #line > 1048576 then
            return -1, timestamp, record
          end
          return 0, timestamp, record
        end
    # Keep the line as written, to send, then parse it as JSON. Only a
    # line that parsed has a partnerapi_line field: one that does not
    # (cut off mid-write and joined to the next line) is dropped.
    - name: modify
      match: partnerapi
      copy: log partnerapi_body
    - name: parser
      match: partnerapi
      key_name: log
      parser: partnerapi_json
      reserve_data: on
    - name: grep
      match: partnerapi
      regex: 'partnerapi_line ^1\.'

  outputs:
    - name: opentelemetry
      match: partnerapi
      host: ingest.partnerapi.com
      port: 443
      tls: on
      tls.verify: on
      logs_uri: /v1/logs
      header: x-tenant-token ${PARTNER_API_TENANT_TOKEN}
      # gzip only: Partner API refuses zstd.
      compress: gzip
      # The line as written is the body; the CRI stream and time are not
      # sent.
      logs_body_key: $partnerapi_body
      # At most 100 lines per export (Partner API takes 10,000).
      batch_size: 100
      # Two exports in flight at most.
      workers: 2
      # Retry 429, 503 and network errors until they succeed; other
      # errors are not retried.
      retry_limit: no_limits
      # At most 512 MB waits on disk while Partner API is unreachable;
      # past that, NEW lines are dropped (and logged at error).
      storage.total_limit_size: 512M

parsers:
  - name: partnerapi_json
    format: json
```

### Fluent Bit on Docker

Mount `/var/lib/docker/containers` read-only. The `docker` multiline parser
removes the wrapper and rejoins split lines. Docker keeps the newline your app
wrote at the end of each line, and Fluent Bit sends it as part of the line;
Partner API ignores it.

<!-- tested-config: fluent-bit/docker-json-file -->

```yaml
# Fluent Bit (YAML configuration), tested on 5.1.3.
# Docker hosts using the json-file logging driver (Docker's default).
# Needs PARTNER_API_TENANT_TOKEN in Fluent Bit's environment: the same
# token your app's SDK holds.
service:
  flush: 1
  # Chunks waiting to be sent are kept on disk, and at most 32 chunks
  # (about 64 MB) are held in memory. Mount this directory from the
  # host.
  storage.path: /var/lib/fluent-bit/partnerapi/buffer
  storage.max_chunks_up: 32

pipeline:
  inputs:
    - name: tail
      tag: partnerapi
      path: /var/lib/docker/containers/*/*-json.log
      # Removes Docker's JSON wrapper and rejoins lines Docker split at
      # 16 KiB.
      multiline.parser: docker
      # Remembers how far each file was read, across restarts.
      db: /var/lib/fluent-bit/partnerapi/tail.db
      # First start: read existing files from the beginning (later
      # starts resume from db), skipping files untouched for a day.
      read_from_head: true
      ignore_older: 1d
      # The default (32 KiB) stops reading a file at its first longer
      # line. Here a line over 1 MiB is skipped instead.
      buffer_chunk_size: 64K
      buffer_max_size: 1M
      skip_long_lines: on
      storage.type: filesystem
      # Look for new files (a new pod, a rotated log) every second, not
      # every 60: a file that appears and is deleted between two looks
      # is never read.
      refresh_interval: 1

  filters:
    # Partner API lines are on stdout: drop what the container wrote
    # to stderr.
    - name: grep
      match: partnerapi
      regex: 'stream ^stdout$'
    # Keep only lines that start with the prefix and end with "}".
    # grep's regex KEEPS what matches, and drops a record that has no
    # log key.
    - name: grep
      match: partnerapi
      regex: 'log \A\{"partnerapi_line":.*\}\n?\z'
    # A rejoined line can grow without limit; drop one over 1 MiB, so
    # it cannot make its whole export too large (and take the lines
    # beside it with it).
    - name: lua
      match: partnerapi
      call: drop_long
      code: |
        function drop_long(tag, timestamp, record)
          local line = record["log"]
          if type(line) == "string" and #line > 1048576 then
            return -1, timestamp, record
          end
          return 0, timestamp, record
        end
    # Keep the line as written, to send, then parse it as JSON. Only a
    # line that parsed has a partnerapi_line field: one that does not
    # (cut off mid-write and joined to the next line) is dropped.
    - name: modify
      match: partnerapi
      copy: log partnerapi_body
    - name: parser
      match: partnerapi
      key_name: log
      parser: partnerapi_json
      reserve_data: on
    - name: grep
      match: partnerapi
      regex: 'partnerapi_line ^1\.'

  outputs:
    - name: opentelemetry
      match: partnerapi
      host: ingest.partnerapi.com
      port: 443
      tls: on
      tls.verify: on
      logs_uri: /v1/logs
      header: x-tenant-token ${PARTNER_API_TENANT_TOKEN}
      # gzip only: Partner API refuses zstd.
      compress: gzip
      # The line as written is the body; Docker's stream and time are not
      # sent.
      logs_body_key: $partnerapi_body
      # At most 100 lines per export (Partner API takes 10,000).
      batch_size: 100
      # Two exports in flight at most.
      workers: 2
      # Retry 429, 503 and network errors until they succeed; other
      # errors are not retried.
      retry_limit: no_limits
      # At most 512 MB waits on disk while Partner API is unreachable;
      # past that, NEW lines are dropped (and logged at error).
      storage.total_limit_size: 512M

parsers:
  - name: partnerapi_json
    format: json
```

### Fluent Bit reading a file

<!-- tested-config: fluent-bit/file-sink -->

```yaml
# Fluent Bit (YAML configuration), tested on 5.1.3.
# Your app's SDK writes to a file (a sink) Fluent Bit can read: a
# shared volume, or a file on the same host.
# Needs PARTNER_API_TENANT_TOKEN in Fluent Bit's environment: the same
# token your app's SDK holds.
service:
  flush: 1
  # Chunks waiting to be sent are kept on disk, and at most 32 chunks
  # (about 64 MB) are held in memory. Mount this directory from the
  # host or node.
  storage.path: /var/lib/fluent-bit/partnerapi/buffer
  storage.max_chunks_up: 32

pipeline:
  inputs:
    - name: tail
      tag: partnerapi
      # The path your SDK's sink writes to. A file needs no multiline
      # parser: the lines are exactly as the SDK wrote them.
      path: /var/log/app/partner-api*.jsonl
      # Remembers how far each file was read, across restarts.
      db: /var/lib/fluent-bit/partnerapi/tail.db
      # First start: read existing files from the beginning (later
      # starts resume from db), skipping files untouched for a day.
      read_from_head: true
      ignore_older: 1d
      # The default (32 KiB) stops reading a file at its first longer
      # line. Here a line over 1 MiB is skipped instead.
      buffer_chunk_size: 64K
      buffer_max_size: 1M
      skip_long_lines: on
      storage.type: filesystem
      # Look for new files (a new pod, a rotated log) every second, not
      # every 60: a file that appears and is deleted between two looks
      # is never read.
      refresh_interval: 1

  filters:
    # Keep only lines that start with the prefix and end with "}".
    # grep's regex KEEPS what matches, and drops a record that has no
    # log key.
    - name: grep
      match: partnerapi
      regex: 'log \A\{"partnerapi_line":.*\}\n?\z'
    # A rejoined line can grow without limit; drop one over 1 MiB, so
    # it cannot make its whole export too large (and take the lines
    # beside it with it).
    - name: lua
      match: partnerapi
      call: drop_long
      code: |
        function drop_long(tag, timestamp, record)
          local line = record["log"]
          if type(line) == "string" and #line > 1048576 then
            return -1, timestamp, record
          end
          return 0, timestamp, record
        end
    # Keep the line as written, to send, then parse it as JSON. Only a
    # line that parsed has a partnerapi_line field: one that does not
    # (cut off mid-write and joined to the next line) is dropped.
    - name: modify
      match: partnerapi
      copy: log partnerapi_body
    - name: parser
      match: partnerapi
      key_name: log
      parser: partnerapi_json
      reserve_data: on
    - name: grep
      match: partnerapi
      regex: 'partnerapi_line ^1\.'

  outputs:
    - name: opentelemetry
      match: partnerapi
      host: ingest.partnerapi.com
      port: 443
      tls: on
      tls.verify: on
      logs_uri: /v1/logs
      header: x-tenant-token ${PARTNER_API_TENANT_TOKEN}
      # gzip only: Partner API refuses zstd.
      compress: gzip
      # The line as written is the body; nothing else is sent.
      logs_body_key: $partnerapi_body
      # At most 100 lines per export (Partner API takes 10,000).
      batch_size: 100
      # Two exports in flight at most.
      workers: 2
      # Retry 429, 503 and network errors until they succeed; other
      # errors are not retried.
      retry_limit: no_limits
      # At most 512 MB waits on disk while Partner API is unreachable;
      # past that, NEW lines are dropped (and logged at error).
      storage.total_limit_size: 512M

parsers:
  - name: partnerapi_json
    format: json
```

## Vector

Tested on 0.58.0. Deploy notes, for all three:

- **The token comes from a secret file.** Vector 0.58 does not expand
  environment variables in its configuration unless started with
  `--dangerously-allow-env-var-interpolation`, so these configurations read
  `tenant_token` from `/etc/partnerapi` with Vector's `directory` secret
  backend. On Kubernetes, mount a Secret with a `tenant_token` key there.
- Keep `data_dir` (`/var/lib/vector/partnerapi`) on the node or host,
  writable by Vector: it holds the read positions and the disk buffer.
  Losing it makes Vector start over: it re-sends what is still in files
  touched in the last day.
- Every `remap` sets `drop_on_error: true`, so an event that fails to parse is
  dropped rather than passed on unchanged, and the `filter` drops an event
  whose condition fails to evaluate. The filter also requires the line to
  parse as JSON.
- The file source's `max_line_bytes` (default 100 KiB) drops a longer line:
  the file configuration raises it to 1 MiB. The rejoining `reduce` transform
  stops at 64 parts (1 MiB); the filter then drops the cut line, so no part of
  a longer line is sent.
- **Vector tells files apart by a checksum of their first line.** Two files
  that start with the same line are read as one. The SDKs' lines start with a
  timestamp, so this only matters for files that begin with something else.
- **Do not rely on Vector's log for refused exports.** When Partner API
  refused an export (`401` or `400`) while Vector was running, Vector 0.58
  logged the first refusal at `ERROR`
  (`Not retriable; dropping the request. reason="Default retry strategy: Unauthorized"`)
  and then `Internal log [...] is being suppressed to avoid flooding` for the
  repeats, at the default log level. An export held in the disk buffer and
  sent only as Vector shut down was refused without a log line in most of
  our runs. Watch Vector's internal metrics instead, from an
  `internal_metrics` source: `component_errors_total` and
  `component_discarded_events_total` (`intentional="false"`) for
  `component_id="partnerapi"`, and `http_client_responses_total` by
  `status`.
- **During an outage**, exports wait in the disk buffer (512 MiB); when it is
  full, reading pauses and the lines stay in your files. Vector retries `429`,
  every `5xx` (`500` included) and network errors, up to 120 times (about an
  hour, with waits of up to 30 seconds), then drops the export. A 45-second
  outage with a restart in the middle lost nothing. With a memory buffer
  instead, the same test lost every line that was waiting when Vector
  restarted, so keep the disk buffer.
- **The disk buffer holds a burst that is followed by silence until the
  next line is written, however long that is** (from 40 seconds to more
  than a minute in our tests). A steady trickle of lines
  arrived within about 10 seconds. So a single test line can appear not to
  arrive: write a few more.
- **The JSON check refuses two kinds of line Partner API accepts:** a string
  holding half of a UTF-16 surrogate pair (JavaScript's `JSON.stringify`
  writes one as `\ud83d`) and nesting deeper than 128 levels. Vector drops
  both, silently.
- On first start Vector reads existing files from the beginning
  (`read_from: beginning`), skipping files untouched for a day
  (`ignore_older_secs`).
- Vector's `kubernetes_logs` source also unwraps and rejoins container logs,
  but needs the Kubernetes API; these configurations read the files directly
  and were tested that way.

### Vector on Kubernetes

Run as a DaemonSet with the node's `/var/log/pods` mounted read-only.

<!-- tested-config: vector/kubernetes-cri -->

```yaml
# Vector, tested on 0.58.0.
# Kubernetes nodes (containerd or CRI-O), run as a DaemonSet.
# Reads the tenant token from a file, tenant_token, in /etc/partnerapi
# (mount a Kubernetes Secret there): the same token your app's SDK holds.
data_dir: /var/lib/vector/partnerapi

secret:
  partnerapi:
    type: directory
    path: /etc/partnerapi
    remove_trailing_whitespace: true

sources:
  partnerapi_pods:
    type: file
    include: [/var/log/pods/*/*/*.log]
    # First start: read existing files from the beginning (later
    # starts resume from data_dir), skipping files untouched for a day.
    read_from: beginning
    ignore_older_secs: 86400
    # Read positions are kept in data_dir, across restarts. Keep it on
    # the node (hostPath).

transforms:
  # Removes the CRI wrapper, and drops stderr: Partner API lines are on
  # stdout. An event that does not parse is dropped, never passed on.
  partnerapi_cri:
    type: remap
    inputs: [partnerapi_pods]
    drop_on_error: true
    drop_on_abort: true
    reroute_dropped: false
    source: |
      parts = parse_regex!(.message, r'^(?P<time>\S+) (?P<stream>stdout|stderr) (?P<tag>[PF]) (?P<log>.*)$')
      if parts.stream != "stdout" { abort }
      . = {"file": .file, "stream": parts.stream, "tag": parts.tag, "log": parts.log}
  # Rejoins lines the runtime split at 16 KiB: P parts, then an F part.
  partnerapi_rejoin:
    type: reduce
    inputs: [partnerapi_cri]
    group_by: [file, stream]
    ends_when: '.tag == "F"'
    merge_strategies:
      log: concat_raw
    # A rejoined line stops at 64 parts (1 MiB); a longer one is cut
    # there, and the filter below drops what is cut.
    max_events: 64
  # Keep only whole Partner API lines: the line must start with the
  # prefix, end with "}" and parse as JSON. A condition that errors
  # drops the event.
  partnerapi_only:
    type: filter
    inputs: [partnerapi_rejoin]
    condition: |
      line = string!(.log)
      starts_with(line, "{\"partnerapi_line\":") && ends_with(line, "}") && is_object(parse_json(line) ?? null)
  # The line becomes the OTLP log record's body; nothing else is sent.
  partnerapi_otlp:
    type: remap
    inputs: [partnerapi_only]
    drop_on_error: true
    drop_on_abort: true
    reroute_dropped: false
    source: |
      line = string!(.log)
      . = {"resourceLogs": [{"scopeLogs": [{"logRecords": [{"body": {"stringValue": line}}]}]}]}

sinks:
  partnerapi:
    type: opentelemetry
    inputs: [partnerapi_otlp]
    protocol:
      type: http
      uri: https://ingest.partnerapi.com/v1/logs
      method: post
      compression: gzip
      encoding:
        codec: otlp
      request:
        headers:
          x-tenant-token: 'SECRET[partnerapi.tenant_token]'
        # Two exports in flight at most.
        concurrency: 2
        # Vector retries 429, 5xx (500 included) and network errors.
        # Give up on an export after 120 attempts, about an hour with
        # waits of up to 30 seconds, so a fault that never clears does
        # not hold a slot forever. Vector may not log a dropped export:
        # watch its internal metrics.
        retry_attempts: 120
        retry_max_duration_secs: 30
      # At most 100 lines and 4 MB per export (Partner API takes
      # 10,000 and 5 MiB).
      batch:
        max_events: 100
        max_bytes: 4000000
        timeout_secs: 1
    # Exports waiting while Partner API is unreachable are kept on
    # disk, up to 512 MiB; past that, reading pauses.
    buffer:
      type: disk
      max_size: 536870912
      when_full: block
    # A file position is saved only once its lines are in the buffer.
    acknowledgements:
      enabled: true
```

### Vector on Docker

Mount `/var/lib/docker/containers` read-only.

<!-- tested-config: vector/docker-json-file -->

```yaml
# Vector, tested on 0.58.0.
# Docker hosts using the json-file logging driver (Docker's default).
# Reads the tenant token from a file, tenant_token, in /etc/partnerapi
# (a file only Vector can read): the same token your app's SDK holds.
data_dir: /var/lib/vector/partnerapi

secret:
  partnerapi:
    type: directory
    path: /etc/partnerapi
    remove_trailing_whitespace: true

sources:
  partnerapi_containers:
    type: file
    include: [/var/lib/docker/containers/*/*-json.log]
    # Docker escapes a 16 KiB part into up to about 100 KiB of JSON.
    max_line_bytes: 262144
    # First start: read existing files from the beginning (later
    # starts resume from data_dir), skipping files untouched for a day.
    read_from: beginning
    ignore_older_secs: 86400
    # Read positions are kept in data_dir, across restarts. Keep it on
    # the host.

transforms:
  # Removes Docker's JSON wrapper, and drops stderr: Partner API lines
  # are on stdout. An event that does not parse is dropped, never
  # passed on.
  partnerapi_docker:
    type: remap
    inputs: [partnerapi_containers]
    drop_on_error: true
    drop_on_abort: true
    reroute_dropped: false
    source: |
      entry = object!(parse_json!(.message))
      if entry.stream != "stdout" { abort }
      . = {"file": .file, "stream": string!(entry.stream), "log": string!(entry.log)}
  # Rejoins lines Docker split at 16 KiB: every part but the last lacks
  # the final newline.
  partnerapi_rejoin:
    type: reduce
    inputs: [partnerapi_docker]
    group_by: [file, stream]
    ends_when: 'ends_with(string!(.log), "\n")'
    merge_strategies:
      log: concat_raw
    # A rejoined line stops at 64 parts (1 MiB); a longer one is cut
    # there, and the filter below drops what is cut.
    max_events: 64
  # Keep only whole Partner API lines: the line must start with the
  # prefix, end with "}" and parse as JSON. A condition that errors
  # drops the event.
  partnerapi_only:
    type: filter
    inputs: [partnerapi_rejoin]
    condition: |
      line = replace(string!(.log), r'\n$', "")
      starts_with(line, "{\"partnerapi_line\":") && ends_with(line, "}") && is_object(parse_json(line) ?? null)
  # The line becomes the OTLP log record's body; nothing else is sent.
  partnerapi_otlp:
    type: remap
    inputs: [partnerapi_only]
    drop_on_error: true
    drop_on_abort: true
    reroute_dropped: false
    source: |
      line = replace(string!(.log), r'\n$', "")
      . = {"resourceLogs": [{"scopeLogs": [{"logRecords": [{"body": {"stringValue": line}}]}]}]}

sinks:
  partnerapi:
    type: opentelemetry
    inputs: [partnerapi_otlp]
    protocol:
      type: http
      uri: https://ingest.partnerapi.com/v1/logs
      method: post
      compression: gzip
      encoding:
        codec: otlp
      request:
        headers:
          x-tenant-token: 'SECRET[partnerapi.tenant_token]'
        # Two exports in flight at most.
        concurrency: 2
        # Vector retries 429, 5xx (500 included) and network errors.
        # Give up on an export after 120 attempts, about an hour with
        # waits of up to 30 seconds, so a fault that never clears does
        # not hold a slot forever. Vector may not log a dropped export:
        # watch its internal metrics.
        retry_attempts: 120
        retry_max_duration_secs: 30
      # At most 100 lines and 4 MB per export (Partner API takes
      # 10,000 and 5 MiB).
      batch:
        max_events: 100
        max_bytes: 4000000
        timeout_secs: 1
    # Exports waiting while Partner API is unreachable are kept on
    # disk, up to 512 MiB; past that, reading pauses.
    buffer:
      type: disk
      max_size: 536870912
      when_full: block
    # A file position is saved only once its lines are in the buffer.
    acknowledgements:
      enabled: true
```

### Vector reading a file

<!-- tested-config: vector/file-sink -->

```yaml
# Vector, tested on 0.58.0.
# Your app's SDK writes to a file (a sink) Vector can read: a shared
# volume, or a file on the same host.
# Reads the tenant token from a file, tenant_token, in /etc/partnerapi
# (a file only Vector can read): the same token your app's SDK holds.
data_dir: /var/lib/vector/partnerapi

secret:
  partnerapi:
    type: directory
    path: /etc/partnerapi
    remove_trailing_whitespace: true

sources:
  partnerapi_file:
    type: file
    # The path your SDK's sink writes to. A file needs no unwrapping:
    # the lines are exactly as the SDK wrote them.
    include: [/var/log/app/partner-api*.jsonl]
    # The default (100 KiB) drops a longer line. Lines carry request
    # and response bodies.
    max_line_bytes: 1048576
    # First start: read existing files from the beginning (later
    # starts resume from data_dir), skipping files untouched for a day.
    read_from: beginning
    ignore_older_secs: 86400
    # Read positions are kept in data_dir, across restarts. Keep it on
    # the host or node.

transforms:
  # Keep only whole Partner API lines: the line must start with the
  # prefix, end with "}" and parse as JSON. A condition that errors
  # drops the event.
  partnerapi_only:
    type: filter
    inputs: [partnerapi_file]
    condition: |
      line = string!(.message)
      starts_with(line, "{\"partnerapi_line\":") && ends_with(line, "}") && is_object(parse_json(line) ?? null)
  # The line becomes the OTLP log record's body; nothing else is sent.
  partnerapi_otlp:
    type: remap
    inputs: [partnerapi_only]
    drop_on_error: true
    drop_on_abort: true
    reroute_dropped: false
    source: |
      line = string!(.message)
      . = {"resourceLogs": [{"scopeLogs": [{"logRecords": [{"body": {"stringValue": line}}]}]}]}

sinks:
  partnerapi:
    type: opentelemetry
    inputs: [partnerapi_otlp]
    protocol:
      type: http
      uri: https://ingest.partnerapi.com/v1/logs
      method: post
      compression: gzip
      encoding:
        codec: otlp
      request:
        headers:
          x-tenant-token: 'SECRET[partnerapi.tenant_token]'
        # Two exports in flight at most.
        concurrency: 2
        # Vector retries 429, 5xx (500 included) and network errors.
        # Give up on an export after 120 attempts, about an hour with
        # waits of up to 30 seconds, so a fault that never clears does
        # not hold a slot forever. Vector may not log a dropped export:
        # watch its internal metrics.
        retry_attempts: 120
        retry_max_duration_secs: 30
      # At most 100 lines and 4 MB per export (Partner API takes
      # 10,000 and 5 MiB).
      batch:
        max_events: 100
        max_bytes: 4000000
        timeout_secs: 1
    # Exports waiting while Partner API is unreachable are kept on
    # disk, up to 512 MiB; past that, reading pauses.
    buffer:
      type: disk
      max_size: 536870912
      when_full: block
    # A file position is saved only once its lines are in the buffer.
    acknowledgements:
      enabled: true
```

## What to know

- **Bodies pass through your pipeline first.** On push, request and response
  bodies go straight to Partner API, which redacts personal data before
  storing anything. In pipeline mode the whole line (bodies, headers, data)
  passes through your collector, and into your own log storage if your other
  pipelines keep these lines, exactly as your app logged it. Partner API still
  redacts it on arrival; what your pipeline stores on the way is yours to
  protect. The SDKs replace the values of `authorization`, `cookie`,
  `set-cookie`, `x-api-key`, `x-tenant-token` and `proxy-authorization`
  headers, and of any header equal to the partner's app key, with
  `[REDACTED]`; nothing else is removed.
- **What is not sent.** With these configurations only whole lines that start
  with `{"partnerapi_line":` leave your pipeline, as the body of an OTLP log
  record. The OpenTelemetry Collector adds the attribute `log.iostream` to
  lines from containers, and `logtag` on Kubernetes; from a file it adds
  nothing, and neither do Fluent Bit and Vector. No file path, pod,
  namespace, container or host name is sent, and nothing your containers
  write to stderr. Partner API reads only the attributes it documents and
  ignores the rest, but every byte counts toward the 5 MiB export limit, so
  do not add attributes to this pipeline.
- **Keep the line as the body.** Partner API reads the line from the record's
  body. Do not parse it into attributes in your collector: fields that are not
  on Partner API's list of attributes are ignored, so they would be lost. If
  you must, carry other fields as `partnerapi.data.<name>` attributes.
- **The same token in the app and the collector.** The reference is keyed on
  the tenant token, so a line written under a different token (a staging app
  sending through a production collector, say) matches no partner.
- **Rolling the tenant token.** The old token stops working at once, for the
  app and the collector alike. Every export the collector sends with it is
  refused with `401`, and none of the three collectors retries a `401`: in
  our tests all three dropped the lines they sent before their token was
  updated, and carried on once it was (Vector logged at most the first refusal; see
  [Vector](#vector)). Lines your app wrote under the old token, including any
  still waiting in the collector, match no partner once the token has
  changed: they are dropped and reported as `unmatched`. So roll in a quiet
  period, and redeploy the app and the collector with the new token in the
  same change.
- **Delivery is at least once.** A collector retries an export after a `503`,
  a `429` or a dropped connection, and if Partner API had already stored it,
  those lines are stored twice. A restart can also resend lines: after a
  restart in the middle of an outage, the OpenTelemetry Collector re-sent up
  to its queue's 50 exports of 100 lines (5,000 lines in our test); Fluent
  Bit and Vector re-sent none in the same test. Expect duplicates.
- **Many collectors, routine `503`s.** Partner API shares its capacity per
  tenant per ingest worker, so with a collector on every node (a DaemonSet)
  some exports are answered `503` (busy) even though each collector keeps to
  two in flight. They are retried; nothing is lost.
- **Slow uploads.** Partner API answers `503` (with `Connection: close`) to a
  protobuf export whose body takes more than 30 seconds to arrive, about
  1.4 Mbit/s for a full 5 MiB export. The export is retried; if it keeps
  happening, lower the batch size.
- **Lines Partner API drops are reported to your collector**, not to your app:
  the export is answered `200` with a count of dropped lines and the reasons
  (see [Troubleshooting](#troubleshooting)). The OpenTelemetry Collector and
  Fluent Bit log that message and do not retry; Vector does not log it. The
  SDK cannot see past its own output.
- **A line cut off mid-write is dropped.** If your process dies while writing
  a line, the line has no ending. Docker, and a file, then join it to the next
  line written (often your restarted app's first line, which may hold a
  connection string). Every configuration keeps a line only when it ends with
  `}` **and** parses as a JSON object, so neither the cut line nor the line
  joined to it is sent, even when that line is a JSON log of its own; the cut
  line is lost.
- **The JSON check is strict in places Partner API is not.** A string holding
  half of a UTF-16 surrogate pair (JavaScript's `JSON.stringify` writes one
  as `\ud83d`) and deeply nested data are both accepted by Partner API. In
  our tests the OpenTelemetry Collector forwarded both. Fluent Bit forwarded
  the half surrogate and dropped a line nested more than about 60 levels
  deep. Vector dropped the half surrogate and a line nested more than about
  125 levels deep. A tool that drops such a line does so silently.
- **Line endings.** A line ending in `\r\n` (a Windows container) ends in
  `\r`, not `}`, and is dropped.
- **Long lines.** A runtime splits a line over 16 KiB; these configurations
  rejoin lines up to 1 MiB and drop a longer one: the OpenTelemetry Collector
  cuts it at 1 MiB (`max_log_size`) and the filter drops the pieces; Fluent
  Bit drops it with its `lua` filter (or skips it, reading a file); Vector
  stops rejoining at 64 parts, or drops it reading a file. A line a collector
  does send whole but that is over 5 MiB makes its whole export a `413`, and
  the lines in the same export are lost with it. Partner API stores at most
  about 250 KB of one line: a longer line is stored without its bodies, as on
  push.
- **Clocks.** A line carries the time your app wrote it. Partner API drops a
  line more than about 7 days old, or more than about 9 minutes in the future,
  so keep your hosts' clocks in sync and do not replay a backlog older than a
  week.

## Logging webhooks

**Incoming webhooks**, a partner calling a webhook endpoint of yours, are
ordinary inbound traffic: log them with the same request and response calls as
any other partner request.

What differs is how you know the partner. A webhook is usually authenticated
by a signature rather than by the partner's app key. Once your handler has
verified the signature and knows which partner sent it, pass that partner's
app key to the log calls (without the SDK, write that partner's reference on
the line). Do not leave a webhook out of your logs because it carries no key,
and do not log it under another partner's key: a partner sees only the
lines attributed to it.

Keep the secret out of the line. Never log the signing secret, and replace the
signature header's value yourself before you log the headers: the SDKs redact
only `authorization`, `cookie`, `set-cookie`, `x-api-key`, `x-tenant-token`,
`proxy-authorization` and, in stdout mode, a header whose value equals the app key, so a header
such as `x-signature` or `stripe-signature` is logged as given.

**Outgoing webhooks**, your code delivering an event to a partner's endpoint:
Partner API does not yet show webhook deliveries to partners. An outbound line
is a call to a service you use and is never shown to a partner.

## Troubleshooting

### What each collector shows when Partner API drops lines

Partner API answers `200` and tells the collector how many lines it dropped,
by reason, in OTLP's partial success. The message never contains your data:

```text
3 log records rejected: 1 unmatched (partner reference resolves to no approved app); 2 invalid: unparseable_line
```

- **OpenTelemetry Collector** logs a warning:
  `Partial success response … "message": "1 log record rejected: …", "dropped_log_records": 1`.
- **Fluent Bit** logs `HTTP status=200` at `info`, followed by the response
  body; the message text is readable in it among a few binary bytes.
- **Vector** logs nothing about it.

### Why a line was dropped

| Reason                     | What it means                                                                                    | Usual cause and fix                                                                                                                                                                                                                                                                                                    |
| -------------------------- | ------------------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `unmatched`                | The line's partner reference matches no approved app in your tenant.                             | The app is not approved yet, or was rejected; the app key in your code is wrong; the app and the collector use different tenant tokens; the token was rolled and lines written under the old one were still in your app or collector. Without the SDK: check your reference against the [test vectors](#test-vectors). |
| `no_marker`                | The record holds no Partner API line at all.                                                     | Your filter let another line through. Check that the filter runs and matches the prefix at the start of the line.                                                                                                                                                                                                      |
| `unparseable_line`         | The line starts with the prefix (or mentions `partnerapi_line`) but is not valid JSON.           | The container wrapper was not removed, a split line was not rejoined, or a collector line-length limit cut the line. Use the configuration for your runtime, unchanged.                                                                                                                                                |
| `unsupported_marker`       | `partnerapi_line` is not a version string such as `"1.6.0"`.                                     | Without the SDK: write the marker exactly as shown in [The line](#the-line).                                                                                                                                                                                                                                           |
| `missing_partner_ref`      | `partnerapi_partner_ref` is missing or empty.                                                    | Without the SDK: every line needs the reference of the partner's app key.                                                                                                                                                                                                                                              |
| `bad_level`                | `partnerapi_level` is missing, empty or not a string.                                            | Without the SDK: write one of `debug`, `info`, `warn`, `error`.                                                                                                                                                                                                                                                        |
| `bad_timestamp`            | `partnerapi_timestamp` is missing or not a time.                                                 | Without the SDK: write the Unix time in nanoseconds as a string of digits.                                                                                                                                                                                                                                             |
| `timestamp_out_of_range`   | The line is more than about 7 days old, or more than about 9 minutes in the future.              | A backlog replayed after more than a week, or a host clock running fast. Sync the clock (NTP).                                                                                                                                                                                                                         |
| `bad_direction`            | `partnerapi_direction` is not `inbound` or `outbound`.                                           | Without the SDK: omit it, or write one of the two.                                                                                                                                                                                                                                                                     |
| `bad_upstream_attribution` | `partnerapi_upstream_integration` or `partnerapi_upstream_base_url` is empty or over 1024 bytes. | Omit the field when you have no value; shorten it.                                                                                                                                                                                                                                                                     |

### What each status means

| Status | When                                                                                                                               | What the collectors do                                                                                          | What to do                                                                 |
| ------ | ---------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------- |
| `200`  | Stored; any dropped lines are counted in the response (above).                                                                     | Log the partial success (not Vector), do not retry.                                                             | Nothing, or fix the reason above.                                          |
| `400`  | The export could not be decoded, or is over 10,000 lines or Partner API's size budget.                                             | Drop the export.                                                                                                | Use the configurations as printed; they keep exports small.                |
| `401`  | The `x-tenant-token` header is missing or not a valid tenant token.                                                                | Drop the export.                                                                                                | Check the variable or secret the collector reads; after a roll, update it. |
| `413`  | The export is over 5 MiB after decompression.                                                                                      | Drop the export.                                                                                                | Lower the batch size or the byte cap; check for very long lines.           |
| `415`  | The content type is not OTLP protobuf or JSON, or the compression is not gzip.                                                     | Drop the export.                                                                                                | Send `application/x-protobuf` (the default) and gzip; never zstd.          |
| `429`  | Your tenant is over its ingestion rate. Sent with `Retry-After: 10`.                                                               | Wait and retry.                                                                                                 | Nothing if it passes; contact support if it persists.                      |
| `503`  | Partner API is briefly unavailable or busy (`Retry-After: 5`), or an export's body took over 30 s to arrive (`Connection: close`). | Wait and retry.                                                                                                 | Nothing if it passes; keep two exports in flight at most.                  |
| `500`  | A fault on Partner API's side.                                                                                                     | The OpenTelemetry Collector and Fluent Bit drop the export; Vector retries it, up to 120 times (about an hour). | Contact support with the time.                                             |

When an export is dropped, the OpenTelemetry Collector logs
`Exporting failed. Dropping data.` at `error`, with
`not retryable error: Permanent error:` and the status; Fluent Bit logs
`HTTP status=401` (and so on) at `error`, with Partner API's message on the
next line; Vector logs at most the first refusal at `error` and suppresses
the rest (see its notes under [Vector](#vector) for the metrics to watch).

### Nothing arrives

1. **Is the collector reading the files?** The OpenTelemetry Collector logs
   `Started watching file` and Vector `Found new file to watch`; Fluent Bit,
   polling as configured here, logs no line per file at `info`. If no file is
   read, check the mount and the `include` / `path` pattern.
2. **Are the lines your app writes Partner API lines, on stdout?** Look at the
   file, or `kubectl logs`: each line must start with `{"partnerapi_line":`
   and end with `}`. If they do not, the SDK is not in stdout mode, or (PHP)
   the sink is not the file you are reading. Lines on stderr are dropped.
3. **Did an export fail?** Search the collector's log for the messages in
   [What each status means](#what-each-status-means); for Vector, check its
   metrics.
4. **Were the lines dropped?** See
   [What each collector shows](#what-each-collector-shows-when-partner-api-drops-lines);
   with Vector, check the token and the app's approval first.

### Lines over 16 KiB are missing or broken

The runtime split them and they were not rejoined, or a length limit cut
them. Use the configuration for your runtime unchanged: Docker needs the
`recombine` operator (OpenTelemetry Collector), the `docker` multiline parser
(Fluent Bit) or the `reduce` transform (Vector), and the file configurations
raise each tool's line limit to 1 MiB. Lines over 1 MiB are dropped (see
[What to know](#what-to-know)).

### Clock skew

`timestamp_out_of_range` on lines that are not old means the host that wrote
them runs ahead of real time by more than about 9 minutes. Sync its clock.

## Limits

| Limit             | Value                                                                                                                     |
| ----------------- | ------------------------------------------------------------------------------------------------------------------------- |
| Endpoint          | `POST https://ingest.partnerapi.com/v1/logs`, OTLP/HTTP                                                                   |
| Authentication    | `x-tenant-token: <your tenant token>`; no other credential                                                                |
| Encodings         | `application/x-protobuf` or `application/json`; JSON is about 20% larger for the same lines                               |
| Compression       | `gzip` or none; anything else is refused with `415`                                                                       |
| Export size       | 5 MiB after decompression (`413`)                                                                                         |
| Lines per export  | 10,000 (`400`)                                                                                                            |
| Structured bodies | very deeply nested or very large structured bodies can be refused (`400`); keep the line as a string body, as the SDKs do |
| Exports in flight | keep it to two per collector; more can be answered `503` and retried                                                      |
| Line age          | dropped when more than about 7 days old or about 9 minutes in the future                                                  |
| Line length       | these configurations drop a line over 1 MiB; Partner API stores about 250 KB of a line, and a longer one without bodies   |

## Without the SDK

You can write the lines from any logger. Your collector configuration stays the
same. Each line is one JSON object on one line, followed by a newline, written
to standard output (or to a file the collector reads).

### The line

The line is an **envelope** followed by your **fields**:

1. It starts with exactly the bytes `{"partnerapi_line":"1.6.0",` — no leading
   space, no byte-order mark. That is what collectors filter on.
2. The envelope keys come next, in this order, before any of your fields:

   | Key                               | Value                                                                                                 |
   | --------------------------------- | ----------------------------------------------------------------------------------------------------- |
   | `partnerapi_line`                 | `"1.6.0"`, the line format's version. Required.                                                       |
   | `partnerapi_partner_ref`          | the partner reference of the app key (below). Required.                                               |
   | `partnerapi_timestamp`            | the Unix time in nanoseconds, as a string of 19 digits. Required.                                     |
   | `partnerapi_level`                | `debug`, `info`, `warn` or `error`: the level Partner API labels the line with. Required.             |
   | `partnerapi_direction`            | `inbound` (a request to your API, the default) or `outbound` (a call your app made). Omit when unset. |
   | `partnerapi_upstream_integration` | for an outbound line, the name of the service called, such as `stripe`. Omit when unset.              |
   | `partnerapi_upstream_base_url`    | for an outbound line, its base URL, such as `https://api.stripe.com`. Omit when unset.                |

3. Then your fields: `level` and `message`, and anything else you log. A
   field of yours must not start with `partnerapi_`.
4. It ends with `}` and then the newline: the collector configurations drop a
   line that does not, so a line cut off mid-write is never sent.

Partner API rebuilds a partner's requests from a pair of lines, one for the
request and one for the response, joined by `correlation_id`:

```json
{"partnerapi_line":"1.6.0","partnerapi_partner_ref":"v1:716768d2853ca9bc0568918e2d3b99e80f0929fb4ccd6c0ca6a2d7558077c676","partnerapi_timestamp":"1700000000000000000","partnerapi_level":"info","level":"info","message":"Incoming request","request_id":"req-456","path":"/v1/bookings?expand=guest","method":"POST","correlation_id":"corr-123","headers":{"content-type":"application/json","authorization":"[REDACTED]"},"body":{"guestName":"Ana","nights":2}}
{"partnerapi_line":"1.6.0","partnerapi_partner_ref":"v1:716768d2853ca9bc0568918e2d3b99e80f0929fb4ccd6c0ca6a2d7558077c676","partnerapi_timestamp":"1700000000042000000","partnerapi_level":"info","level":"info","message":"Outgoing response","request_id":"req-456","path":"/v1/bookings?expand=guest","method":"POST","status_code":201,"duration_ms":42,"correlation_id":"corr-123","headers":{"content-type":"application/json"},"body":{"id":"bk_1","status":"confirmed"}}
```

The request line's `message` is exactly `Incoming request`, the response
line's exactly `Outgoing response`, and both carry the same `correlation_id`.
`method`, `path`, `status_code`, `duration_ms` (milliseconds), `request_id`,
`headers` and `body` are read by those names.

**Keep secrets off the line:**

- Never write the partner's app key, its SHA-256, or your tenant token.
- Replace the values of the `authorization`, `cookie`, `set-cookie`,
  `x-api-key`, `x-tenant-token` and `proxy-authorization` headers (any case)
  with `"[REDACTED]"`, and the value of any header that equals the partner's
  app key, whatever the header is called.
- Bodies and other fields are sent as you write them; redact what your own
  pipeline must not store.

**One line per write.** If several processes share one output, a write longer
than 4096 bytes to a pipe can interleave with another process's; give each
process its own file, or a logger that serialises writes.

### The partner reference

```text
message   = lowercase hex( SHA-256( UTF-8 bytes of the app key ) )        64 characters
reference = "v1:" + lowercase hex( HMAC-SHA256( key     = UTF-8 bytes of the tenant token,
                                                message = the 64 characters of message ) )
```

- Use the app key and the tenant token **exactly as given**: no trimming, no
  change of case, no Unicode normalisation.
- The HMAC message is the **hex text** of the digest, not its 32 raw bytes.
- The result is always 67 characters: `v1:` and 64 lowercase hex digits.
- You may cache it per (token, app key).

Worked example, with stock tools and the first test vector. These are test
values, so they can go on the command line:

```sh
printf %s 'test-api-key' | shasum -a 256
# 4c806362b613f7496abf284146efd31da90e4b16169fe001841ca17290f427c4
printf %s '4c806362b613f7496abf284146efd31da90e4b16169fe001841ca17290f427c4' \
  | openssl dgst -sha256 -hmac 'tenant_live_test123'
# 716768d2853ca9bc0568918e2d3b99e80f0929fb4ccd6c0ca6a2d7558077c676
# reference: v1:716768d2853ca9bc0568918e2d3b99e80f0929fb4ccd6c0ca6a2d7558077c676
```

**Never type your real tenant token or an app key on a command line:** it is
kept in your shell history and other users of the machine can read it in the
process list. To check a real reference, read both from the environment:

```sh
python3 -c 'import hashlib, hmac, os
m = hashlib.sha256(os.environ["APP_KEY"].encode()).hexdigest()
print("v1:" + hmac.new(os.environ["PARTNER_API_TENANT_TOKEN"].encode(), m.encode(), hashlib.sha256).hexdigest())'
```

### Test vectors

Your implementation must produce `reference` from `tenant_token` and
`app_key` for every entry in `derivation` (`message` is the intermediate
digest, for debugging). `common_mistakes` are references computed the wrong
way for the key `second-partner-key` and the token `tenant_live_test123`: if
you get one of them, the description says what went wrong.

<!-- test-vectors -->

```json
{
  "derivation": [
    {
      "description": "ASCII key, the token the push fixtures use",
      "tenant_token": "tenant_live_test123",
      "app_key": "test-api-key",
      "message": "4c806362b613f7496abf284146efd31da90e4b16169fe001841ca17290f427c4",
      "reference": "v1:716768d2853ca9bc0568918e2d3b99e80f0929fb4ccd6c0ca6a2d7558077c676"
    },
    {
      "description": "same key, another tenant token",
      "tenant_token": "tenant_live_test456",
      "app_key": "test-api-key",
      "message": "4c806362b613f7496abf284146efd31da90e4b16169fe001841ca17290f427c4",
      "reference": "v1:1682cb9ec15cf22b2b216dd76b4ecc3cb90c80dbc72dc0b459ffe1a18975ed32"
    },
    {
      "description": "second ASCII key",
      "tenant_token": "tenant_live_test123",
      "app_key": "second-partner-key",
      "message": "791aca2d0d1533d8ac2b61595b3b9ff4cf13e9787474413bd4dc325b1dcaf352",
      "reference": "v1:616f2ec28c141eb71b291c31d63fa28c8852bf9f0a4b07888b80985cca280765"
    },
    {
      "description": "a minted-shape token (44 bytes)",
      "tenant_token": "tenant_live_00000000000000000000000000000001",
      "app_key": "test-api-key",
      "message": "4c806362b613f7496abf284146efd31da90e4b16169fe001841ca17290f427c4",
      "reference": "v1:b946a04f95968879c1143f8b12b05940068dbb6b7a829bc36006d65824b51ae0"
    },
    {
      "description": "a 64-byte token: one block, used as the HMAC key as-is",
      "tenant_token": "tenant_live_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
      "app_key": "test-api-key",
      "message": "4c806362b613f7496abf284146efd31da90e4b16169fe001841ca17290f427c4",
      "reference": "v1:f578b3225554fa559978d3564575c4a40bd96c050869d4c6434d8a961a24c516"
    },
    {
      "description": "a 72-byte token: longer than a block, hashed first per RFC 2104",
      "tenant_token": "tenant_live_cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc",
      "app_key": "test-api-key",
      "message": "4c806362b613f7496abf284146efd31da90e4b16169fe001841ca17290f427c4",
      "reference": "v1:ca2b3fd4d6bffb8fc984c2e642cdc17c2129b732764e094175ef88511dd52eae"
    },
    {
      "description": "non-ASCII key, NFC",
      "tenant_token": "tenant_live_test123",
      "app_key": "cl\u00e9-partenaire-\u00fc",
      "message": "db87d289d7b9af4907bb9050957a0fcfe6b387d3e4d23fc7efb78fdba951c6e9",
      "reference": "v1:838cfdd3c2c6a3d519c04dca8024d089ef2ede9503346420e81bb73c963d7b19"
    },
    {
      "description": "the same text in NFD: different bytes, different reference (no Unicode normalisation)",
      "tenant_token": "tenant_live_test123",
      "app_key": "cle\u0301-partenaire-u\u0308",
      "message": "334d18572861edf875902b1a2a06e819ad1fdd2d5c6d6868124b31112763390f",
      "reference": "v1:6f8e1fce8e8788354d47396f8054766454b73a65bb11c8efe15943aeaea28870"
    },
    {
      "description": "a key with a four-byte UTF-8 character",
      "tenant_token": "tenant_live_test123",
      "app_key": "partner-\ud83d\udd11-key",
      "message": "7394d22c5aea6c85a128d29baf6f28a382bf371e5c29ef8955b301234cd7cbb0",
      "reference": "v1:c1f8a2266cbaa0ae23e339cafc8651ba787fb5fb99835bf8dd9b6f5a7e7a4d29"
    },
    {
      "description": "a CJK key",
      "tenant_token": "tenant_live_test123",
      "app_key": "\u30d1\u30fc\u30c8\u30ca\u30fc\u306e\u9375",
      "message": "495de18172cb264ad4c183dbaa9f9e4db6ab889dc6181f36d0d5f80f86db1cae",
      "reference": "v1:b66b82953e4f2f6d1f674a81306534ec46b5262fbffbbd077e8e73e5530b2b77"
    },
    {
      "description": "surrounding spaces are part of the key (no trimming)",
      "tenant_token": "tenant_live_test123",
      "app_key": " test-api-key ",
      "message": "87e07c45f34275b64c3ad1ee5fa85e7cf4c5888816466a22775bc13e3ff52b28",
      "reference": "v1:3cbc64c783d592ec35248eda431393a3aa1e7c9ef047a20faf4ca043bff6b10b"
    },
    {
      "description": "a 256-character key",
      "tenant_token": "tenant_live_test123",
      "app_key": "kkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkk",
      "message": "ce16fe78208a4e93f7158e62393680e025b2ad584f4e875ce903a654bb74cf93",
      "reference": "v1:c2a2eaaa865831e01416c314ba6e33d8be8fe0ed7187e4fc79b9dd490e12d2bd"
    }
  ],
  "common_mistakes": [
    {
      "description": "the plain SHA-256 of the key is not a reference",
      "reference": "v1:791aca2d0d1533d8ac2b61595b3b9ff4cf13e9787474413bd4dc325b1dcaf352"
    },
    {
      "description": "HMAC over the raw key instead of its hex digest",
      "reference": "v1:689b15bc5a5a6c6b9667f59d86fca24048c336110bd4749cf8db7c4ccae763ad"
    },
    {
      "description": "HMAC over the 32-byte binary digest instead of its hex",
      "reference": "v1:288ab94484c65f9e1014f7340dc3b7f9e1f252341696edf56c095c48438d00b7"
    },
    {
      "description": "HMAC over the upper-case hex digest",
      "reference": "v1:a8b2803c2a9f761cff0dfe6db5d1ff4bf7602e177f7323a4467e9a49fdd10f49"
    },
    {
      "description": "key and message swapped",
      "reference": "v1:85281a67fc3da5385b490cc4be98651d383b4109910bef5affbf0fd1f56655ef"
    }
  ]
}
```

### Minimal emitters

Each of these computes the reference and writes one line; it does not log
headers or bodies for you. Run them against the test vectors before you
rely on them.

**Python** (3.7 or later):

<!-- emitter: python -->

```python
import hashlib
import hmac
import json
import time

MARKER = "1.6.0"


def partner_reference(tenant_token: str, app_key: str) -> str:
    message = hashlib.sha256(app_key.encode("utf-8")).hexdigest()
    mac = hmac.new(tenant_token.encode("utf-8"), message.encode("ascii"), hashlib.sha256)
    return "v1:" + mac.hexdigest()


def partnerapi_line(tenant_token: str, app_key: str, level: str, message: str, **data) -> str:
    """One pipeline line, without the trailing newline."""
    envelope = {
        "partnerapi_line": MARKER,
        "partnerapi_partner_ref": partner_reference(tenant_token, app_key),
        "partnerapi_timestamp": str(time.time_ns()),
        "partnerapi_level": level,
    }
    line = {"level": level, "message": message}
    line.update({k: v for k, v in data.items() if not k.startswith("partnerapi_")})
    # Envelope first, then the line: serialise both and join them.
    head = json.dumps(envelope, separators=(",", ":"), allow_nan=False)
    tail = json.dumps(line, separators=(",", ":"), allow_nan=False)
    return head[:-1] + "," + tail[1:]
```

```python
print(partnerapi_line(token, app_key, "info", "Charge retried", charge="ch_1"), flush=True)
```

**Go**:

<!-- emitter: go -->

```go
package partnerapi

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"strconv"
	"strings"
	"time"
)

const Marker = "1.6.0"

func PartnerReference(tenantToken, appKey string) string {
	digest := sha256.Sum256([]byte(appKey))
	mac := hmac.New(sha256.New, []byte(tenantToken))
	mac.Write([]byte(hex.EncodeToString(digest[:])))
	return "v1:" + hex.EncodeToString(mac.Sum(nil))
}

// Line returns one pipeline line, without the trailing newline.
func Line(tenantToken, appKey, level, message string, data map[string]any) (string, error) {
	envelope, err := json.Marshal(struct {
		Line      string `json:"partnerapi_line"`
		Reference string `json:"partnerapi_partner_ref"`
		Timestamp string `json:"partnerapi_timestamp"`
		Level     string `json:"partnerapi_level"`
	}{Marker, PartnerReference(tenantToken, appKey), strconv.FormatInt(time.Now().UnixNano(), 10), level})
	if err != nil {
		return "", err
	}
	line := map[string]any{"level": level, "message": message}
	for k, v := range data {
		if !strings.HasPrefix(k, "partnerapi_") {
			line[k] = v
		}
	}
	tail, err := json.Marshal(line)
	if err != nil {
		return "", err
	}
	// Envelope first, then the line: serialise both and join them.
	return string(envelope[:len(envelope)-1]) + "," + string(tail[1:]), nil
}
```

**Ruby**:

<!-- emitter: ruby -->

```ruby
require "json"
require "openssl"

PARTNERAPI_MARKER = "1.6.0"

def partner_reference(tenant_token, app_key)
  message = OpenSSL::Digest::SHA256.hexdigest(app_key.encode("UTF-8"))
  "v1:" + OpenSSL::HMAC.hexdigest("SHA256", tenant_token.encode("UTF-8"), message)
end

# One pipeline line, without the trailing newline.
def partnerapi_line(tenant_token, app_key, level, message, data = {})
  envelope = {
    "partnerapi_line" => PARTNERAPI_MARKER,
    "partnerapi_partner_ref" => partner_reference(tenant_token, app_key),
    "partnerapi_timestamp" => Process.clock_gettime(Process::CLOCK_REALTIME, :nanosecond).to_s,
    "partnerapi_level" => level,
  }
  line = { "level" => level, "message" => message }
  data.each { |k, v| line[k.to_s] = v unless k.to_s.start_with?("partnerapi_") }
  # Envelope first, then the line: serialise both and join them.
  JSON.generate(envelope)[0...-1] + "," + JSON.generate(line)[1..-1]
end
```

### Sending OTLP yourself

Prefer a collector. If you send OTLP from your own code, put each line in a log
record's body as a string, exactly as above, and send to the endpoint with the
headers in [Limits](#limits). In OTLP/JSON, an `intValue` must be an integer
within the signed 64-bit range; any other value makes the whole export
undecodable (`400`). A protobuf encoder must not repeat a singular field (two
bodies on one record, say): that is undecodable too.

## What was tested

Each configuration in this guide was run, as printed, with only the endpoint
pointed at a local test server, on these versions:

| Tool                              | Version |
| --------------------------------- | ------- |
| OpenTelemetry Collector (contrib) | 0.161.0 |
| Fluent Bit                        | 5.1.3   |
| Vector                            | 0.58.0  |

Each run wrote the SDK's real lines, wrapped as the runtime wraps them and
split at 16 KiB, among ordinary application lines, and checked that every SDK
line arrived byte for byte (up to 196 KB long) and that no other line arrived.
The runs included a stderr line written between the parts of a split stdout
line, a whole Partner API line written to stderr, a line cut off mid-write
followed by a plain-text line and by a JSON log line, a line whose data has
fields named `log`, `stream` and `time`, and a 3 MB line; no export exceeded
100 lines or 4 MiB, and the token header came from the environment or secret
file. Further runs restarted the collector in the middle, made Partner API
answer `503` for 45 seconds while 8,000 lines were written (with a restart in
the middle of that too), started the collector on files that already held
lines, rotated a file the way logrotate does, deleted a container's log file
3 seconds after it appeared, and ran the OpenTelemetry configuration merged
by hand into a collector with its own health check and pipeline.
The same configurations were run against Partner API's own `/v1/logs`
handler, which stored every line and dropped the one written for an unknown
app key. Each fail-closed setting was also broken on purpose to confirm that
the line is dropped, not forwarded.
