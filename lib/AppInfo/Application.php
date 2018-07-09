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

use OCA\EventUpdateNotification\Backend;
use OCA\EventUpdateNotification\Notifier;
use OCP\AppFramework\App;
use Symfony\Component\EventDispatcher\GenericEvent;

class Application extends App {
	public function __construct() {
		parent::__construct('event_update_notification');
	}

	public function register() {
		$this->registerEventListener();
		$this->registerNotifier();
	}

	public function registerEventListener() {
		$dispatcher = $this->getContainer()->getServer()->getEventDispatcher();
		$listener = function(GenericEvent $event, $eventName) {
			/** @var Backend $backend */
			$backend = $this->getContainer()->query(Backend::class);

			$subject = Notifier::SUBJECT_OBJECT_ADD;
			if ($eventName === '\OCA\DAV\CalDAV\CalDavBackend::updateCalendarObject') {
				$subject = Notifier::SUBJECT_OBJECT_UPDATE;
			} else if ($eventName === '\OCA\DAV\CalDAV\CalDavBackend::deleteCalendarObject') {
				$subject = Notifier::SUBJECT_OBJECT_DELETE;
			}
			$backend->onTouchCalendarObject(
				$subject,
				$event->getArgument('calendarData'),
				$event->getArgument('shares'),
				$event->getArgument('objectData')
			);
		};
		$dispatcher->addListener('\OCA\DAV\CalDAV\CalDavBackend::createCalendarObject', $listener);
		$dispatcher->addListener('\OCA\DAV\CalDAV\CalDavBackend::updateCalendarObject', $listener);
		$dispatcher->addListener('\OCA\DAV\CalDAV\CalDavBackend::deleteCalendarObject', $listener);
	}

	protected function registerNotifier() {
		$this->getContainer()->getServer()->getNotificationManager()->registerNotifier(function() {
			return $this->getContainer()->query(Notifier::class);
		}, function() {
			$l = $this->getContainer()->getServer()->getL10NFactory()->get('event_update_notification');
			return [
				'id' => 'event_update_notification',
				'name' => $l->t('Calendar event updates'),
			];
		});
	}
}
