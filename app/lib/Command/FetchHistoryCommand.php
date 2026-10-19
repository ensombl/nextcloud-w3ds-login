<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Command;

use OCA\W3dsLogin\BackgroundJob\AwarenessBackfillJob;
use OCA\W3dsLogin\Db\W3dsMappingMapper;
use OCA\W3dsLogin\Service\UserProvisioningService;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Fetch W3DS history now, for one person or everyone who signs in here,
 * instead of waiting for a background job.
 */
class FetchHistoryCommand extends Command {
	public function __construct(
		private AwarenessBackfillJob $job,
		private W3dsMappingMapper $mappings,
		private UserProvisioningService $provisioning,
		private IUserManager $userManager,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('w3ds_login:fetch-history')
			->setDescription('Fetch W3DS conversations and messages now.')
			->addArgument('ename', InputArgument::OPTIONAL, 'The eName to fetch for; everyone who signs in here when omitted.')
			->addOption('full', null, InputOption::VALUE_NONE, 'Read the whole history window, not only what arrived since the last fetch.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$ename = $input->getArgument('ename');
		$w3ids = is_string($ename) && $ename !== '' ? ['@' . ltrim($ename, '@')] : $this->peopleWhoSignIn();

		foreach ($w3ids as $w3id) {
			$output->writeln(sprintf('Fetching %s%s…', $w3id, $input->getOption('full') ? ' (full)' : ''));
			$this->job->runNow(['w3id' => $w3id, 'force' => true, 'full' => (bool)$input->getOption('full')]);
		}
		$output->writeln(sprintf('<info>Fetched history for %d identit%s.</info>', count($w3ids), count($w3ids) === 1 ? 'y' : 'ies'));

		return 0;
	}

	/**
	 * Linked accounts someone actually uses: not the ones this app made for
	 * peers it only shows as senders.
	 *
	 * @return list<string>
	 */
	private function peopleWhoSignIn(): array {
		$w3ids = [];
		foreach ($this->mappings->findAll() as $mapping) {
			$uid = $mapping->getNcUid();
			if (!$this->provisioning->isAppProvisioned($uid) || ($this->userManager->get($uid)?->getLastLogin() ?? 0) > 0) {
				$w3ids[] = $mapping->getW3id();
			}
		}

		return $w3ids;
	}
}
