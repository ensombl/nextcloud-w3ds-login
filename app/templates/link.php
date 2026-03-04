<?php

declare(strict_types=1);

use OCP\Util;

Util::addScript('w3ds_login', 'poll');

/** @var array $_ */
$w3dsUri = $_['w3dsUri'];
$sessionId = $_['sessionId'];
$statusUrl = $_['statusUrl'];

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
            <div id="w3ds-qr" class="w3ds-qr">
                <div class="w3ds-qr-placeholder">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200" width="200" height="200">
                        <rect width="200" height="200" rx="12" fill="#f5f5f5" stroke="#ddd" stroke-width="1"/>
                        <g transform="translate(100,80)" text-anchor="middle">
                            <text font-size="48" fill="#0082c9">&#x2B1A;</text>
                        </g>
                        <text x="100" y="140" text-anchor="middle" font-size="11" fill="#666" font-family="sans-serif">
                            QR Code
                        </text>
                        <text x="100" y="158" text-anchor="middle" font-size="9" fill="#999" font-family="sans-serif">
                            Install php-qrcode for production
                        </text>
                    </svg>
                </div>
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
