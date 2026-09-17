<?php

declare(strict_types=1);

/** @var array $_ */

?>

<div class="section" id="w3ds-awareness-settings">
	<h2><?php p('W3DS chat sync'); ?></h2>

	<p class="settings-hint">
		<?php p('Incoming chats and messages are delivered by Awareness as a Service. Enter the credentials issued by its portal.'); ?>
	</p>

	<?php if ($_['configured'] && $_['consumerStatus'] !== ''): ?>
		<p>
			<?php p('Connected as'); ?>
			<strong><?php p($_['consumerName'] !== '' ? $_['consumerName'] : '—'); ?></strong>
			(<?php p($_['consumerStatus']); ?>)
		</p>
	<?php elseif ($_['configured']): ?>
		<p class="warning">
			<?php p('Credentials are set, but the service did not confirm them. Incoming messages will not arrive until it does.'); ?>
		</p>
	<?php else: ?>
		<p class="warning">
			<?php p('Not configured. Outgoing messages are still written to each sender\'s eVault, but nothing will arrive from other platforms.'); ?>
		</p>
	<?php endif; ?>

	<form method="post" action="<?php p($_['saveUrl']); ?>">
		<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken'] ?? ''); ?>">

		<p>
			<label for="w3ds-awareness-url"><?php p('Service URL'); ?></label><br>
			<input type="url" id="w3ds-awareness-url" name="baseUrl"
				value="<?php p($_['baseUrl']); ?>"
				placeholder="https://aaas.example.org" style="width: 26em;">
		</p>

		<p>
			<label for="w3ds-awareness-key"><?php p('API key'); ?></label><br>
			<input type="password" id="w3ds-awareness-key" name="apiKey"
				placeholder="<?php p($_['configured'] ? '••••••••  (leave blank to keep)' : 'aaas_…'); ?>"
				style="width: 26em;" autocomplete="off">
		</p>

		<p>
			<label for="w3ds-awareness-secret"><?php p('Webhook secret'); ?></label><br>
			<input type="password" id="w3ds-awareness-secret" name="webhookSecret"
				placeholder="<?php p('optional; leave blank to keep'); ?>"
				style="width: 26em;" autocomplete="off">
			<br>
			<em class="settings-hint">
				<?php p('Set the same value on the subscription to have deliveries signed. Without it, any request reaching the webhook URL is trusted.'); ?>
			</em>
		</p>

		<p>
			<em class="settings-hint">
				<?php p('Deliver webhooks to:'); ?> <code><?php p($_['webhookUrl']); ?></code>
			</em>
		</p>

		<input type="submit" class="button" value="<?php p('Save'); ?>">
	</form>
</div>
