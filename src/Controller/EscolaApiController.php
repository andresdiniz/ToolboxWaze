<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\EscolaInep;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class EscolaApiController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/escolas/uf/{uf}', name: 'api_escolas_por_uf', methods: ['GET'])]
    public function porUf(string $uf): JsonResponse
    {
        $user = $this->requireApiUser();
        $uf = strtoupper(trim($uf));

        if (!in_array($uf, User::ALL_UFS, true)) {
            return $this->json(['error' => 'UF inválida.'], Response::HTTP_BAD_REQUEST);
        }

        if (!$user->canAccessUf($uf)) {
            return $this->json(['error' => 'Você não tem acesso a esta UF.'], Response::HTTP_FORBIDDEN);
        }

        $escolas = $this->em->getRepository(EscolaInep::class)->findBy(
            ['uf' => $uf],
            ['escola' => 'ASC']
        );

        return $this->json([
            'uf' => $uf,
            'total' => count($escolas),
            'escolas' => array_map($this->serializeEscola(...), $escolas),
        ]);
    }

    #[Route('/api/escolas/{id}', name: 'api_escola_update', methods: ['PATCH', 'POST'])]
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $this->requireApiUser();

        if (!$user->hasPermission(User::PERMISSION_ESCOLAS)) {
            return $this->json(['error' => 'Você não tem permissão para atualizar escolas.'], Response::HTTP_FORBIDDEN);
        }

        $escola = $this->em->find(EscolaInep::class, $id);

        if (!$escola instanceof EscolaInep) {
            return $this->json(['error' => 'Escola não encontrada.'], Response::HTTP_NOT_FOUND);
        }

        if (!$user->canAccessUf((string) $escola->getUf())) {
            return $this->json(['error' => 'Você não tem acesso a esta UF.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json(['error' => 'Corpo JSON inválido.'], Response::HTTP_BAD_REQUEST);
        }

        $alterou = false;

        if (array_key_exists('link_waze', $data)) {
            $linkWaze = $this->normalizeNullableString($data['link_waze']);

            if ($linkWaze !== null && !$this->isWazeEditorLink($linkWaze)) {
                return $this->json(['error' => 'O link_waze deve ser uma URL válida do Waze Map Editor.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $escola->setLinkWaze($linkWaze);
            $alterou = true;
        }

        if (array_key_exists('link_area_escolar', $data)) {
            $linkArea = $this->normalizeNullableString($data['link_area_escolar']);

            if ($linkArea !== null && !$this->isWazeLink($linkArea)) {
                return $this->json(['error' => 'O link_area_escolar deve ser uma URL válida do Waze.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $escola->setLinkAreaEscolar($linkArea);
            $alterou = true;
        }

        if (!$alterou) {
            return $this->json(['error' => 'Informe ao menos um campo para atualizar: link_waze ou link_area_escolar.'], Response::HTTP_BAD_REQUEST);
        }

        $escola->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();

        return $this->json([
            'message' => 'Escola atualizada com sucesso.',
            'escola' => $this->serializeEscola($escola),
        ]);
    }

    private function requireApiUser(): User
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Autenticação por token necessária.');
        }

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeEscola(EscolaInep $escola): array
    {
        return [
            'id' => $escola->getId(),
            'codigo_inep' => $escola->getCodigoInep(),
            'escola' => $escola->getEscola(),
            'uf' => $escola->getUf(),
            'municipio' => $escola->getMunicipio(),
            'localizacao' => $escola->getLocalizacao(),
            'localidade_diferenciada' => $escola->getLocalidadeDiferenciada(),
            'categoria_administrativa' => $escola->getCategoriaAdministrativa(),
            'dependencia_administrativa' => $escola->getDependenciaAdministrativa(),
            'categoria_escola_privada' => $escola->getCategoriaEscolaPrivada(),
            'conveniada' => $escola->getConveniada(),
            'regulamentacao' => $escola->getRegulamentacao(),
            'porte' => $escola->getPorte(),
            'etapas_ensino' => $escola->getEtapasEnsino(),
            'outras_ofertas' => $escola->getOutrasOfertas(),
            'endereco' => $escola->getEndereco(),
            'telefone' => $escola->getTelefone(),
            'latitude' => $escola->getLatitude(),
            'longitude' => $escola->getLongitude(),
            'restricao_atendimento' => $escola->getRestricaoAtendimento(),
            'link_waze' => $escola->getLinkWaze(),
            'permanent_hazard_id' => $escola->getPermanentHazardId(),
            'link_area_escolar' => $escola->getLinkAreaEscolar(),
            'imported_at' => $escola->getImportedAt()->format(DATE_ATOM),
            'updated_at' => $escola->getUpdatedAt()?->format(DATE_ATOM),
        ];
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function isWazeLink(string $url): bool
    {
        return $this->isValidUrl($url)
            && (bool) preg_match('/^https?:\/\/([a-z0-9-]+\.)?waze\.com\//i', $url);
    }

    private function isWazeEditorLink(string $url): bool
    {
        return $this->isWazeLink($url) && str_contains($url, '/editor');
    }

    private function isValidUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
