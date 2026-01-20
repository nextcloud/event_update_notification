<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EventUpdateNotification;

use OCA\DAV\CalDAV\CalDavBackend;
use OCP\Calendar\Events\CalendarObjectCreatedEvent;
use OCP\Calendar\Events\CalendarObjectDeletedEvent;
use OCP\Calendar\Events\CalendarObjectMovedToTrashEvent;
use OCP\Calendar\Events\CalendarObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Reader;
use Sabre\VObject\Recur\EventIterator;

/**
 * @template-implements IEventListener<Event>
 */
class EventListener implements IEventListener {
	public function __construct(
		protected INotificationManager $notificationManager,
		protected IGroupManager $groupManager,
		protected IUserSession $userSession,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!($event instanceof CalendarObjectCreatedEvent)
			&& !($event instanceof CalendarObjectUpdatedEvent)
			&& !($event instanceof CalendarObjectDeletedEvent)
			&& !($event instanceof CalendarObjectMovedToTrashEvent)) {
			return;
		}

		if ($event instanceof CalendarObjectCreatedEvent) {
			$subject = Notifier::SUBJECT_OBJECT_ADD;
		} elseif ($event instanceof CalendarObjectUpdatedEvent) {
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
		[$dateTime, $hasTime] = $this->getNearestDateTime($objectData['calendardata']);
		$now = new \DateTime();

		if ($dateTime < $now) {
			// Do not notify about past events
			return;
		}

		$notification = $this->notificationManager->createNotification();
		$notification->setApp('event_update_notification')
			->setObject('calendar', (string)$calendarData['id'])
			->setUser($currentUser)
			->setDateTime($now)
			->setMessage('event_update_notification', [
				'start' => $dateTime->format(\DateTime::ATOM),
				'hasTime' => $hasTime,
			]);

		$users = $this->getUsersForShares($shares, $owner, (int)$calendarData['id']);

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
							'id' => (int)$calendarData['id'],
							'uri' => $calendarData['uri'],
							'name' => $calendarData['{DAV:}displayname'],
						],
						'object' => [
							'id' => $object['id'],
							'name' => $isClassified ? 'Busy' : $object['name'],
							'classified' => $isClassified,
							'link' => [
								'owner' => $owner,
								'calendar_uri' => $calendarData['uri'],
								'object_uri' => $objectData['uri'],
							],
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
		foreach ($vObject->getComponents() as $component) {
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
			return ['id' => (string)$component->UID, 'name' => (string)$component->SUMMARY, 'type' => 'event'];
		}
		return ['id' => (string)$component->UID, 'name' => (string)$component->SUMMARY, 'type' => 'todo', 'status' => (string)$component->STATUS];
	}

	/**
	 * Get all users that have access to a given calendar
	 *
	 * @param array $shares
	 * @param string $owner
	 * @return string[]
	 */
	protected function getUsersForShares(array $shares, string $owner, int $calendarId): array {
		$users = [$owner];
		$groups = [];
		foreach ($shares as $share) {
			$principal = explode('/', $share['{http://owncloud.org/ns}principal']);
			if ($principal[1] === 'users') {
				$users[] = $principal[2];
			} elseif ($principal[1] === 'groups') {
				$groups[] = $principal[2];
			}
		}

		$groupAddedUsers = false;
		if (!empty($groups)) {
			foreach ($groups as $gid) {
				$group = $this->groupManager->get($gid);
				if ($group instanceof IGroup) {
					foreach ($group->getUsers() as $user) {
						$groupAddedUsers = true;
						$users[] = $user->getUID();
					}
				}
			}
		}

		$users = array_unique($users);

		if (!$groupAddedUsers) {
			return $users;
		}

		/** @var \OCA\DAV\CalDAV\Sharing\Service $service */
		$service = \OCP\Server::get(\OCA\DAV\CalDAV\Sharing\Service::class);
		$unshares = $service->getUnshares($calendarId);
		$usersToRemove = [];
		foreach ($unshares as $unshare) {

			$principal = explode('/', $unshare['principaluri']);
			if ($principal[1] === 'users') {
				$usersToRemove[] = $principal[2];
			}
		}

		$users = array_diff($users, $usersToRemove);

		return $users;
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
