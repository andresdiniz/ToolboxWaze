<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EscolaInep;
use App\Entity\FuelResellerRaw;
use App\Entity\RadarMedidor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

final class ConsultaPublicaRepository extends ServiceEntityRepository
{
    private const TYPES = ['radar', 'escola', 'posto'];

    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, RadarMedidor::class); }

    public function findMunicipios(string $tipo, string $uf): array
    {
        $tipo = strtolower(trim($tipo)); $uf = strtoupper(trim($uf));
        if (!in_array($tipo, self::TYPES, true) || !preg_match('/^[A-Z]{2}$/', $uf)) return [];
        [$entity, $field] = match ($tipo) { 'radar' => [RadarMedidor::class, 'municipio'], 'escola' => [EscolaInep::class, 'municipio'], 'posto' => [FuelResellerRaw::class, 'municipio'] };
        $a = 'record';
        $qb = $this->getEntityManager()->createQueryBuilder()->select("DISTINCT $a.$field AS municipio")->from($entity, $a)->andWhere("UPPER($a.uf) = :uf")->andWhere("$a.$field IS NOT NULL")->andWhere("$a.$field <> ''")->setParameter('uf', $uf)->orderBy("$a.$field", 'ASC');
        return array_values(array_filter(array_map(static fn(array $r): string => trim((string)$r['municipio']), $qb->getQuery()->getArrayResult())));
    }

    public function search(string $tipo, array $filters, int $page = 1, int $limit = 20): array
    {
        $tipo = strtolower(trim($tipo)); if (!in_array($tipo, self::TYPES, true)) return ['items'=>[],'total'=>0,'page'=>1,'limit'=>$limit];
        $page = max(1,$page); $limit = min(50,max(1,$limit));
        [$entity,$a] = match($tipo){'radar'=>[RadarMedidor::class,'radar'],'escola'=>[EscolaInep::class,'escola'],'posto'=>[FuelResellerRaw::class,'posto']};
        $qb=$this->getEntityManager()->createQueryBuilder()->from($entity,$a); $this->applyFilters($qb,$a,$filters,$tipo); $this->selectFields($qb,$tipo,$a);
        $cq=$this->getEntityManager()->createQueryBuilder()->select("COUNT(DISTINCT $a.id)")->from($entity,$a); $this->applyFilters($cq,$a,$filters,$tipo);
        $total=(int)$cq->getQuery()->getSingleScalarResult(); $items=$qb->setFirstResult(($page-1)*$limit)->setMaxResults($limit)->getQuery()->getArrayResult(); return compact('items','total','page','limit');
    }

    private function applyFilters(QueryBuilder $qb,string $a,array $filters,string $tipo):void
    {
        foreach(['uf','municipio'] as $f) if(!empty($filters[$f])) $qb->andWhere("UPPER($a.$f) = UPPER(:$f)")->setParameter($f,trim((string)$filters[$f]));
        if(empty($filters['q'])) return;
        if($tipo==='radar'){ $qb->leftJoin($a.'.faixas','faixaBusca'); $e=["LOWER($a.municipio) LIKE LOWER(:q)","LOWER($a.logradouro) LIKE LOWER(:q)","LOWER($a.numeroSerie) LIKE LOWER(:q)",'LOWER(faixaBusca.numeroInmetro) LIKE LOWER(:q)']; }
        elseif($tipo==='posto') $e=["LOWER($a.razaoSocial) LIKE LOWER(:q)","LOWER($a.nomeFantasia) LIKE LOWER(:q)","LOWER($a.municipio) LIKE LOWER(:q)","LOWER($a.bandeira) LIKE LOWER(:q)"];
        else $e=["LOWER($a.escola) LIKE LOWER(:q)","LOWER($a.municipio) LIKE LOWER(:q)","LOWER($a.codigoInep) LIKE LOWER(:q)"];
        $qb->andWhere($qb->expr()->orX(...$e))->setParameter('q','%'.trim((string)$filters['q']).'%');
    }

    private function selectFields(QueryBuilder $qb,string $tipo,string $a):void
    {
        if($tipo==='radar'){ $qb->leftJoin($a.'.faixas','faixa')->select(implode(', ',[$a.'.id AS id',$a.'.municipio AS municipio',$a.'.uf AS uf',$a.'.logradouro AS endereco',$a.'.tipoMedidor AS tipo',$a.'.numeroSerie AS numeroSerie',$a.'.latitude AS latitude',$a.'.longitude AS longitude','faixa.numeroInmetro AS numeroInmetro','faixa.numeroFaixa AS numeroFaixa','faixa.sentido AS sentido','faixa.velocidadeNominal AS velocidade']))->addOrderBy($a.'.municipio','ASC')->addOrderBy('faixa.numeroFaixa','ASC'); return; }
        if($tipo==='escola'){ $fields=[$a.'.id AS id',$a.'.escola AS nome',$a.'.codigoInep AS codigoInep',$a.'.restricaoAtendimento AS restricaoAtendimento',$a.'.municipio AS municipio',$a.'.uf AS uf',$a.'.localizacao AS localizacao',$a.'.localidadeDiferenciada AS localidadeDiferenciada',$a.'.categoriaAdministrativa AS categoriaAdministrativa',$a.'.endereco AS endereco',$a.'.telefone AS telefone',$a.'.dependenciaAdministrativa AS dependenciaAdministrativa',$a.'.categoriaEscolaPrivada AS categoriaEscolaPrivada',$a.'.conveniada AS conveniada',$a.'.regulamentacao AS regulamentacao',$a.'.porte AS porte',$a.'.etapasEnsino AS etapasEnsino',$a.'.outrasOfertas AS outrasOfertas',$a.'.latitude AS latitude',$a.'.longitude AS longitude',$a.'.linkAreaEscolar AS linkAreaEscolar']; $qb->select(implode(', ',$fields))->addOrderBy($a.'.municipio','ASC'); return; }
        $fields=[$a.'.id AS id',$a.'.codigoIsimp AS codigoIsimp',$a.'.autorizacao AS autorizacao',$a.'.dataPublicacao AS dataPublicacao',$a.'.razaoSocial AS razaoSocial',$a.'.cnpj AS cnpj',$a.'.endereco AS endereco',$a.'.complemento AS complemento',$a.'.bairro AS bairro',$a.'.cep AS cep',$a.'.uf AS uf',$a.'.municipio AS municipio',$a.'.bandeira AS bandeira',$a.'.dataVinculacao AS dataVinculacao',$a.'.nomeFantasia AS nomeFantasia',$a.'.importedAt AS importedAt',$a.'.updatedAt AS updatedAt']; $qb->select(implode(', ',$fields))->addOrderBy($a.'.municipio','ASC')->addOrderBy($a.'.nomeFantasia','ASC');
    }
}
