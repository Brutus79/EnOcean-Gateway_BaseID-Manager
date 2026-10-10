# C2 native-gateway maintenance — final product

The manually accepted UI uses the existing production C2/B6 write path. The
permanent development barrier is disabled; every other live safety gate remains
active. Finalization tests use a local SDK Parent double, never physical hardware.

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

Return follows `RETURN_CLOSING → RESTORED → RETURNED` or a visible fault.
The legacy internal name `NATIVE_REFRESH_PENDING` now covers only technical return.
Exact configuration restoration and UART ownership remain required. No native
cache-refresh observer or additional one-to-two-minute wait runs afterwards.
Faults remain latched until a completely new maintenance session is started.
`WRITE_BLOCKED` is the retained internal prewrite-proof state, not a permanent
hardware-write ban: the existing C2/B6 engine performs the final gates and send.

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

Acquisition of the five initial and five prewrite read pairs remains limited to
60 seconds; in-flight response timeouts are unchanged. Once established, an otherwise intact `MAINTENANCE_READY`
session has no time limit: exclusive ownership and the exact live context are
still rechecked on each maintenance tick and before local actions/target review.
Target review can use that verified snapshot after a long stay, but both new
confirmation stages and all five newly acquired prewrite pairs are still mandatory.
A/B confirmation and successfully completed prewrite verification have no
time-based idle expiry within the continuously verified exclusive session.
Ownership loss, disconnect/new session, transport/handoff changes, changed target,
contradictory hardware responses, communication faults, leases and unknown
outcomes still invalidate the proof and latch faults. Return/restart cannot reuse
it. Timestamps remain diagnostic metadata, not a user deadline. This change does
not authorize any send or relax post-write verification/UNKNOWN handling.
The two confirmation stages and completed prewrite proof start the existing
B6 transaction. Its additional fresh reads, live gates and durable WAL precede
the sole write attempt. Post-write verification and UNKNOWN/no-retry handling
remain unchanged; see [write integration](c2-write-integration.md).

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

## Guided configuration and technical return

One shared selector covers manual Base-ID input, the stored Master and history.
**BASE-ID PRÜFEN** checks the existing format/range/128-address alignment rules.
Only the exact checked selection enables local Master saving or target preparation;
a value/source change immediately invalidates that permission. A target still
requires maintenance, explicit A/B confirmation and all existing live write gates.
Backup deletion affects only locally stored values, never chip storage or counters.

Named fields use incremental SDK updates, without reloading the entire form or
resetting user inputs. Hardware Base-ID, local Master and desired target remain
separate. Technical return restores the original native connection and verifies
configuration and UART ownership. It does not wait for native cache-uptake debug
telemetry. Return is not post-write verification: a write still requires the full
fresh disconnect/new-session/EURID/Base-ID/counter proof before success.

## Historical validation and supported scope

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

The current UI and functionality were manually accepted. The final enabled send
path was subsequently verified with a local Parent double, not a real hardware
write. Direct serial ESP3 remains the supported profile; LAN/ESP2, unknown chains
and unverified hardware contracts remain blocked. No production deployment or
physical actuator test was performed during finalization.

## User workflow

Install the product repository through IP-Symcon's Module repository dialog and
select `main`. Create/open the EnOcean Gateway Manager configurator, explicitly
choose the existing native gateway and open its manager. Unsupported parent
chains are rejected; do not substitute or guess endpoint parameters.

Start maintenance and inspect the freshly verified identity/Base-ID/counter.
Select or enter a Base-ID, use **BASE-ID PRÜFEN**, then save it locally as Master
or prepare it as the desired gateway target. Confirm A then B only after reviewing
the actual current/desired values and counter. The existing C2/B6 path performs
its final reads and gates, one write attempt, then mandatory postverification.
Same-value targets do not write. UNKNOWN outcomes never cause an automatic retry.
End maintenance to restore native operation; no extra native refresh wait follows.
