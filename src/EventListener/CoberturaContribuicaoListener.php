<?php

declare(strict_types=1);
namespace App\EventListener;

use App\Entity\EscolaInep;
use App\Entity\EstabelecimentoSaude;
use App\Entity\RadarManual;
use App\Entity\RadarWazeLink;
use App\Entity\PostoWazeLink;
use App\Entity\EscolaInepWazeLinkLog;
use App\Entity\RadarWazeLinkLog;
use App\Entity\PostoWazeLinkLog;
use App\Entity\User;
use App\Service\CoberturaContribuicaoService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Psr\Log\LoggerInterface;

#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
final class CoberturaContribuicaoListener
{
    private ?bool $ready = null;
    public function __construct(private readonly CoberturaContribuicaoService $journal, private readonly Security $security, private readonly RequestStack $requests, private readonly LoggerInterface $logger) {}
    public function postPersist(PostPersistEventArgs $event): void { $this->capture($event->getObject(), [], true); }
    public function postUpdate(PostUpdateEventArgs $event): void
    {
        $this->capture($event->getObject(), $event->getObjectManager()->getUnitOfWork()->getEntityChangeSet($event->getObject()), false);
    }
    private function capture(object $entity, array $changes, bool $new): void
    {
        $request = $this->requests->getCurrentRequest();
        $actor = $request?->attributes->get('_cobertura_user') ?? $this->security->getUser();
        $origem = $request && str_starts_with($request->getPathInfo(), '/api/') ? 'api' : 'web';
        $ref = null; $fields = []; $date = null;
        if ($entity instanceof EscolaInep || $entity instanceof EstabelecimentoSaude) {
            if ($new) return;
            $tipo = $entity instanceof EscolaInep ? 'escolas' : 'saude'; $id = $entity->getId(); $uf = $entity->getUf();
            $fields = $changes;
            // Links da escola via interface já têm log próprio; evita duplicação.
            if ($tipo === 'escolas' && $origem !== 'api') unset($fields['linkWaze'], $fields['linkAreaEscolar']);
        } elseif ($entity instanceof RadarManual && $new) {
            $tipo = 'manuais'; $id = $entity->getId(); $uf = $entity->getSiglaUf(); $actor = $entity->getCriadoPor() ?? $actor;
            $fields = ['cadastro_manual'=>[null,'cadastrado']]; $date = $entity->getCriadoEm(); $ref = 'manual:' . $id;
        } elseif (($entity instanceof RadarWazeLink || $entity instanceof PostoWazeLink) && $new) {
            $radar = $entity instanceof RadarWazeLink; $target = $radar ? $entity->getRadarMedidor() : $entity->getPosto();
            $tipo = $radar ? 'radares' : 'postos'; $id = $target->getId(); $uf = $radar ? ($target->getSiglaUf() ?: $target->getUf()) : $target->getUf();
            $actor = $entity->getInsertedBy(); $date = $entity->getInsertedAt();
            $fields = ['waze_link'=>[null,$entity->getWazeLink()]]; $ref = ($radar ? 'radar_link:' : 'posto_link:') . $entity->getId();
        } elseif (($entity instanceof RadarWazeLinkLog || $entity instanceof PostoWazeLinkLog) && $new) {
            $radar = $entity instanceof RadarWazeLinkLog; $link = $radar ? $entity->getRadarWazeLink() : $entity->getPostoWazeLink();
            $target = $radar ? $link->getRadarMedidor() : $link->getPosto();
            $tipo = $radar ? 'radares' : 'postos'; $id = $target->getId(); $uf = $radar ? ($target->getSiglaUf() ?: $target->getUf()) : $target->getUf();
            $actor = $entity->getChangedBy(); $date = $entity->getChangedAt();
            $fields = [$entity->getCampoAlterado()=>[$entity->getValorAnterior(),$entity->getValorNovo()]];
            $ref = ($radar ? 'radar_log:' : 'posto_log:') . $entity->getId();
        } elseif ($entity instanceof EscolaInepWazeLinkLog && $new) {
            $target = $entity->getEscola(); $tipo = 'escolas'; $id = $target->getId(); $uf = $target->getUf();
            $actor = $entity->getAlteradoPor(); $date = $entity->getAlteradoEm();
            $fields = [$entity->getCampo()=>[$entity->getValorAnterior(),$entity->getValorNovo()]]; $ref = 'escola_log:' . $entity->getId();
        } else return;
        if (!$actor instanceof User || !$id || !in_array(strtoupper((string) $uf), User::ALL_UFS, true)) return;
        $pending = [];
        foreach ($fields as $field => [$before,$after]) {
            $action = $field === 'cadastro_manual' ? 'cadastro_manual' : CoberturaContribuicaoService::acao($field,$before,$after);
            if ($action !== null) $pending[] = [$field,$before,$after,$action];
        }
        if ($pending === []) return;
        $this->ready ??= $this->journal->disponivel();
        if (!$this->ready) { $this->logger->warning('Diário de cobertura indisponível: aplique a migração Version20261103112100.'); return; }
        $rid = $request?->attributes->get('_cobertura_request_id');
        if (!is_string($rid)) { $rid = bin2hex(random_bytes(16)); $request?->attributes->set('_cobertura_request_id',$rid); }
        $body = $request && $origem === 'api' ? json_decode($request->getContent(),true) : [];
        $declared = is_array($body) && is_string($body['username'] ?? null) ? mb_substr(trim($body['username']),0,100) : null;
        $at = ($date ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        foreach ($pending as [$field,$before,$after,$action]) {
            $reference = $ref !== null ? $ref : 'request:' . hash('sha256',serialize([$rid,$tipo,$id,$field,$before,$after]));
            $written = $this->journal->registrar(['referencia'=>$reference,'tipo'=>$tipo,'local_id'=>$id,'uf'=>strtoupper((string)$uf),
                'actor_id'=>$actor->getId(),'editor'=>mb_substr($actor->getWazeNickname() ?: 'Editor #' . $actor->getId(),0,180),
                'username_declarado'=>$declared,'origem'=>$request ? $origem : 'historico','acao'=>$action,'campo'=>$field,
                'valor_anterior'=>$before === null ? null : (string)$before,'valor_novo'=>$after === null ? null : (string)$after,
                'ocorrido_em'=>$at,'request_id'=>$rid]);
            if ($written && $request) $request->attributes->set('_cobertura_events',1+(int)$request->attributes->get('_cobertura_events',0));
        }
    }
}
