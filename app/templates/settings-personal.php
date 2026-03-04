<?php

declare(strict_types=1);

/** @var array $_ */
$linkedW3id = $_['linkedW3id'];
$linkUrl = $_['linkUrl'];
$unlinkUrl = $_['unlinkUrl'];

?>

<div id="w3ds-settings" class="section">
    <h2>W3DS Login</h2>

    <?php if ($linkedW3id): ?>
        <p class="w3ds-settings-status">
            Your account is linked to
            <strong><?php p($linkedW3id); ?></strong>
        </p>
        <form method="post" action="<?php p($unlinkUrl); ?>">
            <input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>"/>
            <button type="submit" class="button">
                Unlink W3DS identity
            </button>
        </form>
    <?php else: ?>
        <p class="w3ds-settings-status">
            Link your W3DS identity to sign in without a password.
        </p>
        <a href="<?php p($linkUrl); ?>" class="button primary">
            Link W3DS identity
        </a>
    <?php endif; ?>
</div>

<style>
    #w3ds-settings .w3ds-settings-status {
        margin-bottom: 1rem;
        color: var(--color-text-maxcontrast, #767676);
    }

    #w3ds-settings .w3ds-settings-status strong {
        color: var(--color-main-text, #222);
    }
</style>
