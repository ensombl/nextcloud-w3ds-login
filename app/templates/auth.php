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
            <h2>Sign in with W3DS</h2>
            <p class="w3ds-auth-subtitle">
                Scan the QR code with your eID wallet to authenticate.
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

<style>
    .w3ds-auth-page {
        display: flex;
        justify-content: center;
        align-items: center;
        min-height: 60vh;
        padding: 2rem;
    }

    .w3ds-auth-card {
        background: var(--color-main-background, #fff);
        border-radius: 16px;
        box-shadow: 0 2px 16px rgba(0, 0, 0, 0.08);
        padding: 2.5rem;
        max-width: 380px;
        width: 100%;
        text-align: center;
    }

    .w3ds-auth-header h2 {
        margin: 0 0 0.5rem;
        font-size: 1.4rem;
        font-weight: 600;
        color: var(--color-main-text, #222);
    }

    .w3ds-auth-subtitle {
        color: var(--color-text-maxcontrast, #767676);
        font-size: 0.9rem;
        margin: 0 0 1.5rem;
        line-height: 1.4;
    }

    .w3ds-qr-container {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 1.25rem;
        margin-bottom: 1.5rem;
    }

    .w3ds-qr {
        padding: 1rem;
        background: #fff;
        border-radius: 12px;
        border: 1px solid var(--color-border, #ededed);
        line-height: 0;
    }

    .w3ds-qr svg {
        width: 200px;
        height: 200px;
    }

    .w3ds-status {
        font-size: 0.85rem;
        color: var(--color-text-maxcontrast, #767676);
    }

    .w3ds-status-waiting {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .w3ds-status-success {
        color: var(--color-success, #46ba61);
        font-weight: 500;
    }

    .w3ds-status-error {
        color: var(--color-error, #e9322d);
    }

    .w3ds-spinner {
        display: inline-block;
        width: 14px;
        height: 14px;
        border: 2px solid var(--color-border, #ddd);
        border-top-color: var(--color-primary, #0082c9);
        border-radius: 50%;
        animation: w3ds-spin 0.8s linear infinite;
    }

    @keyframes w3ds-spin {
        to { transform: rotate(360deg); }
    }

    .w3ds-deeplink {
        display: inline-block;
        padding: 0.6rem 1.5rem;
        background: var(--color-primary, #0082c9);
        color: var(--color-primary-text, #fff) !important;
        border-radius: 20px;
        text-decoration: none;
        font-size: 0.9rem;
        font-weight: 500;
        transition: opacity 0.15s;
    }

    .w3ds-deeplink:hover {
        opacity: 0.9;
    }

    .w3ds-auth-footer {
        padding-top: 0.5rem;
    }
</style>
