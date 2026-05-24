<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\RadarMedidor;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Cadastro manual de radares que ainda não constam na base do INMETRO.
 */
#[Route('/radares/manual', name: 'radar_manual_')]
final class RadarManualController extends AbstractController
{
    // UFs brasileiras para o select
    private const UFS = [
        'AC','AL','AM','AP','BA','CE','DF','ES','GO',
        'MA','MG','MS','MT','PA','PB','PE','PI','PR',
        'RJ','RN','RO','RR','RS','SC','SE','SP','TO',
    ];

    // Tipos de medidor presentes na base INMETRO
    private const TIPOS = [
        'Estacionário',
        'Portátil',
        'Fixo',
        'Redutor de velocidade',
        'Monitoração',
    ];

    // Resultados possíveis
    private const RESULTADOS = [
        'APROVADO',
        'REPROVADO',
        'APROVADO COM RESTRIÇÃO',
        'CANCELADO',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
    ) {}

    // ------------------------------------------------------------------
    //  GET /radares/manual/novo  —  formulário
    // ------------------------------------------------------------------
    #[Route('/novo', name: 'novo', methods: ['GET', 'POST'])]
    public function novo(Request $req): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_REMEMBERED');

        /** @var User $user */
        $user = $this->getUser();

        $errors = [];
        $data   = [
            'sigla_uf'                => '',
            'estado'                  => '',
            'municipio'               => '',
            'local_verificacao'       => '',
            'tipo_medidor'            => '',
            'ultimo_resultado'        => '',
            'data_ultima_verificacao' => '',
            'data_validade'           => '',
            'proprietario_nome'       => '',
            'proprietario_municipio'  => '',
            'proprietario_estado'     => '',
        ];

        if ($req->isMethod('POST')) {
            $data = [
                'sigla_uf'                => strtoupper(trim((string) $req->request->get('sigla_uf', ''))),
                'estado'                  => trim((string) $req->request->get('estado', '')),
                'municipio'               => trim((string) $req->request->get('municipio', '')),
                'local_verificacao'       => trim((string) $req->request->get('local_verificacao', '')),
                'tipo_medidor'            => trim((string) $req->request->get('tipo_medidor', '')),
                'ultimo_resultado'        => trim((string) $req->request->get('ultimo_resultado', '')),
                'data_ultima_verificacao' => trim((string) $req->request->get('data_ultima_verificacao', '')),
                'data_validade'           => trim((string) $req->request->get('data_validade', '')),
                'proprietario_nome'       => trim((string) $req->request->get('proprietario_nome', '')),
                'proprietario_municipio'  => trim((string) $req->request->get('proprietario_municipio', '')),
                'proprietario_estado'     => strtoupper(trim((string) $req->request->get('proprietario_estado', ''))),
            ];

            // Validação
            if (!in_array($data['sigla_uf'], self::UFS, true)) {
                $errors['sigla_uf'] = 'Selecione uma UF válida.';
            }
            if ($data['municipio'] === '') {
                $errors['municipio'] = 'Município é obrigatório.';
            }
            if ($data['local_verificacao'] === '') {
                $errors['local_verificacao'] = 'Local / endereço é obrigatório.';
            }

            // Datas: converte de yyyy-mm-dd para dd/mm/yyyy (padrão INMETRO)
            foreach (['data_ultima_verificacao', 'data_validade'] as $campo) {
                if ($data[$campo] !== '') {
                    $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $data[$campo]);
                    if ($dt === false) {
                        $errors[$campo] = 'Data inválida.';
                    } else {
                        $data[$campo] = $dt->format('d/m/Y');
                    }
                }
            }

            // Verifica duplicata manual (mesmo local + UF já existe como manual)
            if (empty($errors)) {
                $existing = $this->db->fetchOne(
                    "SELECT id FROM radar_medidor
                     WHERE sigla_uf = ? AND municipio = ? AND local_verificacao = ? AND origem = 'manual'",
                    [$data['sigla_uf'], $data['municipio'], $data['local_verificacao']]
                );
                if ($existing) {
                    $errors['local_verificacao'] = 'Já existe um radar manual neste local. '
                        . '<a href="/radares/' . $existing . '">Ver radar #' . $existing . '</a>';
                }
            }

            if (empty($errors)) {
                $radar = new RadarMedidor();
                $radar
                    ->setSiglaUf($data['sigla_uf'])
                    ->setEstado($data['estado'] ?: null)
                    ->setMunicipio($data['municipio'])
                    ->setLocalVerificacao($data['local_verificacao'])
                    ->setTipoMedidor($data['tipo_medidor'] ?: null)
                    ->setUltimoResultado($data['ultimo_resultado'] ?: null)
                    ->setDataUltimaVerificacao($data['data_ultima_verificacao'] ?: null)
                    ->setDataValidade($data['data_validade'] ?: null)
                    ->setProprietarioNome($data['proprietario_nome'] ?: null)
                    ->setProprietarioMunicipio($data['proprietario_municipio'] ?: null)
                    ->setProprietarioEstado($data['proprietario_estado'] ?: null)
                    ->setOrigem('manual')
                    ->setCriadoPor($user->getId())
                    ->setImportedAt(new \DateTimeImmutable())
                    ->setRowHash(null);  // sem hash INMETRO até o merge

                // Calcula data_verificacao_efetiva como no importador
                $efetiva = $data['data_ultima_verificacao'] ?: null;
                if ($efetiva === null && $data['data_validade'] !== '') {
                    $dv = \DateTimeImmutable::createFromFormat('d/m/Y', $data['data_validade']);
                    if ($dv !== false) {
                        $efetiva = $dv->modify('-1 year')->format('d/m/Y');
                    }
                }
                $radar->setDataVerificacaoEfetiva($efetiva);

                // identity_hash: mesmo algoritmo do importador
                $radar->setIdentityHash(
                    hash('sha256', $data['sigla_uf'] . '|' . ($data['local_verificacao']))
                );

                $this->em->persist($radar);
                $this->em->flush();

                $this->addFlash('success',
                    'Radar cadastrado com sucesso. Será atualizado automaticamente quando o INMETRO publicar o registro oficial.');

                return $this->redirectToRoute('radar_show', ['id' => $radar->getId()]);
            }
        }

        return $this->render('radar/manual_novo.html.twig', [
            'data'      => $data,
            'errors'    => $errors,
            'ufs'       => self::UFS,
            'tipos'     => self::TIPOS,
            'resultados'=> self::RESULTADOS,
        ]);
    }

    // ------------------------------------------------------------------
    //  GET /radares/manual  —  lista os radares manuais do usuário
    // ------------------------------------------------------------------
    #[Route('', name: 'lista', methods: ['GET'])]
    public function lista(): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_REMEMBERED');

        /** @var User $user */
        $user = $this->getUser();

        $rows = $this->db->fetchAllAssociative(
            "SELECT rm.id, rm.sigla_uf, rm.municipio, rm.local_verificacao,
                    rm.tipo_medidor, rm.ultimo_resultado, rm.data_validade,
                    rm.imported_at AS criado_em,
                    CASE WHEN rwl.id IS NOT NULL THEN 1 ELSE 0 END AS tem_waze
             FROM radar_medidor rm
             LEFT JOIN radar_waze_link rwl ON rwl.radar_medidor_id = rm.id
             WHERE rm.origem = 'manual' AND rm.criado_por = ?
             ORDER BY rm.imported_at DESC",
            [$user->getId()]
        );

        return $this->render('radar/manual_lista.html.twig', [
            'radares' => $rows,
        ]);
    }

    // ------------------------------------------------------------------
    //  POST /radares/manual/{id}/excluir  —  exclui radar manual
    // ------------------------------------------------------------------
    #[Route('/{id}/excluir', name: 'excluir', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function excluir(int $id): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_REMEMBERED');

        /** @var User $user */
        $user  = $this->getUser();
        $radar = $this->em->find(RadarMedidor::class, $id);

        if (!$radar || !$radar->isManual()) {
            throw $this->createNotFoundException('Radar não encontrado ou não é manual.');
        }

        // Somente o criador ou ADMIN pode excluir
        if ($radar->getCriadoPor() !== $user->getId() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }

        $this->em->remove($radar);
        $this->em->flush();

        $this->addFlash('success', 'Radar manual excluído.');
        return $this->redirectToRoute('radar_manual_lista');
    }
}
