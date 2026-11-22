<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Solicitacao;
use App\Entity\SolicitacaoTipoResponsavel;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Fonte única de "quem pode tratar solicitações" no canal WME.
 *
 * Existem três critérios de responsabilidade no sistema (responsável do TIPO,
 * responsável da SOLICITAÇÃO e admin). Para o canal WME eles são combinados
 * por união: basta um deles, e a conta precisa estar aprovada.
 */
final class TratativaAcessoService
{
    public const STATUS_ABERTOS = [
        Solicitacao::STATUS_PENDENTE,
        Solicitacao::STATUS_EM_ANALISE,
        Solicitacao::STATUS_EM_ANDAMENTO,
        Solicitacao::STATUS_AGUARDANDO,
    ];

    /** @var array<int, list<string>> */
    private array $cacheTipos = [];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function isAdmin(User $user): bool
    {
        return in_array('ROLE_ADMIN', $user->getRoles(), true);
    }

    public function isChamp(User $user): bool
    {
        return in_array('ROLE_CHAMP', $user->getRoles(), true);
    }

    /** @return list<string> slugs dos tipos pelos quais o usuário é responsável */
    public function tiposDoUsuario(User $user): array
    {
        $id = (int) $user->getId();

        if (!isset($this->cacheTipos[$id])) {
            $rows = $this->em->createQuery(
                'SELECT DISTINCT r.tipo AS tipo FROM ' . SolicitacaoTipoResponsavel::class
                . ' r JOIN r.responsaveis u WHERE u = :user'
            )->setParameter('user', $user)->getArrayResult();

            $this->cacheTipos[$id] = array_values(array_map('strval', array_column($rows, 'tipo')));
        }

        return $this->cacheTipos[$id];
    }

    private function responsavelDeAlgumaSolicitacao(User $user): bool
    {
        return (int) $this->em->createQuery(
            'SELECT COUNT(s.id) FROM ' . Solicitacao::class . ' s WHERE :user MEMBER OF s.responsaveis'
        )->setParameter('user', $user)->getSingleScalarResult() > 0;
    }

    /**
     * Resumo seguro para o userscript decidir se mostra a aba de tratativa.
     * É só interface: toda ação é revalidada no servidor.
     *
     * @return array{tratar: bool, admin: bool, champ: bool, tipos: list<string>|null}
     */
    public function capacidades(User $user): array
    {
        if (!$user->isApproved() || $user->isRejected()) {
            return ['tratar' => false, 'admin' => false, 'champ' => false, 'tipos' => []];
        }

        $admin = $this->isAdmin($user);
        $tipos = $this->tiposDoUsuario($user);
        $tratar = $admin || $tipos !== [] || $this->responsavelDeAlgumaSolicitacao($user);

        return [
            'tratar' => $tratar,
            'admin' => $tratar && $admin,
            'champ' => $tratar && $this->isChamp($user),
            // null = todos os tipos (admin)
            'tipos' => $admin ? null : $tipos,
        ];
    }

    public function pode(User $user, Solicitacao $solicitacao): bool
    {
        if (!$user->isApproved() || $user->isRejected()) {
            return false;
        }

        return $this->isAdmin($user)
            || in_array($solicitacao->getTipo(), $this->tiposDoUsuario($user), true)
            || $solicitacao->getResponsaveis()->contains($user);
    }

    /** Downgrade de nível só pode ser processado por Champ (regra já existente no site). */
    public function bloqueioDeStatus(User $user, Solicitacao $solicitacao): ?string
    {
        $downgrade = $solicitacao->getTipo() === Solicitacao::TIPO_NIVEL
            && ($solicitacao->getDados()['tipoNivel'] ?? null) === 'downgrade';

        if ($downgrade && !$this->isChamp($user)) {
            return 'Apenas Champs podem processar downgrade de nível.';
        }

        return null;
    }

    /** Solicitações que o usuário pode tratar (já restringe por permissão). */
    public function filaQueryBuilder(User $user): QueryBuilder
    {
        $qb = $this->em->getRepository(Solicitacao::class)->createQueryBuilder('s');

        if ($this->isAdmin($user)) {
            return $qb;
        }

        $condicao = ':tratUser MEMBER OF s.responsaveis';
        $qb->setParameter('tratUser', $user);

        $tipos = $this->tiposDoUsuario($user);

        if ($tipos !== []) {
            $condicao = 's.tipo IN (:tratTipos) OR ' . $condicao;
            $qb->setParameter('tratTipos', $tipos);
        }

        return $qb->andWhere('(' . $condicao . ')');
    }
}
