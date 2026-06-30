#!/bin/bash
#
# Step 4a + 4b + 4c + 5a + 5b + 5c + 6 + 7 Test Runner
#
# Runs the Step 4a/4b/4c suites, the Step 5a orchestrator suites, the
# Step 5b/5c persistence + worker suites, the Step 6 HTTP-facing
# action suite, and the Step 7 reconciler suite:
#
#   Step 4a (CREATE step library):
#     - provisioning-steps-saga-test.php    (StepName / SagaRegistry /
#                                            VhostConfigTemplate / pure
#                                            unit derivations - no system
#                                            side effects)
#     - provisioning-steps-ols-test.php     (VhostConfigWriteStep,
#                                            OlsMainConfigInsertStep,
#                                            OlsRestartStep against a
#                                            sandboxed ols/ tree)
#     - provisioning-steps-fs-sftp-test.php (HomeDirCreateStep,
#                                            SftpGroupCreateStep,
#                                            SftpUserCreateStep; SFTP
#                                            groups require root and
#                                            SKIP if not)
#     - provisioning-steps-db-test.php      (DatabaseCreateStep,
#                                            DatabaseUserCreateStep,
#                                            DatabaseGrantStep; SKIP
#                                            destructive tests when run
#                                            without --admin-user / --admin-pass)
#
#   Step 4b (DELETE step library):
#     - provisioning-steps-delete-test.php  (PreDeleteSnapshotStep,
#                                            DatabaseDropStep,
#                                            DatabaseUserDropStep,
#                                            OlsMainConfigRemoveStep,
#                                            VhostConfigRemoveStep,
#                                            HomeDirRemoveStep,
#                                            SftpUserRemoveStep,
#                                            SftpGroupRemoveStep; SFTP +
#                                            DB groups SKIP without root /
#                                            admin)
#     - mail-teardown-step-test.php         (MailTeardownStep against
#                                            sandboxed vmail/dkim roots
#                                            + real mail_domains rows;
#                                            SKIPs when the mail tables
#                                            are absent)
#     - dns-zone-step-test.php              (DnsZoneCreateStep +
#                                            DnsZoneRemoveStep incl.
#                                            native pdns table cleanup)
#     - saga-delete-e2e-test.php            (orchestrator driving the
#                                            DELETE subset
#                                            PreDeleteSnapshot + mail
#                                            teardown + main config
#                                            remove + vhost dir remove +
#                                            home dir rmtree against the
#                                            sandbox with snapshot-guard
#                                            coverage)
#
#   Step 4c (SUSPEND / RESUME / ARCHIVE / RESTORE step library):
#     - provisioning-steps-suspend-test.php (VhostSuspendStep,
#                                            VhostResumeStep,
#                                            ArchivePromoteStep,
#                                            ArchiveRestorePreflightStep,
#                                            HomeDirHydrateStep,
#                                            DatabaseHydrateStep; the
#                                            db_hydrate real-restore
#                                            case SKIPs without MySQL
#                                            admin creds)
#     - saga-lifecycle-e2e-test.php         (orchestrator driving the
#                                            SUSPEND + RESUME sagas
#                                            against the sandbox,
#                                            including the idempotent
#                                            re-run + failure paths)
#
#   Step 5a (orchestrator):
#     - saga-orchestrator-test.php          (FSM unit tests with fake steps)
#     - saga-create-e2e-test.php            (orchestrator driving real
#                                            VhostConfigWrite +
#                                            OlsMainConfigInsert against
#                                            the sandbox; does NOT call
#                                            lswsctrl)
#
#   Step 5b (state machine bridge):
#     - saga-state-bridge-test.php          (ProvisioningSagaRunner +
#                                            SiteStateMachine integration:
#                                            outcome mapping + real sites
#                                            row transitions for CREATE,
#                                            DELETE, RESTORE, ABORTED, and
#                                            illegal-source paths)
#
#   Step 5c-1 (DB-backed persistence):
#     - db-persistence-test.php             (DbStepStateStore +
#                                            DbSagaEventSink round-trip
#                                            against the live site_jobs /
#                                            site_step_executions /
#                                            site_job_events schema, with
#                                            secret-masking checks)
#
#   Step 5c-2 (job queue):
#     - job-dispatcher-test.php             (JobDispatcher enqueue +
#                                            retrieval + listing + cancel
#                                            + payload validation)
#     - job-worker-test.php                 (JobWorker claim/lease/run/
#                                            persist cycle with retry
#                                            backoff, exhaustion,
#                                            cancellation, unsupported
#                                            types - uses a fake 1-step
#                                            saga registry)
#
#   Step 5c-3 (worker daemon + sweeper):
#     - dead-lease-sweeper-test.php         (DeadLeaseSweeper recovery
#                                            of stale-leased running rows
#                                            back to queued, audit row
#                                            written, terminal rows
#                                            untouched, idempotency)
#     - worker-daemon-test.php              (WorkerDaemon loop semantics:
#                                            runUntil, idle backoff,
#                                            drain mode, requestStop,
#                                            SIGTERM, pause file)
#     - worker-supervisor-test.php          (WorkerSupervisor factory
#                                            rebuild after N jobs +
#                                            rapid-restart ceiling +
#                                            requestStop)
#
#   Step 6 (HTTP-facing provisioning action):
#     - provisioning-action-test.php        (ProvisioningAction enqueue/
#                                            cancel/retry/list/get,
#                                            duplicate-job dedup,
#                                            secret masking on the
#                                            HTTP surface, RBAC envelopes)
#
#   Step 7 (reconciler):
#     - reconciler-test.php                 (SiteProber against a
#                                            sandbox, DriftAssessor's
#                                            decision matrix,
#                                            ReconcilerService end-to-end
#                                            including in-flight blocking,
#                                            DEGRADE transitions, and
#                                            non-eligible state filtering)
#     - stuck-site-sweeper-test.php         (StuckSiteSweeper landing
#                                            maps, grace/active-job
#                                            skips, orphaned-create rows
#                                            -> failed, dry-run + audit)
#
# Usage:
#   sudo bash /var/www/vps-admin/tests/run-steps-tests.sh --verbose
#   sudo bash /var/www/vps-admin/tests/run-steps-tests.sh --verbose --admin-user=root --admin-pass=SECRET
#   sudo bash /var/www/vps-admin/tests/run-steps-tests.sh --verbose --with-restart    # actually invokes lswsctrl
#
# The --admin-*, --with-restart flags are filtered out before being
# passed to suites that don't understand them.
#

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

PHP_BIN="/usr/local/lsws/lsphp83/bin/php"
if [ ! -x "$PHP_BIN" ]; then
    PHP_BIN="$(command -v php || echo php)"
fi

# Split flags into:
#   - "common" (passed to every suite)
#   - "db-only" (admin creds, only passed to db-test)
#   - "ols-only" (--with-restart, only passed to ols-test)
COMMON_FLAGS=()
DB_FLAGS=()
OLS_FLAGS=()
for arg in "$@"; do
    case "$arg" in
        --admin-user=*|--admin-pass=*|--admin-socket=*)
            DB_FLAGS+=("$arg")
            ;;
        --with-restart)
            OLS_FLAGS+=("$arg")
            ;;
        *)
            COMMON_FLAGS+=("$arg")
            ;;
    esac
done

SUITES=(
    "provisioning-steps-saga-test.php|common"
    "provisioning-steps-ols-test.php|ols"
    "provisioning-steps-fs-sftp-test.php|common"
    "provisioning-steps-db-test.php|db"
    "provisioning-steps-delete-test.php|common"
    "mail-teardown-step-test.php|common"
    "dns-zone-step-test.php|common"
    "provisioning-steps-suspend-test.php|db"
    "saga-orchestrator-test.php|common"
    "saga-create-e2e-test.php|common"
    "saga-delete-e2e-test.php|common"
    "saga-lifecycle-e2e-test.php|common"
    "saga-state-bridge-test.php|common"
    "db-persistence-test.php|common"
    "job-dispatcher-test.php|common"
    "job-worker-test.php|common"
    "dead-lease-sweeper-test.php|common"
    "worker-daemon-test.php|common"
    "worker-supervisor-test.php|common"
    "provisioning-action-test.php|common"
    "reconciler-test.php|common"
    "stuck-site-sweeper-test.php|common"
)

PASS=0
FAIL=0
FAILED_SUITES=()

for entry in "${SUITES[@]}"; do
    suite="${entry%%|*}"
    kind="${entry##*|}"
    echo
    echo "========================================================"
    echo " Running ${suite}"
    echo "========================================================"

    case "$kind" in
        ols)  EXTRA=("${OLS_FLAGS[@]+"${OLS_FLAGS[@]}"}") ;;
        db)   EXTRA=("${DB_FLAGS[@]+"${DB_FLAGS[@]}"}") ;;
        *)    EXTRA=() ;;
    esac

    if "$PHP_BIN" "$SCRIPT_DIR/$suite" \
        "${COMMON_FLAGS[@]+"${COMMON_FLAGS[@]}"}" \
        "${EXTRA[@]+"${EXTRA[@]}"}"; then
        PASS=$((PASS + 1))
    else
        FAIL=$((FAIL + 1))
        FAILED_SUITES+=("$suite")
    fi
done

echo
echo "========================================================"
echo " Step 4a + 4b + 4c + 5a + 5b + 5c-1 + 5c-2 + 5c-3 + 6 + 7 suite summary"
echo "========================================================"
echo "  Passed: $PASS"
echo "  Failed: $FAIL"
if [ ${#FAILED_SUITES[@]} -gt 0 ]; then
    echo
    echo "Failed suites:"
    for s in "${FAILED_SUITES[@]}"; do
        echo "  - $s"
    done
fi

[ "$FAIL" -eq 0 ]
