# Security Policy

## Supported versions

Only the latest release of Stream Engine receives security fixes. Fixes are
published as a new release; older versions are not patched. Please upgrade to
the latest release before reporting an issue, and check whether it still
reproduces there.

| Version | Supported |
| --- | --- |
| Latest release (currently 0.6.x) | Yes |
| Anything older | No |

## Reporting a vulnerability

Please do not report security vulnerabilities through public issues, pull
requests, or discussions.

Report them privately through GitHub instead:
[Report a vulnerability](https://github.com/bfhp/stream-engine/security/advisories/new)
(Security tab → Report a vulnerability).

Include as much of the following as you can:

- the affected version or commit;
- the type of issue, such as SQL injection, cross-site scripting, CSRF, or
  authentication bypass;
- the affected URL, endpoint, module, or source file;
- step-by-step instructions to reproduce it, and any configuration it needs;
- a proof of concept, if you have one;
- the impact: what an attacker could do with it.

## What to expect

Stream Engine is maintained by volunteers, so the timelines below are goals
rather than guarantees:

- an acknowledgement of your report within 7 days;
- an initial assessment, including whether the issue is accepted, within
  14 days;
- updates on progress until the issue is fixed or closed.

Once a fix is released, the issue is disclosed in a GitHub Security Advisory
and in the changelog. You will be credited for the discovery unless you prefer
to stay anonymous. Please keep the details private until the fix is released.
