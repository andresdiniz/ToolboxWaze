<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\RadarFaixa;
use App\Entity\RadarHistorico;
use App\Entity\RadarMedidor;
use App\Entity\RadarWazeLink;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Leitura pública com projeções explícitas: nunca serializa usuários ou rawData. */
final class PublicRadarController extends AbstractController
{
    private const UF = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];
    private const FIELDS = ['q'=>120,'uf'=>2,'municipio'=>100,'rodovia'=>40,'km'=>20,'situacao'=>20,'tipo_medidor'=>100,'sentido'=>50,'velocidade'=>20,'empresa'=>120,'certificado'=>50,'ano'=>4,'validade_de'=>10,'validade_ate'=>10,'ordenar'=>20];

    public function __construct(private readonly EntityManagerInterface $em) {}

    #[Route('/consultar/radares', name: 'public_radar_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filters = [];
        foreach (self::FIELDS as $key => $max) {
            $value = $request->query->get($key, '');
            if (!is_string($value) || mb_strlen($value) > $max) {
                throw new BadRequestHttpException('Filtro inválido: '.$key);
            }
            $filters[$key] = trim($value);
        }
        $filters['uf'] = strtoupper($filters['uf']);
        if ($filters['uf'] !== '' && !in_array($filters['uf'], self::UF, true)) {
            throw new BadRequestHttpException('UF inválida.');
        }
        if ($filters['ano'] !== '' && !preg_match('/^\d{4}$/D', $filters['ano'])) {
            throw new BadRequestHttpException('Informe um ano com quatro dígitos.');
        }
        foreach (['validade_de','validade_ate'] as $key) {
            if ($filters[$key] !== '') {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $filters[$key]);
                if (!$date || $date->format('Y-m-d') !== $filters[$key]) {
                    throw new BadRequestHttpException('Data inválida.');
                }
            }
        }
        if ($filters['validade_de'] !== '' && $filters['validade_ate'] !== '' && $filters['validade_de'] > $filters['validade_ate']) {
            throw new BadRequestHttpException('O início da validade deve ser anterior ao fim.');
        }
        $sorts = ['municipio'=>'r.municipio','logradouro'=>'r.logradouro','validade'=>'r.dataValidadeDate','atualizacao'=>'r.updatedAt'];
        $filters['ordenar'] = $filters['ordenar'] ?: 'municipio';
        if (!isset($sorts[$filters['ordenar']])) {
            throw new BadRequestHttpException('Ordenação inválida.');
        }
        $pageString = $request->query->get('page', '1');
        if (!is_string($pageString) || !preg_match('/^[1-9]\d{0,3}$/D', $pageString)) {
            throw new BadRequestHttpException('Página inválida.');
        }
        $page = (int) $pageString;
        $qb = $this->em->createQueryBuilder()->from(RadarMedidor::class, 'r')->where('r.mergedIntoId IS NULL');
        foreach (['uf'=>'uf','municipio'=>'municipio','situacao'=>'situacao','tipo_medidor'=>'tipoMedidor'] as $key=>$field) {
            if ($filters[$key] !== '') {
                $qb->andWhere('UPPER(r.'.$field.') = :'.$key)->setParameter($key, mb_strtoupper($filters[$key]));
            }
        }
        foreach (['empresa'=>'nomeEmpresa','rodovia'=>'logradouro','km'=>'logradouro'] as $key=>$field) {
            if ($filters[$key] !== '') {
                $qb->andWhere('UPPER(r.'.$field.') LIKE :'.$key." ESCAPE '!'")->setParameter($key, self::like($filters[$key]));
            }
        }
        // EXISTS evita multiplicação de linhas por faixas ou verificações.
        $lane = [];
        foreach (['sentido'=>'sentido','velocidade'=>'velocidadeNominal'] as $key=>$field) {
            if ($filters[$key] !== '') {
                $lane[] = 'UPPER(f.'.$field.') = :'.$key;
                $qb->setParameter($key, mb_strtoupper($filters[$key]));
            }
        }
        if ($lane) {
            $qb->andWhere('EXISTS (SELECT f.id FROM '.RadarFaixa::class.' f WHERE f.radarMedidor = r AND '.implode(' AND ', $lane).')');
        }
        $history = [];
        if ($filters['ano'] !== '') {
            $history[] = 'h.ano = :ano';
            $qb->setParameter('ano', $filters['ano']);
        }
        if ($filters['certificado'] !== '') {
            $qb->setParameter('certificado', self::like($filters['certificado']));
            if ($filters['ano'] === '') {
                $qb->andWhere("(UPPER(r.numeroCertificado) LIKE :certificado ESCAPE '!' OR EXISTS (SELECT hc.id FROM ".RadarHistorico::class." hc WHERE hc.radarMedidor = r AND UPPER(hc.numeroCertificado) LIKE :certificado ESCAPE '!'))");
            } else {
                $history[] = "UPPER(h.numeroCertificado) LIKE :certificado ESCAPE '!'";
            }
        }
        if ($history) {
            $qb->andWhere('EXISTS (SELECT h.id FROM '.RadarHistorico::class.' h WHERE h.radarMedidor = r AND '.implode(' AND ', $history).')');
        }
        foreach (['validade_de'=>'>=','validade_ate'=>'<='] as $key=>$op) {
            if ($filters[$key] !== '') {
                $qb->andWhere('r.dataValidadeDate '.$op.' :'.$key)->setParameter($key, new \DateTimeImmutable($filters[$key]), 'date_immutable');
            }
        }
        if ($filters['q'] !== '') {
            $terms = [];
            foreach (['logradouro','municipio','numeroSerie','numeroCertificado','nomeEmpresa'] as $field) {
                $terms[] = "UPPER(r.$field) LIKE :q ESCAPE '!'";
            }
            $terms[] = "EXISTS (SELECT fq.id FROM ".RadarFaixa::class." fq WHERE fq.radarMedidor = r AND (UPPER(fq.numeroInmetro) LIKE :q ESCAPE '!' OR UPPER(fq.numeroSerie) LIKE :q ESCAPE '!'))";
            $terms[] = "EXISTS (SELECT hq.id FROM ".RadarHistorico::class." hq WHERE hq.radarMedidor = r AND UPPER(hq.numeroCertificado) LIKE :q ESCAPE '!')";
            if (ctype_digit($filters['q'])) {
                $terms[] = 'r.id = :searchId';
                $qb->setParameter('searchId', $filters['q']);
            }
            $qb->andWhere('('.implode(' OR ', $terms).')')->setParameter('q', self::like($filters['q']));
        }
        $total = (int) (clone $qb)->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();
        $pages = max(1, (int) ceil($total / 20));
        $page = min($page, $pages);
        $items = $qb->select('r.id, r.uf, r.municipio, r.logradouro, r.tipoMedidor, r.situacao, r.dataValidade')
            ->orderBy($sorts[$filters['ordenar']], $filters['ordenar'] === 'atualizacao' ? 'DESC' : 'ASC')
            ->addOrderBy('r.id', 'ASC')->setFirstResult(($page - 1) * 20)->setMaxResults(20)->getQuery()->getArrayResult();
        $options = [];
        foreach (['situacao','tipoMedidor'] as $field) {
            $rows = $this->em->createQueryBuilder()->select('DISTINCT o.'.$field.' AS value')->from(RadarMedidor::class, 'o')
                ->where('o.mergedIntoId IS NULL')->andWhere('o.'.$field.' IS NOT NULL')->andWhere('o.'.$field." <> ''")->orderBy('o.'.$field, 'ASC')->getQuery()->getArrayResult();
            $options[$field] = array_column($rows, 'value');
        }
        $canonicalParams = array_filter($filters, static fn(string $v): bool => $v !== '');
        if ($canonicalParams['ordenar'] === 'municipio') { unset($canonicalParams['ordenar']); }
        if ($page > 1) { $canonicalParams['page'] = $page; }
        $canonical = $this->generateUrl('public_radar_index', $canonicalParams, UrlGeneratorInterface::ABSOLUTE_URL);
        return $this->render('public_radar/page.html.twig', [
            'detail'=>false,'filters'=>$filters,'states'=>self::UF,'options'=>$options,'items'=>$items,'total'=>$total,'page'=>$page,'pages'=>$pages,
            'canonical'=>$canonical,'title'=>'Consulta gratuita de radares no Brasil | ToolboxWaze',
            'description'=>'Busque radares por estado, município, rodovia, km, tipo, situação, certificado e validade. Consulte faixas e verificações sem login.',
            'jsonld'=>$this->jsonLd(['@context'=>'https://schema.org','@type'=>'CollectionPage','name'=>'Consulta pública de radares','url'=>$canonical,'inLanguage'=>'pt-BR']),
        ]);
    }

    #[Route('/consultar/radar/{id}', name: 'public_radar_show', methods: ['GET'], requirements: ['id'=>'[1-9][0-9]{0,9}'])]
    public function show(int $id): Response
    {
        $r = $this->em->getRepository(RadarMedidor::class)->find($id);
        if (!$r) { throw $this->createNotFoundException('Radar não encontrado.'); }
        if ($r->getMergedIntoId() !== null) {
            return $this->redirectToRoute('public_radar_show', ['id'=>$r->getMergedIntoId()], 301);
        }
        $lanes = $this->em->createQueryBuilder()->select('f.numeroFaixa, f.numeroInmetro, f.numeroSerie, f.sentido, f.velocidadeNominal')
            ->from(RadarFaixa::class, 'f')->where('f.radarMedidor = :r')->setParameter('r', $r)->orderBy('f.numeroFaixa', 'ASC')->getQuery()->getArrayResult();
        $history = $this->em->createQueryBuilder()->select('h.tipoServico, h.ano, h.numeroCertificado, h.numeroEnsaio, h.dataLaudo, h.dataValidade')
            ->from(RadarHistorico::class, 'h')->where('h.radarMedidor = :r')->setParameter('r', $r)->orderBy('h.ano', 'DESC')->addOrderBy('h.id', 'DESC')->getQuery()->getArrayResult();
        $links = $this->em->createQueryBuilder()->select('w.wazeLink')->from(RadarWazeLink::class, 'w')->where('w.radarMedidor = :r')
            ->setParameter('r', $r)->setMaxResults(1)->getQuery()->getArrayResult();
        // O vínculo dedicado é a fonte atual; o campo legado só é usado se não há vínculo.
        $coords = self::coordinates($links[0]['wazeLink'] ?? $r->getLinkWaze());
        $data = ['id'=>$r->getId(),'logradouro'=>$r->getLogradouro(),'municipio'=>$r->getMunicipio(),'uf'=>$r->getUf(),
            'tipo'=>$r->getTipoMedidor(),'situacao'=>$r->getSituacao(),'ultimaVerificacao'=>$r->getDataUltimaVerificacao(),
            'validade'=>$r->getDataValidade(),'cep'=>$r->getCep(),'empresa'=>$r->getNomeEmpresa(),'cnpj'=>$r->getCnpjEmpresa()];
        $title = ($data['logradouro'] ?: 'Radar #'.$id).' — '.($data['municipio'] ?: 'Município não informado').'/'.$data['uf'];
        $canonical = $this->generateUrl('public_radar_show', ['id'=>$id], UrlGeneratorInterface::ABSOLUTE_URL);
        $place = ['@type'=>'Place','name'=>$title,'address'=>['@type'=>'PostalAddress','streetAddress'=>$data['logradouro'],'addressLocality'=>$data['municipio'],'addressRegion'=>$data['uf'],'addressCountry'=>'BR']];
        if ($coords) { $place['geo'] = ['@type'=>'GeoCoordinates','latitude'=>$coords['lat'],'longitude'=>$coords['lon']]; }
        return $this->render('public_radar/page.html.twig', [
            'detail'=>true,'radar'=>$data,'lanes'=>$lanes,'history'=>$history,'coords'=>$coords,'canonical'=>$canonical,
            'title'=>$title.' | ToolboxWaze','description'=>'Consulte tipo, situação, faixas, velocidade nominal, validade e histórico de verificações de '.$title.'.',
            'jsonld'=>$this->jsonLd(['@context'=>'https://schema.org','@type'=>'WebPage','name'=>$title,'url'=>$canonical,'inLanguage'=>'pt-BR','mainEntity'=>$place]),
        ]);
    }

    private static function like(string $value): string
    {
        return '%'.strtr(mb_strtoupper($value), ['!'=>'!!','%'=>'!%','_'=>'!_']).'%';
    }

    /** As coordenadas do permalink podem representar o centro do mapa, não posição aferida. */
    private static function coordinates(?string $url): ?array
    {
        if (!$url || strlen($url) > 1000) { return null; }
        $parts = parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || !in_array(strtolower($parts['host'] ?? ''), ['waze.com','www.waze.com','beta.waze.com'], true)
            || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== 443)) { return null; }
        parse_str($parts['query'] ?? '', $query);
        foreach (['lat'=>90,'lon'=>180] as $key=>$bound) {
            if (!isset($query[$key]) || !is_string($query[$key]) || !is_numeric($query[$key]) || !is_finite((float) $query[$key]) || abs((float) $query[$key]) > $bound) { return null; }
        }
        return ['lat'=>(float) $query['lat'],'lon'=>(float) $query['lon']];
    }

    private function jsonLd(array $data): string
    {
        return json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
    }
}
