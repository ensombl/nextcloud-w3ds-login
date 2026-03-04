<?php

declare(strict_types=1);

use OCP\Util;

Util::addScript('w3ds_login', 'link');

/** @var array $_ */
$linkedW3id = $_['linkedW3id'];
$hasEmail = $_['hasEmail'];
$linkStartUrl = $_['linkStartUrl'];
$unlinkUrl = $_['unlinkUrl'];

?>

<div id="w3ds-settings" class="section"
     data-link-start-url="<?php p($linkStartUrl); ?>"
     data-unlink-url="<?php p($unlinkUrl); ?>"
     data-request-token="<?php p($_['requesttoken']); ?>">
    <h2>W3DS Login</h2>

    <div id="w3ds-settings-content">
        <?php if ($linkedW3id): ?>
            <p class="w3ds-settings-status">
                Your account is linked to
                <strong><?php p($linkedW3id); ?></strong>
            </p>
            <?php if ($hasEmail): ?>
                <button id="w3ds-unlink-btn" type="button" class="button">
                    Unlink W3DS identity
                </button>
            <?php else: ?>
                <p class="w3ds-settings-note">
                    Set an email address in your profile to enable unlinking.
                </p>
            <?php endif; ?>
        <?php else: ?>
            <p class="w3ds-settings-status">
                Link your W3DS identity to sign in without a password.
            </p>
            <button id="w3ds-link-btn" type="button" class="button primary">
                Link W3DS identity
            </button>
        <?php endif; ?>
    </div>

    <!-- Link modal (hidden by default) -->
    <div id="w3ds-link-modal" class="w3ds-modal" hidden>
        <div class="w3ds-modal-backdrop"></div>
        <div class="w3ds-modal-card">
            <button type="button" class="w3ds-modal-close" aria-label="Close">&times;</button>
            <h3>Link your W3DS identity</h3>
            <p class="w3ds-modal-subtitle">
                Scan the QR code with your eID wallet, or tap the button below on your phone.
            </p>
            <div id="w3ds-modal-qr" class="w3ds-modal-qr"></div>
            <div id="w3ds-modal-status" class="w3ds-modal-status">
                <span class="w3ds-spinner"></span>
                <span>Waiting for wallet...</span>
            </div>
            <a id="w3ds-modal-deeplink" href="#" class="w3ds-deeplink">
                Open in eID Wallet
            </a>
        </div>
    </div>
</div>

<style>
    #w3ds-settings .w3ds-settings-status {
        margin-bottom: 1rem;
        color: var(--color-text-maxcontrast, #767676);
    }

    #w3ds-settings .w3ds-settings-status strong {
        color: var(--color-main-text, #222);
    }

    #w3ds-settings .w3ds-settings-note {
        margin-top: 0.5rem;
        font-size: 0.85rem;
        color: var(--color-text-maxcontrast, #767676);
    }

    /* Modal */
    .w3ds-modal {
        position: fixed;
        inset: 0;
        z-index: 10000;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .w3ds-modal[hidden] {
        display: none;
    }

    .w3ds-modal-backdrop {
        position: absolute;
        inset: 0;
        background: rgba(0, 0, 0, 0.5);
    }

    .w3ds-modal-card {
        position: relative;
        background: var(--color-main-background, #fff);
        border-radius: 16px;
        padding: 2rem;
        max-width: 340px;
        width: 90%;
        text-align: center;
        box-shadow: 0 8px 32px rgba(0, 0, 0, 0.15);
    }

    .w3ds-modal-close {
        position: absolute;
        top: 0.75rem;
        right: 0.75rem;
        background: none;
        border: none;
        font-size: 1.4rem;
        cursor: pointer;
        color: var(--color-text-maxcontrast, #767676);
        padding: 0.25rem 0.5rem;
        line-height: 1;
    }

    .w3ds-modal-close:hover {
        color: var(--color-main-text, #222);
    }

    .w3ds-modal-card h3 {
        margin: 0 0 0.5rem;
        font-size: 1.2rem;
        font-weight: 600;
    }

    .w3ds-modal-subtitle {
        color: var(--color-text-maxcontrast, #767676);
        font-size: 0.85rem;
        margin: 0 0 1.25rem;
        line-height: 1.4;
    }

    .w3ds-modal-qr {
        display: inline-block;
        padding: 0.75rem;
        background: #fff;
        border-radius: 12px;
        border: 1px solid var(--color-border, #ededed);
        line-height: 0;
        margin-bottom: 1rem;
    }

    .w3ds-modal-qr svg {
        width: 180px;
        height: 180px;
    }

    .w3ds-modal-status {
        font-size: 0.85rem;
        color: var(--color-text-maxcontrast, #767676);
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        margin-bottom: 1rem;
    }

    .w3ds-modal-status.w3ds-status-success {
        color: #46ba61;
        font-weight: 500;
    }

    .w3ds-modal-status.w3ds-status-error {
        color: #ff6b6b;
        font-weight: 500;
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
        padding: 0.5rem 1.25rem;
        background: var(--color-primary, #0082c9);
        color: var(--color-primary-text, #fff) !important;
        border-radius: 20px;
        text-decoration: none;
        font-size: 0.85rem;
        font-weight: 500;
        transition: opacity 0.15s;
    }

    .w3ds-deeplink:hover {
        opacity: 0.9;
    }
</style>
