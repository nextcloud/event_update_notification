<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EventUpdateNotification\AppInfo;

use OCA\EventUpdateNotification\EventListener;
use OCA\EventUpdateNotification\Notifier;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Calendar\Events\CalendarObjectCreatedEvent;
use OCP\Calendar\Events\CalendarObjectDeletedEvent;
use OCP\Calendar\Events\CalendarObjectMovedToTrashEvent;
use OCP\Calendar\Events\CalendarObjectUpdatedEvent;
use OCP\Notification\IManager;

class Application extends App implements IBootstrap {
	public function __construct() {
		parent::__construct('event_update_notification');
	}

	#[\Override]
	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(CalendarObjectCreatedEvent::class, EventListener::class);
		$context->registerEventListener(CalendarObjectUpdatedEvent::class, EventListener::class);
		$context->registerEventListener(CalendarObjectDeletedEvent::class, EventListener::class);
		$context->registerEventListener(CalendarObjectMovedToTrashEvent::class, EventListener::class);
	}

	#[\Override]
	public function boot(IBootContext $context): void {
		$context->getServerContainer()->get(IManager::class)->registerNotifierService(Notifier::class);
	}
}
