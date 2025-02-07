<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CoreBundle\Migrations\Schema\V200;

use Chamilo\CoreBundle\Entity\User;
use Chamilo\CoreBundle\Migrations\AbstractMigrationChamilo;
use Chamilo\CourseBundle\Entity\CCalendarEvent;
use DateTime;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\Exception\ORMException;
use Exception;

class Version20230904173400 extends AbstractMigrationChamilo
{
    public function getDescription(): string
    {
        return 'Migrate personal_agenda to c_calendar_event';
    }

    /**
     * @inheritDoc
     *
     * @throws ORMException
     * @throws Exception
     */
    public function up(Schema $schema): void
    {
        $collectiveInvitationsEnabled = $this->getConfigurationValue('agenda_collective_invitations');
        $subscriptionsEnabled = $this->getConfigurationValue('agenda_event_subscriptions');

        $this->addSql("UPDATE personal_agenda SET parent_event_id = NULL WHERE parent_event_id = 0 OR parent_event_id = ''");
        $this->addSql('UPDATE personal_agenda SET parent_event_id = NULL WHERE parent_event_id NOT IN (SELECT id FROM personal_agenda)');
        $this->addSql('DELETE FROM personal_agenda WHERE user NOT IN (SELECT id FROM user)');

        /** @var array<int, CCalendarEvent> $map */
        $map = [];

        $em = $this->getEntityManager();
        $userRepo = $em->getRepository(User::class);

        $personalAgendas = $this->getPersonalEvents();

        $utc = new DateTimeZone('UTC');

        /** @var array $personalAgenda */
        foreach ($personalAgendas as $personalAgenda) {
            $oldParentId = (int) $personalAgenda['parent_event_id'];
            $user = $userRepo->find($personalAgenda['user']);

            $newParent = null;

            if ($oldParentId && isset($map[$oldParentId])) {
                $newParent = $map[$oldParentId];
            }

            $calendarEvent = $this->createCCalendarEvent(
                $personalAgenda['title'] ?: '-',
                $personalAgenda['text'],
                $personalAgenda['date'] ? new DateTime($personalAgenda['date'], $utc) : null,
                $personalAgenda['enddate'] ? new DateTime($personalAgenda['enddate'], $utc) : null,
                (bool) $personalAgenda['all_day'],
                $personalAgenda['color'],
                $user,
                $newParent
            );

            $map[$personalAgenda['id']] = $calendarEvent;

            $em->persist($calendarEvent);

            if ($collectiveInvitationsEnabled) {
                $calendarEvent->setCollective($personalAgenda['collective']);

                $hasSubscriptions = false;
                $invitationsOrSubscriptionsInfo = [];

                if ($subscriptionsEnabled) {
                    $subscriptionsInfo = $this->getSubscriptions((int) $personalAgenda['id']);

                    if (count($subscriptionsInfo) > 0) {
                        $hasSubscriptions = true;

                        $invitationsOrSubscriptionsInfo = $subscriptionsInfo;
                    }
                }

                if ($hasSubscriptions) {
                    $calendarEvent
                        ->setInvitaionType(CCalendarEvent::TYPE_SUBSCRIPTION)
                        ->setSubscriptionVisibility($personalAgenda['subscription_visibility'])
                        ->setSubscriptionItemId($personalAgenda['subscription_item_id'])
                    ;
                } else {
                    $calendarEvent->setInvitaionType(CCalendarEvent::TYPE_INVITATION);

                    $invitationsOrSubscriptionsInfo = $this->getInvitations($subscriptionsEnabled, (int) $personalAgenda['id']);
                }

                foreach ($invitationsOrSubscriptionsInfo as $invitationOrSubscriptionInfo) {
                    $inviteesOrSubscribersInfo = $this->getInviteesOrSubscribers($invitationOrSubscriptionInfo['id']);

                    foreach ($inviteesOrSubscribersInfo as $oldInviteeOrSubscriberInfo) {
                        $user = $em->find(User::class, $oldInviteeOrSubscriberInfo['user_id']);

                        if ($user) {
                            $calendarEvent->addUserLink($user);
                        }
                    }
                }
            }
        }

        $em->flush();
    }

    private function getPersonalEvents(): array
    {
        $sql = 'SELECT * FROM personal_agenda ORDER BY id';
        $result = $this->connection->executeQuery($sql);

        return $result->fetchAllAssociative();
    }

    private function createCCalendarEvent(
        string $title,
        string $content,
        ?DateTime $startDate,
        ?DateTime $endDate,
        bool $allDay,
        string $color,
        User $creator,
        ?CCalendarEvent $parentEvent = null
    ): CCalendarEvent {
        $calendarEvent = new CCalendarEvent();

        $calendarEvent
            ->setTitle($title)
            ->setContent($content)
            ->setStartDate($startDate)
            ->setEndDate($endDate)
            ->setAllDay($allDay)
            ->setColor($color)
            ->setCreator($creator)
            ->setResourceName($title)
        ;

        if ($parentEvent) {
            $calendarEvent
                ->setParentEvent($parentEvent)
                ->setParentResourceNode($parentEvent->getResourceNode()->getId())
            ;
        } else {
            $calendarEvent->setParentResourceNode($creator->getResourceNode()->getId());
        }

        return $calendarEvent;
    }

    private function getInvitations(bool $subscriptionsEnabled, int $personalAgendaId): array
    {
        $sql = "SELECT i.id, i.creator_id, i.created_at, i.updated_at
            FROM agenda_event_invitation i
            INNER JOIN personal_agenda pa ON i.id = pa.agenda_event_invitation_id
            WHERE pa.id = $personalAgendaId";

        if ($subscriptionsEnabled) {
            $sql .= " AND i.type = 'invitation'";
        }

        try {
            $result = $this->connection->executeQuery($sql);

            return $result->fetchAllAssociative();
        } catch (\Doctrine\DBAL\Exception) {
            return [];
        }
    }

    private function getInviteesOrSubscribers(int $invitationId): array
    {
        $sql = "SELECT id, user_id, created_at, updated_at
            FROM agenda_event_invitee
            WHERE invitation_id = $invitationId
            ORDER BY created_at ASC";

        try {
            $result = $this->connection->executeQuery($sql);

            return $result->fetchAllAssociative();
        } catch (\Doctrine\DBAL\Exception) {
            return [];
        }
    }

    private function getSubscriptions(int $personalAgendaId): array
    {
        $sql = "SELECT i.id, i.creator_id, i.created_at, i.updated_at
            FROM agenda_event_invitation i
            INNER JOIN personal_agenda pa ON i.id = pa.agenda_event_invitation_id
            WHERE pa.id = $personalAgendaId
                AND i.type = 'subscription'";

        try {
            $result = $this->connection->executeQuery($sql);

            return $result->fetchAllAssociative();
        } catch (\Doctrine\DBAL\Exception) {
            return [];
        }
    }
}
