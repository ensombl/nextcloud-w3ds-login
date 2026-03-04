<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Settings;

use OCA\W3dsLogin\AppInfo\Application;
use OCA\W3dsLogin\Service\UserProvisioningService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Settings\ISettings;

class PersonalSettings implements ISettings {
    public function __construct(
        private UserProvisioningService $provisioningService,
        private IUserSession $userSession,
        private IURLGenerator $urlGenerator,
    ) {
    }

    public function getForm(): TemplateResponse {
        $user = $this->userSession->getUser();
        $linkedW3id = null;
        $linkUrl = '';
        $unlinkUrl = '';

        if ($user !== null) {
            $linkedW3id = $this->provisioningService->getLinkedW3id($user->getUID());
            $linkUrl = $this->urlGenerator->linkToRoute(
                Application::APP_ID . '.settings.linkOffer',
            );
            $unlinkUrl = $this->urlGenerator->linkToRoute(
                Application::APP_ID . '.settings.unlink',
            );
        }

        return new TemplateResponse(
            Application::APP_ID,
            'settings-personal',
            [
                'linkedW3id' => $linkedW3id,
                'linkUrl' => $linkUrl,
                'unlinkUrl' => $unlinkUrl,
            ],
        );
    }

    public function getSection(): string {
        return 'security';
    }

    public function getPriority(): int {
        return 80;
    }
}
