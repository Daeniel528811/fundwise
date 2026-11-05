# Security boundaries and review

This is an educational PHP/MySQL system. Safeguards and tests reduce specific risks; they are not a penetration-test certification or a guarantee that every endpoint is vulnerability-free.

## Existing controls
- Password hashes use PHP password functions. Recovery-code hashes are stored separately; replacement codes are shown once.
- Shared session/authentication checks protect authenticated pages, with 30-minute idle and 12-hour absolute expiry and credential-version checks.
- Reviewed mutations use CSRF tokens and one-time request identifiers. Page-specific ownership checks bind records to the current session user.
- Reviewed queries use prepared statements; dynamic output is escaped. File names, MIME types and sizes are validated for receipt uploads, with a 5 MB limit.
- Receipt retrieval requires login and transaction ownership. Direct-file denial depends on the web server enforcing `uploads/receipts/.htaccess`.
- Login, recovery and sensitive password actions have throttling. This is not an application-wide request-rate limiter or a framework middleware layer.
- Personal financial information must remain outside the public repository.

## Architecture boundary
The app uses PHP and MySQL with server-side `user_id` authorization checks. It does not use Supabase RLS policies. Authorization must be enforced for each requested record, not just by hiding buttons. Recheck ownership for POST forms, GET previews, downloads, linked records and files whenever adding a feature.

## Before real use
Verify HTTPS, direct receipt access, authentication redirects, session expiry, account isolation, CSRF, request replay, upload failure paths and safe error messages on the deployed host. Avoid recording sensitive real financial data until a suitable review is completed. Notifications are not emergency or guaranteed financial alerts.

## If a secret was committed
Rotate the exposed credential with its provider. Remove it from the current tree, then plan history cleanup carefully; `.gitignore` alone does not fix previous commits. Do not paste credentials into issues or screenshots.

## Reporting
Describe reproducible steps and affected endpoints to the project owner without posting real credentials or financial data publicly. No separate private security-contact address or response-time guarantee is configured for this project.
