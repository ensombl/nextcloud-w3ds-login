<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Command;

use OCA\W3dsLogin\Service\AwarenessClient;
use OCA\W3dsLogin\Service\AwarenessPoller;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Polls the awareness service every few seconds, for as long as it runs.
 *
 * Background jobs only run as often as cron does -- every five minutes on
 * most servers -- which is far too slow for chat. Run this alongside
 * Nextcloud instead, as a systemd service or a container, and new messages
 * arrive within the interval. It exits after --max-runtime so its supervisor
 * restarts it with fresh memory; the cron job keeps polling if it is not
 * running at all.
 */
class AwarenessPollCommand extends Command {
	private bool $stopping = false;

	public function __construct(
		private AwarenessPoller $poller,
		private AwarenessClient $awarenessClient,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('w3ds_login:awareness-poll')
			->setDescription('Poll the awareness service for incoming W3DS chats every few seconds.')
			->addOption('interval', 'i', InputOption::VALUE_REQUIRED, 'Seconds between polls (minimum 5).', '30')
			->addOption('once', null, InputOption::VALUE_NONE, 'Poll once and exit.')
			->addOption('max-runtime', null, InputOption::VALUE_REQUIRED, 'Exit after this many seconds, for the supervisor to restart (0 = never).', '3600');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$interval = max(5, (int)$input->getOption('interval'));
		$maxRuntime = max(0, (int)$input->getOption('max-runtime'));
		$startedAt = time();

		if (!$this->awarenessClient->isConfigured()) {
			$output->writeln('<comment>The awareness service is not configured (Administration settings → W3DS); waiting for it.</comment>');
		}

		if (function_exists('pcntl_async_signals') && defined('SIGTERM') && defined('SIGINT')) {
			pcntl_async_signals(true);
			foreach ([(int)constant('SIGTERM'), (int)constant('SIGINT')] as $signal) {
				pcntl_signal($signal, function (): void {
					$this->stopping = true;
				});
			}
		}

		do {
			$began = microtime(true);
			try {
				$applied = $this->poller->poll();
				if ($applied > 0 || $output->isVerbose()) {
					$output->writeln(sprintf(
						'[%s] %s',
						date('c'),
						$applied === null ? 'skipped (not configured, or another poll is running)' : 'applied ' . $applied . ' packet(s)',
					));
				}
			} catch (\Throwable $e) {
				$output->writeln(sprintf('<error>[%s] poll failed: %s</error>', date('c'), $e->getMessage()));
			}

			if ($input->getOption('once')) {
				break;
			}
			if ($maxRuntime > 0 && time() - $startedAt >= $maxRuntime) {
				break;
			}

			$this->sleep(max(0.0, (float)$interval - (microtime(true) - $began)));
		} while (!$this->stopping);

		return 0;
	}

	/** In short steps, so a stop signal is acted on promptly. */
	private function sleep(float $seconds): void {
		$until = microtime(true) + $seconds;
		while (!$this->stopping && microtime(true) < $until) {
			usleep((int)min(500000.0, ($until - microtime(true)) * 1000000.0));
		}
	}
}
