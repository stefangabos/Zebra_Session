# Security policy

## Supported versions

Security fixes are made to the latest release. If you are running an older version, updating is the fix.

| Version | Supported          |
| ------- | ------------------ |
| 4.x     | :white_check_mark: |
| < 4.0   | :x:                |

## Reporting a vulnerability

**Please do not open a public issue for a security problem.** This library handles session data - reporting a flaw publicly before there is a fix hands it to anyone reading the issue tracker.

Report it privately in one of two ways:

- through GitHub, using [private vulnerability reporting](https://github.com/stefangabos/Zebra_Session/security/advisories/new) - this is the preferred route, as it keeps the report, the discussion and the eventual advisory in one place
- by email, to contact@stefangabos.ro

Useful things to include, as far as you can:

- which version you were running, and which PHP and MySQL versions
- how the library was configured - in particular the `lock_to_user_agent`, `lock_to_ip` and `read_only` arguments, since those decide how a session is tied to a visitor
- what an attacker would be able to do
- anything that reproduces it

This is a project maintained in spare time, so please don't expect an immediate reply. Your report will be acknowledged as soon as I get to it, and I will let you know what I intend to do about it and roughly when. If you would like credit in the advisory and the changelog, say so and tell me how you would like to be named.

## Scope

The library is a session handler: it stores session data in MySQL and ties each session to the visitor who started it. Reports that concern that job are in scope - for example a way to read or take over another visitor's session, session data leaking between sessions, or the hash that identifies a visitor being bypassed.

Out of scope, because they are not something the library can decide for you:

- a database that is reachable by people who should not reach it, or credentials kept somewhere readable
- session IDs exposed by your own application, for example in URLs or logs
- anything that requires an attacker to already have the session ID and the visitor's user agent and IP address

If you are unsure whether something counts, report it anyway.
