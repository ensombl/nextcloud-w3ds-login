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
                You've signed in via W3DS. Set a Nextcloud password so desktop,
                mobile and WebDAV clients can authenticate too.
            </p>
        </div>

        <?php if ($error !== null): ?>
            <div class="w3ds-pwsetup-error" role="alert">
                <?php p($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="<?php p($submitUrl); ?>" class="w3ds-pwsetup-form">
            <input type="hidden" name="requesttoken" value="<?php p($requestToken); ?>" />

            <div class="w3ds-pwsetup-field">
                <label for="w3ds-pw-new">New password</label>
                <input
                    id="w3ds-pw-new"
                    type="password"
                    name="password"
                    autocomplete="new-password"
                    required
                    minlength="<?php p($minLength); ?>"
                    autofocus
                />
            </div>

            <div class="w3ds-pwsetup-field">
                <label for="w3ds-pw-confirm">Confirm password</label>
                <input
                    id="w3ds-pw-confirm"
                    type="password"
                    name="confirm"
                    autocomplete="new-password"
                    required
                    minlength="<?php p($minLength); ?>"
                />
            </div>

            <p class="w3ds-pwsetup-hint">
                Minimum <?php p($minLength); ?> characters.
            </p>

            <button type="submit" class="w3ds-pwsetup-submit">
                Save password and continue
            </button>
        </form>
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
        box-sizing: border-box;
    }

    .w3ds-pwsetup-header {
        text-align: center;
        margin-bottom: 1.75rem;
    }

    .w3ds-pwsetup-header h2 {
        margin: 0 0 0.5rem;
        font-size: 1.4rem;
        font-weight: 600;
        color: var(--color-main-text, #222);
    }

    .w3ds-pwsetup-subtitle {
        color: var(--color-text-maxcontrast, #767676);
        font-size: 0.9rem;
        margin: 0;
        line-height: 1.45;
    }

    .w3ds-pwsetup-error {
        background: #fde8e8;
        color: #8b1f1f;
        border-radius: 8px;
        padding: 0.75rem 1rem;
        margin-bottom: 1rem;
        font-size: 0.9rem;
        text-align: left;
    }

    .w3ds-pwsetup-form {
        display: flex;
        flex-direction: column;
        gap: 1rem;
        text-align: left;
    }

    .w3ds-pwsetup-field {
        display: flex;
        flex-direction: column;
        gap: 0.35rem;
    }

    .w3ds-pwsetup-field label {
        font-size: 0.85rem;
        color: var(--color-text-maxcontrast, #767676);
        font-weight: 500;
    }

    .w3ds-pwsetup-field input {
        padding: 0.65rem 0.8rem;
        border: 1px solid var(--color-border, #ededed);
        border-radius: 8px;
        font-size: 0.95rem;
        background: var(--color-main-background, #fff);
        color: var(--color-main-text, #222);
        box-sizing: border-box;
        width: 100%;
        transition: border-color 0.15s, box-shadow 0.15s;
    }

    .w3ds-pwsetup-field input:focus {
        outline: none;
        border-color: var(--color-primary, #0082c9);
        box-shadow: 0 0 0 3px rgba(0, 130, 201, 0.15);
    }

    .w3ds-pwsetup-hint {
        font-size: 0.8rem;
        color: var(--color-text-maxcontrast, #767676);
        margin: -0.25rem 0 0.25rem;
    }

    .w3ds-pwsetup-submit {
        margin-top: 0.25rem;
        padding: 0.75rem 1rem;
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
</style>
