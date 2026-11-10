# Host requests

Eligible guests submit a separate host application. Neither real verification nor the local debug verification buttons grant the host role. Admins review requests at `/admin/host-requests`, with a required reason for rejection. Rejected applicants can reapply. Existing host accounts are preserved.

Debug applications are marked explicitly. Approving one requires a loopback request with `APP_ENV=local` and `DEBUG_VERIFICATION_ENABLED=true`. Approval persists the host role; disabling debug does not revoke an admin-approved role. Real applications require current verified contacts and approved identity again when approved. Profile changes invalidate pending approval.

Apply `database/migrations/20261110_host_requests.sql` with a database administrator, or run `php database/install_host_requests.php` using migration credentials supplied through the environment. The normal application account needs EXECUTE permission on the new procedures. The migration disables the old self-service role-grant procedure.

Validate with `php tests/host_eligibility_test.php` after installation. It requires a local database with an admin fixture and rolls back its test data. It covers verification gates, pending requests, duplicate submission, unauthorized review, rejection, reapplication, production rejection of debug approval, and separate admin approval.
