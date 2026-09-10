# Nextcloud W3DS Login

Passwordless Nextcloud login via the [W3DS](https://w3ds.metastate.foundation) decentralized identity protocol, plus optional bidirectional sync of Nextcloud Talk chats to/from user eVaults.

Users authenticate by scanning a QR code with their eID wallet, which signs a session challenge using ECDSA P-256. No passwords are transmitted or stored. Once linked, a user's Talk rooms and messages are mirrored to their eVault so the same conversations show up on any other W3DS-connected platform.

## Docs

- [Installation](docs/installation.md) — manual install, Docker dev setup, and app store publishing
- [How it works](docs/how-it-works.md) — auth flow, sync architecture, and data model

## License

Copyright (C) 2026 Ensombl Pte. Ltd.

This program is free software: you can redistribute it and/or modify it under
the terms of the GNU Affero General Public License as published by the Free
Software Foundation, either version 3 of the License, or (at your option) any
later version. See [LICENSE](LICENSE) for the full text.
