<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\AppInfo;

use OCA\W3dsLogin\Provider\W3dsLoginProvider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
    public const APP_ID = 'w3ds_login';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
        $context->registerAlternativeLogin(W3dsLoginProvider::class);
    }

    public function boot(IBootContext $context): void {
    }
}
