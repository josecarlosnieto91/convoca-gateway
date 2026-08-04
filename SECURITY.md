# Security Policy

## Supported Versions

Only the latest version of each Convoca plugin receives security updates.

| Plugin               | Latest | Security Updates |
|----------------------|--------|:----------------:|
| convoca-core         | 2.1.x  | ✅ |
| convoca-enroll       | 2.6.x  | ✅ |
| convoca-members      | 2.6.x  | ✅ |
| convoca-gateway      | 2.6.x  | ✅ |
| convoca-shifts       | 2.5.x  | ✅ |
| convoca-publisher    | 1.3.x  | ✅ |
| convoca-theme        | 2.6.x  | ✅ |

Older versions are not maintained and may contain unpatched vulnerabilities.

## Reporting a Vulnerability

We take security seriously. If you discover a security vulnerability within any Convoca plugin or theme, please report it privately.

**Do not open a public GitHub issue.**

### How to Report

Send an encrypted email to **security@convoca.org** with:

1. A clear description of the vulnerability
2. Steps to reproduce (proof-of-concept code is helpful but not required)
3. Affected plugin(s) and version(s)
4. Any potential impact assessment

You can use our PGP key for encrypted communication:

```
Key ID: [to be published]
Fingerprint: [to be published]
```

### What to Expect

- **Acknowledgment**: within 48 hours
- **Assessment**: we will confirm the vulnerability and determine severity within 5 business days
- **Fix timeline**: critical vulnerabilities are patched within 7 days; lower severity within 30 days
- **Disclosure**: we coordinate public disclosure with the reporter after a fix is released
- **Credit**: reporters are credited in the release notes (unless they opt out)

### Scope

Security reports are accepted for:

- Any Convoca plugin or theme in the `josecarlosnieto91` GitHub organization
- The official distribution at getconvoca.app
- Third-party integrations only if the vulnerability originates in Convoca code

Out of scope:
- Vulnerabilities in WordPress core, third-party plugins, or server configuration
- Social engineering attacks
- Denial of service (DoS) without clear impact

## Security Best Practices for Users

1. **Keep plugins updated** — always run the latest version
2. **Use strong license keys** — never share or hardcode keys
3. **Review permissions** — audit WordPress user roles and capabilities
4. **Use HTTPS** — all Convoca API communication requires SSL
5. **Monitor logs** — review the Convoca audit log regularly
6. **Backup before updating** — always back up your site before applying updates

## Past Vulnerabilities

We maintain a public list of past security advisories at:

https://getconvoca.app/security/advisories
