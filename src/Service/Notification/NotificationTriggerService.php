<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\NotificationEvent;
use App\Entity\RadarMedidor;
use App\Entity\Solicitacao;
use App\Entity\SolicitacaoComentario;
use App\Entity\User;
use App\Entity\WmeDispositivo;
use App\Repository\NotificationEventRepository;

final class NotificationTriggerService
{
    private const RADAR_FIELDS = [
        'situacao',
        'tipoMedidor',
        'logradouro',
        'municipio',
        'uf',
        'nomeEmpresa',
        'numeroCertificado',
        'dataValidade',
    ];

    private const LABELS = [
        'situacao' => 'Situação',
        'tipoMedidor' => 'Tipo de medidor',
        'logradouro' => 'Logradouro',
        'municipio' => 'Município',
        'uf' => 'Estado',
        'nomeEmpresa' => 'Empresa',
        'numeroCertificado' => 'Certificado',
        'dataValidade' => 'Validade',
    ];

    public function __construct(
        private readonly EmailNotificationService $notifications,
        private readonly NotificationEventRepository $events,
    ) {
    }

    public function radarCreated(RadarMedidor $radar, ?User $actor = null): ?NotificationEvent
    {
        if ($radar->getId() === null || $this->isSuppressed('radar_criado', 'radar', $radar->getId(), 60)) {
            return null;
        }

        return $this->notifications->dispatch(
            'radar_criado',
            'Novo radar cadastrado',
            sprintf(
                'Um radar foi cadastrado em %s — %s.',
                $radar->getMunicipio() ?: 'município não informado',
                $radar->getUf() ?: 'UF não informada'
            ),
            $this->radarLink($radar),
            'radar',
            $radar->getId(),
            $actor,
        );
    }

    /**
     * @param array<string, array{0:mixed,1:mixed}> $changes
     */
    public function radarUpdated(RadarMedidor $radar, array $changes, ?User $actor = null): ?NotificationEvent
    {
        $relevant = array_intersect(array_keys($changes), self::RADAR_FIELDS);

        if ($radar->getId() === null || $relevant === [] || $this->isSuppressed('radar_atualizado', 'radar', $radar->getId(), 60)) {
            return null;
        }

        $lines = [];

        foreach ($relevant as $field) {
            [$old, $new] = $changes[$field];

            $lines[] = sprintf(
                '%s: %s → %s',
                self::LABELS[$field] ?? $field,
                $this->value($old),
                $this->value($new)
            );
        }

        return $this->notifications->dispatch(
            'radar_atualizado',
            'Radar atualizado',
            sprintf(
                "O radar em %s — %s foi atualizado.\n\n%s",
                $radar->getMunicipio() ?: 'município não informado',
                $radar->getUf() ?: 'UF não informada',
                implode("\n", $lines)
            ),
            $this->radarLink($radar),
            'radar',
            $radar->getId(),
            $actor,
        );
    }

    public function validityReminder(RadarMedidor $radar): ?NotificationEvent
    {
        if ($radar->getId() === null || $this->isSuppressed('radar_validade', 'radar', $radar->getId(), 1440)) {
            return null;
        }

        return $this->notifications->dispatch(
            'radar_validade',
            'Radar com validade próxima',
            sprintf(
                'O radar em %s — %s possui validade informada para %s.',
                $radar->getMunicipio() ?: 'município não informado',
                $radar->getUf() ?: 'UF não informada',
                $radar->getDataValidade()?->format('d/m/Y') ?: 'data não informada'
            ),
            $this->radarLink($radar),
            'radar',
            $radar->getId(),
        );
    }

    public function requestStatusChanged(Solicitacao $request, string $previousStatus, ?User $actor = null): ?NotificationEvent
    {
        if (
            $request->getId() === null
            || $previousStatus === $request->getStatus()
            || $this->isSuppressed('solicitacao_status', 'solicitacao', $request->getId(), 10)
        ) {
            return null;
        }

        return $this->notifications->dispatchToUsers(
            'solicitacao_status',
            'Solicitação atualizada',
            sprintf(
                'A solicitação #%d (%s) mudou de "%s" para "%s".',
                $request->getId(),
                $request->getTipoLabel(),
                $previousStatus,
                $request->getStatusLabel()
            ),
            array_values($request->getResponsaveis()->toArray()),
            $this->requestLink($request),
            'solicitacao',
            $request->getId(),
            $actor,
        );
    }

    public function commentAdded(SolicitacaoComentario $comment, ?User $actor = null): ?NotificationEvent
    {
        $request = $comment->getSolicitacao();

        if (
            $request->getId() === null
            || $comment->isInterno()
            || $this->isSuppressed('solicitacao_comentario', 'solicitacao', $request->getId(), 10)
        ) {
            return null;
        }

        $recipients = array_values($request->getResponsaveis()->toArray());

        if ($comment->getAutor() !== null) {
            $recipients[] = $comment->getAutor();
        }

        return $this->notifications->dispatchToUsers(
            'solicitacao_comentario',
            'Novo comentário na solicitação',
            sprintf(
                '%s comentou na solicitação #%d (%s).',
                $comment->getAutorNome(),
                $request->getId(),
                $request->getTipoLabel()
            ),
            $recipients,
            $this->requestLink($request),
            'solicitacao',
            $request->getId(),
            $actor,
        );
    }

    public function deviceExpiring(WmeDispositivo $device): ?NotificationEvent
    {
        if ($device->getId() === null || $this->isSuppressed('dispositivo_expira', 'dispositivo', $device->getId(), 1440)) {
            return null;
        }

        $lastActivity = $device->getUltimoUsoEm()
            ?? $device->getAtivadoEm()
            ?? $device->getCriadoEm();

        $expiresAt = $lastActivity->modify('+90 days');

        return $this->notifications->dispatchToUsers(
            'dispositivo_expira',
            'Seu dispositivo WME expira em breve',
            sprintf(
                'O dispositivo #%d está inativo desde %s e expirará em %s. Renove ou revogue o token na área "Meus dispositivos".',
                $device->getId(),
                $lastActivity->format('d/m/Y'),
                $expiresAt->format('d/m/Y')
            ),
            [$device->getUser()],
            null,
            'dispositivo',
            $device->getId(),
        );
    }

    private function isSuppressed(string $type, string $entityType, int $entityId, int $minutes): bool
    {
        return $this->events->findRecent($type, $entityType, $entityId, $minutes) !== null;
    }

    private function radarLink(RadarMedidor $radar): string
    {
        return '/consultar/radar/' . $radar->getId();
    }

    private function requestLink(Solicitacao $request): string
    {
        return '/solicitacoes/' . $request->getId();
    }

    private function value(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('d/m/Y');
        }

        return (string) ($value ?? '—');
    }
}
