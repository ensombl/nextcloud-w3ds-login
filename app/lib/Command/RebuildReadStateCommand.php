<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Command;

use OCA\W3dsLogin\Service\ReadStateService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reset who has read what in synced conversations to what the messages'
 * read receipts say.
 */
class RebuildReadStateCommand extends Command {
	public function __construct(
		private ReadStateService $readState,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('w3ds_login:rebuild-read-state')
			->setDescription('Set every read marker in synced Talk conversations from the read receipts on their messages.')
			->addOption('room', null, InputOption::VALUE_REQUIRED, 'Only this conversation (its token).');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$only = $input->getOption('room');
		$result = $this->readState->rebuild(
			is_string($only) && $only !== '' ? $only : null,
			static fn (string $vault) => $output->writeln('  reading ' . $vault, OutputInterface::VERBOSITY_VERBOSE),
		);

		$output->writeln(sprintf(
			'<info>%d conversation(s) rebuilt, %d read marker(s) changed.</info>',
			$result['rooms'],
			$result['markers'],
		));
		if ($result['skipped'] > 0) {
			$output->writeln(sprintf(
				'<comment>%d conversation(s) left as they were: some of their messages could not be read. Run again later.</comment>',
				$result['skipped'],
			));
		}

		return 0;
	}
}
