<?php

declare(strict_types=1);
namespace App\EventSubscriber;

use App\Entity\User;
use App\Service\ApiTokenService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

final class CoberturaApiSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly Security $security, private readonly ApiTokenService $tokens, private readonly Connection $db) {}
    public static function getSubscribedEvents(): array { return ['kernel.controller'=>['guard',10], 'kernel.response'=>['metadata',0]]; }
    public function guard(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) return;
        $r = $event->getRequest(); $route = (string)$r->attributes->get('_route');
        $kind = match (true) {
            str_starts_with($route,'api_escola') => 'escolas', str_starts_with($route,'api_saude') => 'saude',
            str_starts_with($route,'api_radar_consulta_'), $route === 'api_radares' => 'radares', $route === 'api_postos' => 'postos', default => null,
        };
        if ($kind === null) return;
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            $token = $this->tokens->extractBearerToken($r->headers->get('Authorization',''));
            $user = $token !== null ? $this->tokens->resolveUser($token) : null;
        }
        if (!$user instanceof User) throw new UnauthorizedHttpException('Bearer','Autenticação necessária.');
        $perm = ['escolas'=>User::PERMISSION_ESCOLAS,'saude'=>User::PERMISSION_SAUDE,'radares'=>User::PERMISSION_RADARES,'postos'=>User::PERMISSION_POSTOS][$kind];
        if (!$user->hasPermission($perm)) throw new AccessDeniedHttpException('Módulo não autorizado.');
        $r->attributes->set('_cobertura_user',$user); $r->attributes->set('_cobertura_kind',$kind);
        $r->attributes->set('_cobertura_request_id',bin2hex(random_bytes(16)));
        $uf = strtoupper(trim((string)$r->attributes->get('uf',$r->query->getString('uf'))));
        if ($uf !== '' && (!in_array($uf,User::ALL_UFS,true) || !$user->canAccessUf($uf))) throw new AccessDeniedHttpException('UF não autorizada.');
        $body = json_decode($r->getContent(),true);
        if (is_array($body) && array_key_exists('username',$body) && (!is_string($body['username']) || trim($body['username']) === '' || mb_strlen($body['username']) > 100)) throw new BadRequestHttpException('Username deve ser texto não vazio com até 100 caracteres.');
        if (in_array($kind,['escolas','saude'],true) && $r->attributes->has('id')) {
            $table = $kind === 'escolas' ? 'escola_inep' : 'estabelecimento_saude';
            $row = $this->db->fetchAssociative('SELECT * FROM ' . $table . ' WHERE id = ?',[(int)$r->attributes->get('id')]);
            if ($row && !$user->canAccessUf((string)$row['uf'])) throw new AccessDeniedHttpException('UF não autorizada.');
            if ($row && str_ends_with($route,'_status') && is_array($body) && ($body['status'] ?? null) === 'confirmado') {
                $link = trim((string)($row['link_waze'] ?? '')); $place = trim((string)($row['link_area_escolar'] ?? ''));
                if ($link === '' && $place === '') throw new UnprocessableEntityHttpException('Cadastre o vínculo antes de confirmar. Não localizado e pulado continuam disponíveis.');
            }
        }
    }
    public function metadata(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) return;
        $r = $event->getRequest(); $user = $r->attributes->get('_cobertura_user'); $response = $event->getResponse();
        if (!$user instanceof User || !$response instanceof JsonResponse) return;
        $data = json_decode((string)$response->getContent(),true);
        if (!is_array($data) || array_is_list($data)) return;
        if ($response->isSuccessful() && !$this->allowed($data,$user)) {
            $event->setResponse(new JsonResponse(['error'=>'A resposta inclui uma UF não autorizada.'],403,['Cache-Control'=>'private, no-store'])); return;
        }
        if (!array_key_exists('_meta',$data)) $data['_meta'] = ['origem'=>'banco_local','verificado_externamente'=>false,
            'gerado_em'=>gmdate('Y-m-d\TH:i:s\Z'),'request_id'=>$r->attributes->get('_cobertura_request_id'),
            'eventos_registrados'=>$response->isSuccessful() ? (int)$r->attributes->get('_cobertura_events',0) : 0];
        $response->setData($data); $response->headers->set('Cache-Control','private, no-store');
    }
    private function allowed(array $node, User $user): bool
    {
        $uf = $node['sigla_uf'] ?? $node['uf'] ?? null;
        if (is_string($uf) && $uf !== '' && !$user->canAccessUf(strtoupper($uf))) return false;
        foreach ($node as $value) if (is_array($value) && !$this->allowed($value,$user)) return false;
        return true;
    }
}
