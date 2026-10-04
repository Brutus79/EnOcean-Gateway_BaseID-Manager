# C2 native-gateway maintenance — development build

Status: **USER_ACCEPTANCE_READY** for manual acceptance of the supported serial
read/local-target/blocked-prewrite workflow. This is an unpublished development
branch, not a release or authorization for physical writes.
Physical Base-ID writes remain blocked at the arbiter send site.
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

Initial SDK splitter activation is observed before any proof/read starts, with
an abort deadline, not a fixed waiting time used as evidence. Ownership, exact
configuration, zero fault epoch, safe correlation and no write lease are required
even in this activation stage. Once proof has started, context loss always latches.
Dynamic `/proc` descriptor checks clear both stat and realpath caches; a reused
descriptor number must not be counted as its former device.

Destroy/library reload durably cancels maintenance and restores only exact owned
objects. A configurator recovery timer completes recorded retirement, never adopts
unknown sessions or resumes a write. Local history/Master survives removal while
the live instance binding is removed to prevent recycled IDs adopting old inventory.

## Read and confirmation gates

Initial synchronization and final preparation each use five sequential
`CO_RD_VERSION / CO_RD_IDBASE` pairs through the existing ESP3 arbiter.
Base-ID and counter form one IDBASE observation, not two identity votes. Entire
parsed responses, session, handoff binding, transport binding, descriptor count
and communication-fault epoch must remain consistent. Later successful reads do
not clear warnings. Format, range and 128-ID alignment validation precede target
preparation. Remaining cycles come from hardware; `FF` means unlimited, a missing
counter remains unknown. No-op and zero-counter targets cannot start a change.
The existing five-cycle reserve policy applies to finite counters as well.
The final prewrite gate additionally rechecks a fresh idle arbiter, no lease/no
unknown outcome, current session/bindings, fault epoch and exactly one descriptor.

Reads and confirmation remain limited to 60 seconds. Target changes, expired
proofs and stale confirmations require fresh preparation; there is no TTL extension.
The two confirmation stages lead only to a **blocked** physical prewrite proof in
this build. They create neither a physical write intent nor a hardware send.
The existing B6 transactional engine and immutable barrier remain independent.

Previously observed hardware and saved targets are historical/local data. A new
chip discovered in a new session is a legitimate replacement, not an automatic
error. Saved Base-ID deletion affects local module attributes only.
Master and history selection are local explicit actions, scoped to the logical
gateway. Replacement acceptance preserves old history and Master; it creates a
new fresh backup only after conscious acknowledgement. No historical data claims
current hardware truth. After return, displayed observations are historical.

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
observer. Full read-only C2 return integration was verified on the Testsystem:
expiry prevents further preparation and a new native read/processed RESULT proves
refresh after exact configuration restoration. No real actuator transmission was
needed. Actual native ERP1 sender-byte verification remains simulator-only.

References: [debug forwarding](https://www.symcon.de/de/service/dokumentation/befehlsreferenz/instanzenverwaltung/debug/ips-enabledebug/),
[message contract](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/nachrichten/),
[snapshot API release notes](https://www.symcon.de/de/service/dokumentation/installation/migrationen/v60-v61-q1-2022/).

## Validation and scope of acceptance

The regression suite has 22 test files and 22,988 passing assertions. Tests cover pure session
and resolver gates, bounded combined stale replies, arbiter stream faults,
configuration/parent/ownership changes, interrupted handoff mutations and native
refresh fragments/negative cases. Tests contain synthetic data only. PHP parsing
and all module/fixture JSON checks pass.

Repeated actual Symcon runtime tests cover five initial + five prewrite rounds,
confirmation A/B, local backup/Master/history, explicit replacement acceptance,
Destroy, ApplyChanges, library reload, normal service restart, SDK uninstall/reinstall,
foreign users, exact-CAS restore faults, wrong confirmations and latched CRC warnings.
All temporary test objects were removed; original foreign configurations remained
unchanged. A former hung-service recovery was a separately authorized one-off
operational repair, not a restart mechanism added to the product.

The test-only C2 simulator bridge exercises real arbiter read scheduling, the
existing transactional policy, durable write-ahead journaling, exactly one
in-memory write effect, unknown outcomes, crash cuts, observed disconnect/new
session, five fresh postverification rounds and final verification. It also proves
that injecting that write into the real read arbiter is rejected. The same 833
simulator assertions pass inside the actual Symcon PHP runtime. This test harness
is not a physical-send switch in the UI.

Native simulator tests verify B-to-A restoration using actual ERP1 sender bytes,
reject wrong Base-ID, missing RESULT and stream gaps, then verify the corrected
native return again. Long-lived PTY native I/O status is not a reliable substitute
for physical expiry/refresh evidence; the required read-only physical proof was
performed separately, without a hardware write or write-cycle consumption.

Manual acceptance is still pending. The supported profile is direct serial ESP3
only; LAN/ESP2, unknown chains, other hardware and other native debug contracts
remain unverified/blocked. No real write, physical actuator test, publication or
production deployment has been performed. Read-only/simulator readiness is not
permission to remove any barrier. Installation through GitHub must wait for a
separate authorization to publish this development branch.

## Prepared manual workflow

After separately authorized GitHub publication: install the existing product
repository via IP-Symcon's Module repository dialog and select the designated
acceptance branch. Create/open the EnOcean Gateway Manager configurator, choose
the existing compatible native gateway and open its manager. Do not manually
substitute endpoint parameters or select an unsupported chain.

Start maintenance, inspect the freshly verified identity/Base-ID/counter, save
locally if desired, consciously assign a local Master or select the gateway's
history/backup, review the target and confirm A then B. Expect `WRITE_BLOCKED`
with the unchanged hardware barrier. Return transport and distinguish restored
configuration from observed native refresh. A missing proof must stay a warning.
No Base-ID write is part of this acceptance build.
