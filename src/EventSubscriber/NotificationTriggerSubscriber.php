<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\RadarMedidor;
use App\Entity\Solicitacao;
use App\Entity\SolicitacaoComentario;
use App\Entity\User;
use App\Service\Notification\NotificationTriggerService;
use Doctrine\ORM\Events;
use Symfony\Component\EventDispatcher\Attribute\AsDoctrineListener;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::postUpdate)]
final class NotificationTriggerSubscriber
{
    /**
     * @var array<int, array<string, array{0:mixed,1:mixed}>>
     */
    private array $changes = [];

    public function __construct(
        private readonly NotificationTriggerService $triggers,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    public function postPersist(\Doctrine\ORM\Event\PostPersistEventArgs $event): void
    {
        $entity = $event->getObject();
        $actor = $this->currentUser();

        if ($entity instanceof RadarMedidor) {
            $this->triggers->radarCreated($entity, $actor);

            return;
        }

        if ($entity instanceof SolicitacaoComentario) {
            $this->triggers->commentAdded($entity, $actor);
        }
    }

    public function preUpdate(\Doctrine\ORM\Event\PreUpdateEventArgs $event): void
    {
        $entity = $event->getObject();

        if (!$entity instanceof RadarMedidor && !$entity instanceof Solicitacao) {
            return;
        }

        $changes = [];

        foreach ($event->getEntityChangeSet() as $field => $values) {
            $changes[$field] = [$values[0], $values[1]];
        }

        $this->changes[spl_object_id($entity)] = $changes;
    }

    public function postUpdate(\Doctrine\ORM\Event\PostUpdateEventArgs $event): void
    {
        $entity = $event->getObject();
        $key = spl_object_id($entity);
        $changes = $this->changes[$key] ?? [];

        unset($this->changes[$key]);

        $actor = $this->currentUser();

        if ($entity instanceof RadarMedidor) {
            $this->triggers->radarUpdated($entity, $changes, $actor);

            return;
        }

        if ($entity instanceof Solicitacao && isset($changes['status'])) {
            $this->triggers->requestStatusChanged(
                $entity,
                (string) $changes['status'][0],
                $actor
            );
        }
    }

    private function currentUser(): ?User
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        return $user instanceof User ? $user : null;
    }
}
