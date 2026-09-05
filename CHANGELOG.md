# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Entries below are reconstructed from commit history. The repository carries no
release tags yet, so the version headings correspond to the `<version>` values
recorded in `appinfo/info.xml` rather than to published releases.

## [Unreleased]

## [0.7.0]

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
