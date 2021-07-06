<?php
/**
 * @copyright Copyright (c) 2018, Joas Schilling <coding@schilljs.com>
 *
 * @author Joas Schilling <coding@schilljs.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
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
