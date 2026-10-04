# C2 native-gateway maintenance — development build

Status: **NOT USER_ACCEPTANCE_READY**. This is an unpublished development branch,
not a release. Physical Base-ID writes remain blocked at the arbiter send site.
No simulator flag can enable that send site.

## Selection and transport

New installations select an existing native IP-Symcon EnOcean gateway through
the configurator or manager dropdown. The stored instance ID is only a reference.
Every start resolves the current module, connection, parent, complete native and
I/O configurations and sharing relationships anew. Inventory never supplies a
trusted port, chip identity or current Base-ID.

The currently implemented profile is a direct, active native ESP3-binary gateway
with a direct Serial Port I/O and 8N1 configuration. Baud rate and endpoint are
copied from its current configuration, not inferred from a model or region. LAN,
ESP2 and unknown parent chains fail closed. No multi-client TCP assumption is
made. Native device discovery does not imply hardware identification.

## State machine

`CAPTURED → CLOSING_NATIVE → DETACHED → ACTIVE → SYNCHRONIZING →
MAINTENANCE_READY → REVIEW_A → REVIEW_B → PREWRITE_VERIFYING → WRITE_BLOCKED`

Return follows `RETURN_CLOSING → RESTORED / NATIVE_REFRESH_PENDING → RETURNED`
or a visible warning. `RESTORED` describes configuration restoration only; it
does not by itself prove native cache refresh. Faults latch until the session is
returned and a completely new session is started.

The hash-chained, fsynced local handoff journal records original configurations,
intents and temporary-object ownership. Zero UART descriptors are required during
handoff, exactly one during maintenance. Counting only unique PIDs is insufficient.
All native restoration uses exact compare-and-set checks. Foreign configuration
changes or unknown children stop automatic restoration instead of being overwritten
or deleted. Only marked, recorded temporary objects may be removed.

## Read and confirmation gates

Initial synchronization and final preparation each use five sequential
`CO_RD_VERSION / CO_RD_IDBASE` pairs through the existing ESP3 arbiter.
Base-ID and counter form one IDBASE observation, not two identity votes. Entire
parsed responses, session, handoff binding, transport binding, descriptor count
and communication-fault epoch must remain consistent. Later successful reads do
not clear warnings. Format, range and 128-ID alignment validation precede target
preparation. Remaining cycles come from hardware; `FF` means unlimited, a missing
counter remains unknown. No-op and zero-counter targets cannot start a change.

Reads and confirmation remain limited to 60 seconds. Target changes, expired
proofs and stale confirmations require fresh preparation; there is no TTL extension.
The two confirmation stages lead only to a **blocked** physical prewrite proof in
this build. They create neither a physical write intent nor a hardware send.
The existing B6 transactional engine and immutable barrier remain independent.

Previously observed hardware and saved targets are historical/local data. A new
chip discovered in a new session is a legitimate replacement, not an automatic
error. Saved Base-ID deletion affects local module attributes only.

## Bounded fault model, not an origin guarantee

ESP3 RESPONSE has no host transaction ID. Five rounds do not prove the origin of
arbitrarily many coherent stale frames. The offline substitution matrix covers
up to four stale substitutions among ten prewrite replies, including identity-only,
Base-ID-only, counter-only and combined changes. Five rounds were selected because
three stale IDBASE replies could hide a Base-ID-only change in a three-round design.

Coherent replacement of all five replies of the changed operation remains outside
this bound. A same-valued stale response is indistinguishable and does not prove
fresh origin. No fixed sleep, old inventory or previous session is an alternative
safety proof. CRC faults, unexpected packet types/responses, duplicate packets,
timeouts, disconnects, context changes and ownership loss latch the session.

## Native return observation

Runtime investigation found no safe immediate refresh from `ApplyChanges` or
disconnect/reconnect. A reconnect alone can retain the old native send basis.
The native function list exposes no tested Base-ID cache getter/refresh command.
Mode-changing, search or SmartAck operations are not used as speculative fallbacks.

The implemented observer temporarily enables documented debug forwarding and
reads the kernel's snapshot-change stream from a cursor acquired **before** return.
It requires a native IDBASE TRANSMIT, a CRC-valid native Parse Buffer with the
expected Base-ID/counter, and the matching native RESULT processing event. Transmit
or receive alone is not a success. Unknown schemas, history gaps, overlapping
requests, wrong values and an observation timeout yield a warning. The original
configuration is restored independently of successful refresh observation.

Debug is local, non-authenticated telemetry. The native 9.0 event contract was
observed in a loopback simulator; actual native RADIO_ERP1 sender-byte checks
confirmed uptake after a changed simulator Base-ID. Debug forwarding expires
automatically; the manager does not disable another user's debug forwarding.
No secondary physical connection, proxy or native actuator send is used by this
observer. Full C2 return integration is still awaiting runtime completion.

References: [debug forwarding](https://www.symcon.de/de/service/dokumentation/befehlsreferenz/instanzenverwaltung/debug/ips-enabledebug/),
[message contract](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/nachrichten/),
[snapshot API release notes](https://www.symcon.de/de/service/dokumentation/installation/migrationen/v60-v61-q1-2022/).

## Validation and remaining blockers

The existing regression suite remains green. Additional tests cover pure session
and resolver gates, bounded combined stale replies, arbiter stream faults,
configuration/parent/ownership changes, interrupted handoff mutations and native
refresh fragments/negative cases. Tests contain synthetic data only.

The first actual product handoff and ten-read synchronization passed on the
Testsystem without any hardware write. Subsequent confirmation exposed sporadic
ownership-UNKNOWN stops; these are not counted as successful prewrite tests. A
later authorized Symcon restart hit its existing stop timeout and its API remained
in shutdown despite an active systemd status. This is a runtime blocker, not PASS.
Resolving the hung service requires separate operational approval. The latest
metadata-cache correction has not yet been runtime-validated.

Before manual acceptance: complete confirmation/prewrite/return integration,
native-refresh sender-byte restoration counterprobes, full C2 simulated-write
integration, lifecycle/uninstall/restart recovery and runtime fault regression.
No public push, release, deployment to production or real hardware write is
authorized by this development build.
