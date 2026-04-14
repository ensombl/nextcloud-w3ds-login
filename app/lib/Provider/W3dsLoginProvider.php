<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Provider;

use OCA\W3dsLogin\AppInfo\Application;
use OCP\Authentication\IAlternativeLogin;
use OCP\IURLGenerator;

class W3dsLoginProvider implements IAlternativeLogin {
	public function __construct(
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getLabel(): string {
		return 'Login with W3DS';
	}

	public function getLink(): string {
		return $this->urlGenerator->linkToRoute(Application::APP_ID . '.auth.offer');
	}

	public function getClass(): string {
		return 'w3ds-login';
	}

	public function load(): void {
	}
}
