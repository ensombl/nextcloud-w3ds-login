<?php

declare(strict_types=1);

use OCP\Util;

Util::addScript('w3ds_login', 'poll');

/** @var array $_ */
$w3dsUri = $_['w3dsUri'];
$sessionId = $_['sessionId'];
$statusUrl = $_['statusUrl'];
$qrSvg = $_['qrSvg'];

?>

<div id="w3ds-auth" class="w3ds-auth-page" data-status-url="<?php p($statusUrl); ?>">
    <div class="w3ds-auth-card">
        <div class="w3ds-auth-header">
            <h2>Link your W3DS identity</h2>
            <p class="w3ds-auth-subtitle">
                Scan the QR code with your eID wallet to link your W3DS identity to this account.
            </p>
        </div>

        <div class="w3ds-qr-container">
            <div class="w3ds-qr">
                <?php print_unescaped($qrSvg); ?>
            </div>

            <div id="w3ds-status" class="w3ds-status">
                <div class="w3ds-status-waiting">
                    <span class="w3ds-spinner"></span>
                    <span>Waiting for authentication...</span>
                </div>
            </div>
        </div>

        <div class="w3ds-auth-footer">
            <a href="<?php p($w3dsUri); ?>" class="w3ds-deeplink">
                Open in eID Wallet
            </a>
        </div>
    </div>
</div>
