<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\RadarMergeService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/radares/mesclar', name: 'radar_merge_')]
final class RadarMergeController extends AbstractController
{
    public function __construct(
        private readonly Connection $db,
        private readonly RadarMergeService $mergeService,
    ) {}

    /**
     * GET  /radares/mesclar?ids[]=1&ids[]=2&ids[]=3
     *   → Exibe tela de confirmação lado a lado
     *
     * POST /radares/mesclar
     *   → Executa a mesclagem e redireciona ao sobrevivente
     */
    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $req): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_REMEMBERED');

        /** @var User $user */
        $user = $this->getUser();

        // ── Coletar IDs ──────────────────────────────────────────────────────
        if ($req->isMethod('POST')) {
            $ids = array_map('intval', (array) $req->request->all('ids'));
        } else {
            $ids = array_map('intval', (array) $req->query->all('ids'));
        }

        $ids = array_values(array_unique(array_filter($ids)));

        if (count($ids) < 2) {
            $this->addFlash('error', 'Selecione pelo menos 2 radares para mesclar.');
            return $this->redirectToRoute('radar_index');
        }

        if (count($ids) > 5) {
            $this->addFlash('error', 'Selecione no máximo 5 radares por vez.');
            return $this->redirectToRoute('radar_index');
        }

        // ── Buscar radares ───────────────────────────────────────────────────
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $radares = $this->db->fetchAllAssociative(
            "SELECT rm.*, 
                    (SELECT COUNT(*) FROM radar_faixa rf WHERE rf.radar_medidor_id = rm.id) AS total_faixas
             FROM radar_medidor rm
             WHERE rm.id IN ($placeholders)
             ORDER BY FIELD(rm.id, $placeholders)",
            array_merge($ids, $ids)
        );

        if (count($radares) !== count($ids)) {
            $this->addFlash('error', 'Um ou mais radares não foram encontrados.');
            return $this->redirectToRoute('radar_index');
        }

        // Verificar acesso por UF
        foreach ($radares as $r) {
            if (!$user->canAccessUf((string) ($r['sigla_uf'] ?? ''))) {
                throw $this->createAccessDeniedException('Sem acesso ao estado ' . $r['sigla_uf']);
            }
        }

        // ── POST: executar mesclagem ─────────────────────────────────────────
        if ($req->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('radar_merge', $req->request->get('_token'))) {
                $this->addFlash('error', 'Token de segurança inválido.');
                return $this->redirectToRoute('radar_index');
            }

            $survivorId  = (int) $req->request->get('survivor_id');
            $absorbedIds = array_values(array_filter(
                array_map('intval', $ids),
                fn($id) => $id !== $survivorId
            ));

            if (!in_array($survivorId, $ids, true)) {
                $this->addFlash('error', 'Radar sobrevivente inválido.');
                return $this->redirectToRoute('radar_index');
            }

            // fieldChoices: [ campo => 'survivor' | 'absorbed_1' | 'absorbed_2' ... ]
            $fieldChoices = (array) $req->request->all('field_choice');

            try {
                $this->mergeService->merge(
                    survivorId:   $survivorId,
                    absorbedIds:  $absorbedIds,
                    fieldChoices: $fieldChoices,
                    mergedBy:     (string) $user->getEmail(),
                );
                $this->addFlash('success', sprintf(
                    'Mesclagem concluída. %d radar(es) absorvido(s) no radar #%d.',
                    count($absorbedIds),
                    $survivorId
                ));
            } catch (\Throwable $e) {
                $this->addFlash('error', 'Erro ao mesclar: ' . $e->getMessage());
                return $this->redirectToRoute('radar_index');
            }

            return $this->redirectToRoute('radar_show', ['id' => $survivorId]);
        }

        // ── GET: mostrar preview ─────────────────────────────────────────────
        $comparableFields = [
            'sigla_uf'                => 'UF',
            'municipio'               => 'Município',
            'logradouro'              => 'Logradouro',
            'cep'                     => 'CEP',
            'nome_empresa'            => 'Empresa',
            'cnpj_empresa'            => 'CNPJ',
            'tipo_medidor'            => 'Tipo',
            'marca_medidor'           => 'Marca',
            'modelo_medidor'          => 'Modelo',
            'numero_serie'            => 'Nº Série',
            'capacidade'              => 'Capacidade',
            'situacao'                => 'Situação',
            'data_verificacao'        => 'Dt. Verificação',
            'data_ultima_verificacao' => 'Última Verificação',
            'data_validade'           => 'Validade',
            'data_lacre'              => 'Dt. Lacre',
            'lacre'                   => 'Lacre',
            'numero_certificado'      => 'Nº Certificado',
            'orgao_verificador'       => 'Órgão Verificador',
            'latitude'                => 'Latitude',
            'longitude'               => 'Longitude',
            'link_waze'               => 'Link Waze',
        ];

        return $this->render('radar/merge/confirm.html.twig', [
            'radares'          => $radares,
            'ids'              => $ids,
            'comparableFields' => $comparableFields,
        ]);
    }
}
