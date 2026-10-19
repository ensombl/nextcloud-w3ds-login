<?php

declare(strict_types=1);

use OCP\Util;

Util::addScript('w3ds_login', 'admin-settings');
Util::addStyle('w3ds_login', 'admin-settings');

?>

<div id="w3ds-admin" class="section">
    <h2>Incoming W3DS chats</h2>
    <p class="settings-hint">
        Messages people send from other W3DS platforms reach this server through the
        awareness service. Without a service URL and an approved API key, outgoing
        messages still sync, but nothing comes in.
    </p>

    <p id="w3ds-admin-state" class="w3ds-admin-state" aria-live="polite"></p>

    <form id="w3ds-admin-form" class="w3ds-admin-form" novalidate>
        <label for="w3ds-admin-base-url">Awareness service URL</label>
        <input id="w3ds-admin-base-url" name="baseUrl" type="url" placeholder="https://aaas.w3ds.metastate.foundation" autocomplete="off">

        <label for="w3ds-admin-api-key">API key</label>
        <input id="w3ds-admin-api-key" name="apiKey" type="password" autocomplete="new-password">
        <span class="w3ds-admin-field-hint" id="w3ds-admin-api-key-hint"></span>

        <label for="w3ds-admin-webhook-secret">Webhook secret <span class="w3ds-admin-optional">(optional)</span></label>
        <div class="w3ds-admin-row">
            <input id="w3ds-admin-webhook-secret" name="webhookSecret" type="password" autocomplete="new-password">
            <button type="button" id="w3ds-admin-generate">Generate</button>
        </div>
        <span class="w3ds-admin-field-hint" id="w3ds-admin-webhook-hint"></span>

        <details class="w3ds-admin-advanced">
            <summary>Advanced</summary>
            <label for="w3ds-admin-registry-url">W3DS registry URL</label>
            <input id="w3ds-admin-registry-url" name="registryUrl" type="url" placeholder="https://registry.w3ds.metastate.foundation" autocomplete="off">
            <span class="w3ds-admin-field-hint">Leave empty for the production registry.</span>
        </details>

        <div class="w3ds-admin-actions">
            <button type="submit" class="primary" id="w3ds-admin-save">Save</button>
            <button type="button" id="w3ds-admin-test">Test connection</button>
        </div>
        <p id="w3ds-admin-message" class="w3ds-admin-message" role="status" aria-live="polite"></p>
    </form>

    <h3>Delivery</h3>
    <dl class="w3ds-admin-facts">
        <dt>Webhook URL</dt>
        <dd><code id="w3ds-admin-webhook-url"></code></dd>
        <dt>Background jobs</dt>
        <dd id="w3ds-admin-cron"></dd>
    </dl>
</div>
