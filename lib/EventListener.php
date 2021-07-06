<?php
declare(strict_types=1);
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

namespace OCA\EventUpdateNotification;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\DAV\Events\CalendarObjectCreatedEvent;
use OCA\DAV\Events\CalendarObjectDeletedEvent;
use OCA\DAV\Events\CalendarObjectMovedToTrashEvent;
use OCA\DAV\Events\CalendarObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Notification\IManager as INotificationManager;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Reader;
use Sabre\VObject\Recur\EventIterator;

class EventListener implements IEventListener {

	/** @var INotificationManager */
	protected $notificationManager;

	/** @var IGroupManager */
	protected $groupManager;

	/** @var IUserSession */
	protected $userSession;

	public function __construct(INotificationManager $notificationManager, IGroupManager $groupManager, IUserSession $userSession) {
		$this->notificationManager = $notificationManager;
		$this->groupManager = $groupManager;
		$this->userSession = $userSession;
	}

	/**
	 * @param Event $event
	 * @throws \Sabre\VObject\Recur\MaxInstancesExceededException
	 * @throws \Sabre\VObject\Recur\NoInstancesException
	 */
	public function handle(Event $event): void {
		if (!($event instanceof CalendarObjectCreatedEvent)
			&& !($event instanceof CalendarObjectUpdatedEvent)
			&& !($event instanceof CalendarObjectDeletedEvent)
			&& !($event instanceof CalendarObjectMovedToTrashEvent)) {
			return;
		}

		if ($event instanceof CalendarObjectCreatedEvent) {
			$subject = Notifier::SUBJECT_OBJECT_ADD;
		} else if ($event instanceof CalendarObjectUpdatedEvent) {
			$subject = Notifier::SUBJECT_OBJECT_UPDATE;
		} else {
			if ($event instanceof CalendarObjectDeletedEvent
				&& substr($event->getObjectData()['uri'], -12) === '-deleted.ics') {
				return;
			}
			$subject = Notifier::SUBJECT_OBJECT_DELETE;
		}

		$this->onTouchCalendarObject($subject, $event->getCalendarData(), $event->getShares(), $event->getObjectData());
	}

	/**
	 * Creates activities when a calendar object was created/updated/deleted
	 *
	 * @param string $action
	 * @param array $calendarData
	 * @param array $shares
	 * @param array $objectData
	 * @throws \Sabre\VObject\Recur\MaxInstancesExceededException
	 * @throws \Sabre\VObject\Recur\NoInstancesException
	 */
	public function onTouchCalendarObject(string $action, array $calendarData, array $shares, array $objectData): void {
		if (!isset($calendarData['principaluri'])) {
			return;
		}

		$principal = explode('/', $calendarData['principaluri']);
		$owner = array_pop($principal);

		$currentUser = $this->userSession->getUser();
		if ($currentUser instanceof IUser) {
			$currentUser = $currentUser->getUID();
		} else {
			$currentUser = $owner;
		}

		$object = $this->getObjectNameAndType($objectData);
		if ($object === false || $object['type'] !== 'event') {
			// For now we only support events
			return;
		}

		$classification = $objectData['classification'] ?? CalDavBackend::CLASSIFICATION_PUBLIC;
		$action .= '_' . $object['type'];
		list ($dateTime, $hasTime) = $this->getNearestDateTime($objectData['calendardata']);
		$now = new \DateTime();

		if ($dateTime < $now) {
			// Do not notify about past events
			return;
		}

		$notification = $this->notificationManager->createNotification();
		$notification->setApp('event_update_notification')
			->setObject('calendar', (string) $calendarData['id'])
			->setUser($currentUser)
			->setDateTime($now)
			->setMessage('event_update_notification', [
				'start' => $dateTime->format(\DateTime::ATOM),
				'hasTime' => $hasTime,
			]);

		$users = $this->getUsersForShares($shares, $owner);

		foreach ($users as $user) {
			if ($user === $currentUser) {
				continue;
			}

			if ($classification === CalDavBackend::CLASSIFICATION_PRIVATE && $user !== $owner) {
				// Private events are only available to the owner
				continue;
			}

			$isClassified = $classification === CalDavBackend::CLASSIFICATION_CONFIDENTIAL && $user !== $owner;

			$notification->setUser($user)
				->setSubject($action,
					[
						'actor' => $currentUser,
						'calendar' => [
							'id' => (int) $calendarData['id'],
							'uri' => $calendarData['uri'],
							'name' => $calendarData['{DAV:}displayname'],
						],
						'object' => [
							'id' => $object['id'],
							'name' => $isClassified ? 'Busy' : $object['name'],
							'classified' => $isClassified,
						],
					]
				);
			$this->notificationManager->notify($notification);
		}
	}

	/**
	 * @param array $objectData
	 * @return string[]|bool
	 */
	protected function getObjectNameAndType(array $objectData) {
		$vObject = Reader::read($objectData['calendardata']);
		$component = $componentType = null;
		foreach($vObject->getComponents() as $component) {
			if (\in_array($component->name, ['VEVENT', 'VTODO'])) {
				$componentType = $component->name;
				break;
			}
		}

		if (!$componentType) {
			// Calendar objects must have a VEVENT or VTODO component
			return false;
		}

		if ($componentType === 'VEVENT') {
			return ['id' => (string) $component->UID, 'name' => (string) $component->SUMMARY, 'type' => 'event'];
		}
		return ['id' => (string) $component->UID, 'name' => (string) $component->SUMMARY, 'type' => 'todo', 'status' => (string) $component->STATUS];
	}

	/**
	 * Get all users that have access to a given calendar
	 *
	 * @param array $shares
	 * @param string $owner
	 * @return string[]
	 */
	protected function getUsersForShares(array $shares, string $owner): array {
		$users = [$owner];
		$groups = [];
		foreach ($shares as $share) {
			$prinical = explode('/', $share['{http://owncloud.org/ns}principal']);
			if ($prinical[1] === 'users') {
				$users[] = $prinical[2];
			} else if ($prinical[1] === 'groups') {
				$groups[] = $prinical[2];
			}
		}

		if (!empty($groups)) {
			foreach ($groups as $gid) {
				$group = $this->groupManager->get($gid);
				if ($group instanceof IGroup) {
					foreach ($group->getUsers() as $user) {
						$users[] = $user->getUID();
					}
				}
			}
		}

		return array_unique($users);
	}

	/**
	 * @param string $data
	 * @return array
	 * @throws \Sabre\VObject\Recur\MaxInstancesExceededException
	 * @throws \Sabre\VObject\Recur\NoInstancesException
	 */
	protected function getNearestDateTime(string $data): array {
		$vObject = \Sabre\VObject\Reader::read($data);
		/** @var VEvent $component */
		$component = $vObject->VEVENT;

		if (!isset($component->RRULE)) {
			return [$component->DTSTART->getDateTime(), $component->DTSTART->hasTime()];
		}

		$it = new EventIterator($vObject, (string)$component->UID);
		$start = $it->getDtStart();
		$today = new \DateTime();
		while ($it->valid() && $start < $today) {
			$start = $it->getDtStart();
			$it->next();
		}

		return [$start, $component->DTSTART->hasTime()];
	}
}
