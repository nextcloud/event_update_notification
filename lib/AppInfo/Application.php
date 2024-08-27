<?php
/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EventUpdateNotification\AppInfo;

use OCA\DAV\Events\CalendarObjectCreatedEvent;
use OCA\DAV\Events\CalendarObjectDeletedEvent;
use OCA\DAV\Events\CalendarObjectMovedToTrashEvent;
use OCA\DAV\Events\CalendarObjectUpdatedEvent;
use OCA\EventUpdateNotification\EventListener;
use OCA\EventUpdateNotification\Notifier;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Notification\IManager;

class Application extends App implements IBootstrap {
	public function __construct() {
		parent::__construct('event_update_notification');
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(CalendarObjectCreatedEvent::class, EventListener::class);
		$context->registerEventListener(CalendarObjectUpdatedEvent::class, EventListener::class);
		$context->registerEventListener(CalendarObjectDeletedEvent::class, EventListener::class);
		$context->registerEventListener(CalendarObjectMovedToTrashEvent::class, EventListener::class);
	}

	public function boot(IBootContext $context): void {
		$context->getServerContainer()->get(IManager::class)->registerNotifierService(Notifier::class);
	}
}
