# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Entries below are reconstructed from commit history.

## [Unreleased]

## [0.8.0] - 2026-09-17

### Fixed

- Inbound messages are no longer pushed back out to the eVault. Displaying a
  received message, and materialising a received attachment, both make Talk
  raise the same event it raises when a person types, so messages this server
  was only mirroring for display were replicated back out under the recipient's
  identity and delivered to everyone in the room. Received attachments were
  affected worst, because catch-up sync replayed the entire history of a
  conversation on install.

### Changed

- Incoming chats and messages now come from Awareness as a Service instead of
  polling each participant's eVault. A message previously arrived once per
  participant, under a different envelope ID each time, and was reconciled
  locally by comparing message content; it now arrives once, with the
  identifier the protocol defines for the purpose.
- Sending is unchanged: messages are still written to the sender's own eVault,
  and participants still receive a reference in theirs.
- Administrators configure the awareness service URL, API key and webhook
  secret under Settings → Administration → Security.
- Webhook deliveries are verified against the subscription secret when one is
  configured.

### Removed

- The browser-side poller, its two endpoints, and the per-eVault polling jobs.
- Message signature and occurrence-counting rows, and the sync cursor table.
  These existed only to tell one message seen several times apart from a
  message genuinely sent twice. Existing rows are cleaned up on upgrade.
- The one-time sync queued at login. Conversations reach the eVault when a
  message is sent or the participants change; queueing a full replay on every
  login is what turned the echo above into a mass re-send.

## [0.7.1] - 2026-09-15

### Changed

- Renamed the app to W3DS Connector and refreshed its App Store metadata and
  screenshots.
- Removed an unsupported public-certificate input from the App Store publishing
  workflow.

## [0.7.0] - 2026-09-10

### Fixed

- Avatars are downscaled before storage, so a high-resolution profile picture no
  longer exhausts the web tier's memory limit and falls back to initials.
- Attachments and avatars that arrive while another poller is running are no
  longer dropped when both race to create the same folder.
- Content mirrored from a remote W3DS platform is displayed only and is no
  longer written back to the eVault as if it originated here.

### Added

- Talk chat sync: Nextcloud Talk rooms and messages are mirrored to and from
  participants' eVaults, so the same conversations appear on any other
  W3DS-connected platform.
- "Add W3DS users" button inside a Talk room, and a collaborator-search plugin
  that resolves W3DS identities when picking share recipients.
- User profile sync: display name and email are hydrated from the eVault
  `User` profile envelope when an account is auto-provisioned.
- `w3ds:dedupe-mappings` occ command for cleaning up duplicate identity
  mappings.

### Changed

- Sessions authenticated through W3DS no longer prompt for a password on
  actions that would normally require re-entry, since no password exists.
- Chats are polled through the eVault's by-ontology listing rather than a
  per-room lookup.
- Pull sync collects messages from every participant's eVault, not only the
  room owner's.

### Fixed

- Duplicate Nextcloud accounts could be created for a single W3ID during
  collaborator search.
- Chat updates could ping-pong between platforms, each side re-pushing the
  other's inbound change.
- Certificate verification used the wrong port when resolving an eVault.
- Corrected the documented installation path for the app directory.

## [0.1.0]

### Added

- Initial app scaffold with the W3DS authentication flow.
- QR code login on the Nextcloud login page, with auto-polling for completion.
- Signature verification (ECDSA P-256) against the W3DS Registry.
- User auto-provisioning on first W3DS login.
- Account linking and unlinking from personal settings, with an inline QR modal.
- Docker development environment with Xdebug.
- CI pipelines for linting, static analysis, testing, and app store publishing.

[Unreleased]: https://github.com/ensombl/nextcloud-w3ds-login/compare/main...HEAD
