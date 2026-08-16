# Security Policy

## Supported Versions

Only the latest stable release of Common Goals receives security updates.

| Version | Supported |
|---------|-----------|
| latest  | Yes       |
| < latest| No        |

## Reporting a Vulnerability

If you discover a security vulnerability in Common Goals, please report it
responsibly:

1. **Do not** open a public GitHub issue.
2. Email the details to the maintainer through the contact information at
   **https://heymauricio.com**. Do not include credentials, personal data or
   production exports in a report.
3. Include a clear description of the issue, the steps to reproduce it, and
   the potential impact.
4. If possible, include a proof of concept or patch.

We aim to acknowledge receipt within **48 hours** and to provide a fix or
mitigation within **30 days** for confirmed vulnerabilities.

## Scope

The following are considered security issues:

- SQL injection in any query.
- Cross-site scripting (XSS) in admin or frontend output.
- Broken access control (capability bypass, missing nonce checks).
- CSRF on any state-changing action.
- Arbitrary file inclusion or execution.
- Exposure of sensitive data to unauthorized users.

The following are **not** security issues and should be reported as regular
bugs:

- Content spam submitted through public forms (the plugin provides moderation
  tools for this).
- Visual or layout issues.
- Feature requests.

## Hardening Recommendations

- Keep the plugin, WordPress, PHP and MySQL updated to supported versions.
- Require user registration for posting if your community is sensitive.
- Regularly moderate the `pending` queue.
- Configure event log retention under Tools > Site Health.
- Use a SSL certificate on your site.
