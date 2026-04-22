<?php

declare(strict_types=1);

/** @var array $_ */
$error = $_['error'] ?? null;
$minLength = (int)($_['minLength'] ?? 10);
$submitUrl = $_['submitUrl'] ?? '';
$requestToken = \OCP\Util::callRegister();

?>

<div class="w3ds-pwsetup-page">
    <div class="w3ds-pwsetup-card">
        <div class="w3ds-pwsetup-header">
            <h2>Set a Nextcloud password</h2>
            <p class="w3ds-pwsetup-subtitle">
                You've signed in via W3DS. Please set a Nextcloud password
                so desktop / mobile / WebDAV clients can authenticate too.
            </p>
        </div>

        <?php if ($error !== null): ?>
            <div class="w3ds-pwsetup-error">
                <?php p($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="<?php p($submitUrl); ?>" class="w3ds-pwsetup-form">
            <input type="hidden" name="requesttoken" value="<?php p($requestToken); ?>" />

            <label class="w3ds-pwsetup-label">
                <span>New password</span>
                <input
                    type="password"
                    name="password"
                    autocomplete="new-password"
                    required
                    minlength="<?php p($minLength); ?>"
                    autofocus
                />
            </label>

            <label class="w3ds-pwsetup-label">
                <span>Confirm password</span>
                <input
                    type="password"
                    name="confirm"
                    autocomplete="new-password"
                    required
                    minlength="<?php p($minLength); ?>"
                />
            </label>

            <button type="submit" class="w3ds-pwsetup-submit">
                Save password and continue
            </button>
        </form>

        <p class="w3ds-pwsetup-hint">
            Minimum <?php p($minLength); ?> characters.
        </p>
    </div>
</div>

<style>
    .w3ds-pwsetup-page {
        display: flex;
        justify-content: center;
        align-items: center;
        min-height: 60vh;
        padding: 2rem;
    }

    .w3ds-pwsetup-card {
        background: var(--color-main-background, #fff);
        border-radius: 16px;
        box-shadow: 0 2px 16px rgba(0, 0, 0, 0.08);
        padding: 2.5rem;
        max-width: 420px;
        width: 100%;
    }

    .w3ds-pwsetup-header h2 {
        margin: 0 0 0.5rem;
        font-size: 1.4rem;
        font-weight: 600;
        color: var(--color-main-text, #222);
        text-align: center;
    }

    .w3ds-pwsetup-subtitle {
        color: var(--color-text-maxcontrast, #767676);
        font-size: 0.9rem;
        margin: 0 0 1.5rem;
        line-height: 1.4;
        text-align: center;
    }

    .w3ds-pwsetup-error {
        background: #fde8e8;
        color: #8b1f1f;
        border-radius: 8px;
        padding: 0.75rem 1rem;
        margin-bottom: 1rem;
        font-size: 0.9rem;
    }

    .w3ds-pwsetup-form {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    .w3ds-pwsetup-label {
        display: flex;
        flex-direction: column;
        gap: 0.35rem;
        font-size: 0.85rem;
        color: var(--color-text-maxcontrast, #767676);
    }

    .w3ds-pwsetup-label input {
        padding: 0.6rem 0.75rem;
        border: 1px solid var(--color-border, #ededed);
        border-radius: 8px;
        font-size: 0.95rem;
        background: var(--color-main-background, #fff);
        color: var(--color-main-text, #222);
    }

    .w3ds-pwsetup-submit {
        margin-top: 0.5rem;
        padding: 0.7rem 1rem;
        background: var(--color-primary, #0082c9);
        color: var(--color-primary-text, #fff);
        border: none;
        border-radius: 20px;
        font-size: 0.95rem;
        font-weight: 500;
        cursor: pointer;
        transition: opacity 0.15s;
    }

    .w3ds-pwsetup-submit:hover {
        opacity: 0.9;
    }

    .w3ds-pwsetup-hint {
        text-align: center;
        font-size: 0.8rem;
        color: var(--color-text-maxcontrast, #767676);
        margin: 1rem 0 0;
    }
</style>
