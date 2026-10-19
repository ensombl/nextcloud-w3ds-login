# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Entries below are reconstructed from commit history.

## [Unreleased]

## [0.14.1] - 2026-10-19

### Fixed

- Imported one-to-one conversations now include both people. Talk adds only
  the first person to a new one-to-one and adds the other when a message is
  sent through its chat API, which imported messages never go through. The
  other person, often the one the conversation was imported for, could not
  see it. An upgrade step adds them to existing conversations.

## [0.14.0] - 2026-10-19

### Fixed

- Display names no longer revert to the eName at every login. user_oidc writes
  the identity provider's name each time, and an IdP without one sends the
  username, which is the eName. For accounts linked to that eName, the name
  and email user_oidc writes now come from the eVault profile.
- References are no longer turned into rooms. Some platforms leave a
  reference in each participant's eVault pointing at the real conversation or
  message (`isReference`, `canonicalOwnerEName`, `canonical…Id`). Each one
  became an empty, untitled room with only its reader in it. The conversation
  or message it points at is now applied instead.
- Copies of one message, which have a different envelope id in each
  participant's eVault, are recognised by their shared inner id. Copies of
  messages imported earlier are recognised by sender and send time.
- Imported history is applied oldest first across all eVaults, so
  conversations are in order. Imported attachments keep the date they were
  sent instead of showing as sent on the day of the import.

### Added

- `occ w3ds_login:cleanup-reference-rooms [--delete]` removes the empty rooms
  made from references.
- `occ w3ds_login:backdate-imported-attachments [--dry-run]` gives attachments
  imported before this release their original dates.

## [0.13.0] - 2026-10-19

### Fixed

- Messages from other people now come through.
  - W3DS stores a message in the conversation's own eVault (a group's) or in
    its sender's, never in each reader's. Reading only the user's own eVault
    fetched nothing but what they had sent themselves.
  - The history fetch now reads every conversation's own eVault, the eVault
    each conversation came from, and every participant's eVault.
  - Opening a conversation reads its last week from those eVaults.
- Conversations a group keeps in the group's own eVault are now found by the
  history fetch.
- Accounts this app creates for chat peers no longer count as "somebody here"
  when deciding whether a conversation from the network belongs on this
  server. Counting them created rooms here for unrelated conversations
  involving those peers. An account counts when someone made or linked it
  themselves, or has signed in to it.

## [0.12.0] - 2026-10-19

### Changed

- Opening a conversation in Talk fetches new W3DS messages immediately, and
  again every 10 seconds while it stays open (every 30 with none open).
- The "Sync W3DS chats" button in Talk is replaced by a status indicator at the
  foot of the conversation list. It shows "Syncing W3DS…", "Fetching your W3DS
  history…", "W3DS up to date · 12s ago", or why sync cannot run.
- A user's W3DS history is fetched automatically the first time they open
  Talk, and an incomplete fetch is retried automatically after 15 minutes.
  Personal settings keep a manual "Fetch my W3DS history again".

### Fixed

- A history fetch no longer ends at the first page the awareness service fails
  to return. Each page is retried three times with backoff.
- The sync no longer reports misleading counts: "384 conversations" counted
  every chat on the network it had read, not the user's.

## [0.11.0] - 2026-10-19

### Added

- New messages can arrive within 30 seconds instead of with the next cron run:
  - `occ w3ds_login:awareness-poll` polls the awareness service every 30
    seconds; run it as a service next to Nextcloud;
  - open Talk pages also trigger a poll every 30 seconds, so chats stay live
    without the worker while anyone is using Talk.

  A lock ensures only one poll reads the stream at a time. The admin page shows
  when messages were last checked for.

### Fixed

- "Sync W3DS chats", and the history fetched when someone links, now reach
  every message.
  - They used to read the whole network's traffic oldest-first and stop at a
    page limit. On a busy network that ran out before the recent messages, and
    still reported success.
  - They now read only the user's own eVault: every conversation, and 90 days
    of messages.
  - A conversation older than the window no longer loses the messages sent in
    it since.
- The background poll is marked time-sensitive, so Nextcloud no longer defers
  it to a maintenance window.

## [0.10.0] - 2026-10-19

### Added

- An admin settings page, Administration settings → W3DS, to connect the
  awareness service that delivers incoming chats. It sets the service URL, API
  key and webhook secret, which until now needed `occ config:app:set`.
  - The key and secret are never shown back.
  - "Test connection" checks that the key is accepted and the consumer
    approved.
  - The page warns when background jobs are not running.
- A "Sync W3DS chats" button in Talk's conversation list and in personal
  settings. It fetches the user's last 30 days of W3DS conversations again and
  shows progress.
  - It explains when sync cannot run: the account isn't linked, or the server
    isn't connected to the awareness service.

## [0.9.0] - 2026-10-19

### Added

- Linked users get their whole eVault profile at every login: through the
  identity provider, with the wallet, and right after linking. That covers
  display name, email, avatar, phone, address, website, biography, headline,
  organisation and role, with the ProfessionalProfile filling gaps. The eVault
  wins over the identity provider's claims; empty profile fields leave
  Nextcloud's values alone.
- Linking no longer fails when this app had already made an account for the
  eName (at a wallet login, in a synced chat or in a search). That account is
  folded into the one being linked:
  - its Talk conversations, messages, reactions and read markers move across
    without announcing anything in the rooms;
  - a background job moves its files and then deletes it.

  Accounts this app did not create, administrators, and accounts with calendar
  or contact entries are still refused.

### Changed

- The display name now always follows the eVault, so edits made in Nextcloud
  are overwritten at the next login.
- The avatar is re-downloaded only when the profile points at a new picture,
  replacing the once-a-day refresh.

### Fixed

- The eName hint is now read from the OIDC session as well, so the link prompt
  appears with released versions of `user_oidc`. Those don't raise the token
  event the app listened for.
- Accounts created by `user_oidc` now take their display name from the eVault.
  Their backend refuses Nextcloud's normal display-name setter.
- The linking dialogs show why linking could not start instead of silently
  resetting.
- Wallet logins now record the last-login time.
- eVaults hosted on a public IP address are reachable without turning off
  Nextcloud's protection against requests to local addresses (#38).

## [0.8.0] - 2026-09-30

### Added

- Users who sign in through an identity provider with the OpenID Connect user
  backend (`user_oidc`), for example Keycloak or Rauthy in front of the W3DS
  OIDC connector, can now sync. The provider names their eName in the standard
  `preferred_username` claim. The app keeps it as a hint and asks the user to
  confirm it with one wallet scan. The link is made only if the wallet holds
  that eName, because an identity provider's username alone is not proof.
- The prompt can be dismissed; users can still link later from personal
  settings. Administrators can prompt every unlinked user with
  `occ config:app:set w3ds_login prompt_unlinked_users --value=yes`.
- `docs/installation.md` explains how to configure `user_oidc` so user IDs are
  the eName rather than a hash of the provider's `sub`.

## [0.7.2] - 2026-09-17

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
  secret with `occ config:app:set`; see `docs/installation.md`.
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
