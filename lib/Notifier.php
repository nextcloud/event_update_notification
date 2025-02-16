<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EventUpdateNotification;

use OCA\DAV\CalDAV\CalDavBackend;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
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
use OCP\Notification\UnknownNotificationException;

class Notifier implements INotifier {
	public const SUBJECT_OBJECT_ADD = 'object_add';
	public const SUBJECT_OBJECT_UPDATE = 'object_update';
	public const SUBJECT_OBJECT_DELETE = 'object_delete';

	/** @var string[] */
	protected array $userDisplayNames = [];

	public function __construct(
		protected IFactory $languageFactory,
		protected IL10N $l,
		protected ITimeFactory $timeFactory,
		protected IURLGenerator $url,
		protected IUserManager $userManager,
		protected INotificationManager $notificationManager,
		protected IAppManager $appManager,
		protected IConfig $config,
		protected IDateTimeFormatter $dateTimeFormatter,
	) {
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
	 * Human-readable name describing the notifier
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
	 * @throws UnknownNotificationException When the notification was not prepared by a notifier
	 * @since 9.0.0
	 */
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== 'event_update_notification') {
			throw new UnknownNotificationException('Invalid app');
		}

		$this->l = $this->languageFactory->get('event_update_notification', $languageCode);

		$notification->setIcon($this->url->getAbsoluteURL($this->url->imagePath('core', 'places/calendar.svg')));

		if ($notification->getSubject() === self::SUBJECT_OBJECT_ADD . '_event') {
			$subject = $this->l->t('{actor} created {event} in {calendar}');
		} elseif ($notification->getSubject() === self::SUBJECT_OBJECT_DELETE . '_event') {
			$subject = $this->l->t('{actor} deleted {event} from {calendar}');
		} elseif ($notification->getSubject() === self::SUBJECT_OBJECT_UPDATE . '_event') {
			$subject = $this->l->t('{actor} updated {event} in {calendar}');
		} else {
			throw new AlreadyProcessedException();
		}

		$params = $notification->getMessageParameters();
		$start = \DateTime::createFromFormat(\DateTime::ATOM, $params['start']);

		if ($start < $this->timeFactory->getDateTime()) {
			throw new AlreadyProcessedException();
		}

		$timeZone = $this->getUserTimezone($notification->getUser());
		if (!empty($params['hasTime'])) {
			$notification->setParsedMessage(
				$this->dateTimeFormatter->formatDateTime(
					$start,
					'long', 'medium', $timeZone,
					$this->l
				)
			);
		} else {
			$notification->setParsedMessage(
				$this->dateTimeFormatter->formatDate(
					$start,
					'long', $timeZone,
					$this->l
				)
			);
		}

		$parsedParameters = $this->getParameters($notification);
		$this->setSubjects($notification, $subject, $parsedParameters);

		return $notification;
	}

	public function getUserTimezone(string $userId): \DateTimeZone {
		return $this->timeFactory->getTimeZone(
			$this->config->getUserValue(
				$userId,
				'core',
				'timezone',
				$this->config->getSystemValueString('default_timezone', 'UTC')
			)
		);
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

		throw new UnknownNotificationException('Invalid subject');
	}

	protected function generateObjectParameter(array $eventData): array {
		if (!isset($eventData['id'], $eventData['name'])) {
			throw new UnknownNotificationException(' Invalid data');
		}

		if (!empty($eventData['classified'])) {
			// Busy is stored untranslated in the database, so we translate it here.
			$eventData['name'] = $this->l->t('Busy');
		}

		$params = [
			'type' => 'calendar-event',
			'id' => $eventData['id'],
			'name' => $eventData['name'],
		];

		if (isset($eventData['link']) && is_array($eventData['link']) && $this->appManager->isEnabledForUser('calendar')) {
			try {
				// The calendar app needs to be manually loaded for the routes to be loaded
				$this->appManager->loadApp('calendar');
				$linkData = $eventData['link'];
				$objectId = base64_encode('/remote.php/dav/calendars/' . $linkData['owner'] . '/' . $linkData['calendar_uri'] . '/' . $linkData['object_uri']);
				$link = [
					'view' => 'dayGridMonth',
					'timeRange' => 'now',
					'mode' => 'sidebar',
					'objectId' => $objectId,
					'recurrenceId' => 'next'
				];
				$params['link'] = $this->url->linkToRouteAbsolute('calendar.view.indexview.timerange.edit', $link);
			} catch (\Exception) {
				// Do nothing
			}
		}

		return $params;
	}

	protected function generateCalendarParameter(array $data): array {
		if ($data['uri'] === CalDavBackend::PERSONAL_CALENDAR_URI &&
			$data['name'] === CalDavBackend::PERSONAL_CALENDAR_NAME) {
			return [
				'type' => 'calendar',
				'id' => (string)$data['id'],
				'name' => $this->l->t('Personal'),
			];
		}

		return [
			'type' => 'calendar',
			'id' => (string)$data['id'],
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
