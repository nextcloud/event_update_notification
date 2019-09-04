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
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeFormatter;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;

class Notifier implements INotifier {

	const SUBJECT_OBJECT_ADD = 'object_add';
	const SUBJECT_OBJECT_UPDATE = 'object_update';
	const SUBJECT_OBJECT_DELETE = 'object_delete';

	/** @var IFactory */
	protected $languageFactory;
	/** @var ITimeFactory */
	protected $timeFactory;
	/** @var IL10N */
	protected $l;
	/** @var IURLGenerator */
	protected $url;
	/** @var IUserManager */
	protected $userManager;
	/** @var INotificationManager */
	protected $notificationManager;
	/** @var IDateTimeFormatter */
	protected $dateTimeFormatter;

	/** @var string[]  */
	protected $userDisplayNames = [];

	public function __construct(IFactory $languageFactory,
								ITimeFactory $timeFactory,
								IURLGenerator $url,
								IUserManager $userManager,
								INotificationManager $notificationManager,
								IDateTimeFormatter $dateTimeFormatter) {
		$this->languageFactory = $languageFactory;
		$this->timeFactory = $timeFactory;
		$this->url = $url;
		$this->userManager = $userManager;
		$this->notificationManager = $notificationManager;
		$this->dateTimeFormatter = $dateTimeFormatter;
	}

	/**
	 * Identifier of the notifier, only use [a-z0-9_]
	 *
	 * @return string
	 * @since 17.0.0
	 */
	public function getID(): string {
		return 'event_update_notification';
	}

	/**
	 * Human readable name describing the notifier
	 *
	 * @return string
	 * @since 17.0.0
	 */
	public function getName(): string {
		return $this->languageFactory->get('event_update_notification')->t('Calendar event update notifications');
	}

	/**
	 * @param INotification $notification
	 * @param string $languageCode The code of the language that should be used to prepare the notification
	 * @return INotification
	 * @throws \InvalidArgumentException When the notification was not prepared by a notifier
	 * @since 9.0.0
	 */
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== 'event_update_notification') {
			throw new \InvalidArgumentException('Invalid app');
		}

		$this->l = $this->languageFactory->get('event_update_notification', $languageCode);

		$notification->setIcon($this->url->getAbsoluteURL($this->url->imagePath('core', 'places/calendar.svg')));

		if ($notification->getSubject() === self::SUBJECT_OBJECT_ADD . '_event') {
			$subject = $this->l->t('{actor} created {event} in {calendar}');
		} else if ($notification->getSubject() === self::SUBJECT_OBJECT_DELETE . '_event') {
			$subject = $this->l->t('{actor} deleted {event} from {calendar}');
		} else if ($notification->getSubject() === self::SUBJECT_OBJECT_UPDATE . '_event') {
			$subject = $this->l->t('{actor} updated {event} in {calendar}');
		} else {
			throw new AlreadyProcessedException();
		}

		$params = $notification->getMessageParameters();
		$start = \DateTime::createFromFormat(\DateTime::ATOM, $params['start']);

		if ($start < $this->timeFactory->getDateTime()) {
			throw new AlreadyProcessedException();
		}

		if (!empty($params['hasTime'])) {
			$notification->setParsedMessage(
				$this->dateTimeFormatter->formatDateTime(
					$start,
					'long', 'medium', null,
					$this->l
				)
			);
		} else {
			$notification->setParsedMessage(
				$this->dateTimeFormatter->formatDate(
					$start,
					'long', null,
					$this->l
				)
			);
		}

		$parsedParameters = $this->getParameters($notification);
		$this->setSubjects($notification, $subject, $parsedParameters);

		return $notification;
	}

	protected function setSubjects(INotification $notification, string $subject, array $parameters) {
		$placeholders = $replacements = [];
		foreach ($parameters as $placeholder => $parameter) {
			$placeholders[] = '{' . $placeholder . '}';
			$replacements[] = $parameter['name'];
		}

		$notification->setParsedSubject(str_replace($placeholders, $replacements, $subject))
			->setRichSubject($subject, $parameters);
	}

	protected function getParameters(INotification $notification): array {
		$subject = $notification->getSubject();
		$parameters = $notification->getSubjectParameters();

		switch ($subject) {
			case self::SUBJECT_OBJECT_ADD . '_event':
			case self::SUBJECT_OBJECT_DELETE . '_event':
			case self::SUBJECT_OBJECT_UPDATE . '_event':
				return [
					'actor' => $this->generateUserParameter($parameters['actor']),
					'calendar' => $this->generateCalendarParameter($parameters['calendar']),
					'event' => $this->generateObjectParameter($parameters['object']),
				];
		}

		throw new \InvalidArgumentException('Invalid subject');
	}

	protected function generateObjectParameter(array $eventData): array {
		if (!\is_array($eventData) || !isset($eventData['id'], $eventData['name'])) {
			throw new \InvalidArgumentException(' Invalid data');
		}

		if (!empty($eventData['classified'])) {
			// Busy is stored untranslated in the database, so we translate it here.
			$eventData['name'] = $this->l->t('Busy');
		}

		return [
			'type' => 'calendar-event',
			'id' => $eventData['id'],
			'name' => $eventData['name'],
		];
	}

	protected function generateCalendarParameter(array $data): array {
		if ($data['uri'] === CalDavBackend::PERSONAL_CALENDAR_URI &&
			$data['name'] === CalDavBackend::PERSONAL_CALENDAR_NAME) {
			return [
				'type' => 'calendar',
				'id' => $data['id'],
				'name' => $this->l->t('Personal'),
			];
		}

		return [
			'type' => 'calendar',
			'id' => $data['id'],
			'name' => $data['name'],
		];
	}

	protected function generateUserParameter(string $uid): array {
		if (!isset($this->userDisplayNames[$uid])) {
			$this->userDisplayNames[$uid] = $this->getUserDisplayName($uid);
		}

		return [
			'type' => 'user',
			'id' => $uid,
			'name' => $this->userDisplayNames[$uid],
		];
	}

	protected function getUserDisplayName(string $uid): string {
		$user = $this->userManager->get($uid);
		if ($user instanceof IUser) {
			return $user->getDisplayName();
		}
		return $uid;
	}
}
