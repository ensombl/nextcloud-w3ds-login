# Nextcloud W3DS Login

Passwordless Nextcloud login via the [W3DS](https://w3ds.metastate.foundation) decentralized identity protocol, plus optional bidirectional sync of Nextcloud Talk chats to/from user eVaults.

Users authenticate by scanning a QR code with their eID wallet, which signs a session challenge using ECDSA P-256. No passwords are transmitted or stored. Once linked, a user's Talk rooms and messages are mirrored to their eVault so the same conversations show up on any other W3DS-connected platform.

## Docs

- [Installation](docs/installation.md) — manual install, Docker dev setup, and app store publishing
- [How it works](docs/how-it-works.md) — auth flow, sync architecture, and data model

## License

AGPL-3.0-or-later
