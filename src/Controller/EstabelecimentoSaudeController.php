<?php

declare(strict_types=1);
namespace App\Controller;

use App\Entity\EstabelecimentoSaude;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/saude', name: 'saude_')]
#[IsGranted('ROLE_USER')]
final class EstabelecimentoSaudeController extends AbstractController
{
    public function __construct(
        private readonly Connection $db,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->usuario();
        $uf = strtoupper(trim($request->query->getString('uf')));
        $q = trim($request->query->getString('q'));
        if (mb_strlen($q) > 120) throw $this->createNotFoundException('Busca muito longa.');
        if ($uf !== '' && !in_array($uf, User::ALL_UFS, true)) {
            throw $this->createNotFoundException('UF invalida.');
        }
        if ($uf !== '' && !$user->canAccessUf($uf)) {
            throw $this->createAccessDeniedException('UF nao autorizada.');
        }

        $ufs = $user->getUfsForQuery();
        $where = ['1=1'];
        $params = [];
        $ufSql = "JSON_UNQUOTE(JSON_EXTRACT(dados_originais, '$.UF'))";
        if ($uf !== '') { $where[] = "$ufSql = ?"; $params[] = $uf; }
        elseif ($ufs !== null) {
            if ($ufs === []) $where[] = '1=0';
            else {
                $where[] = "$ufSql IN (" . implode(',', array_fill(0, count($ufs), '?')) . ')';
                array_push($params, ...$ufs);
            }
        }
        if ($q !== '') {
            $where[] = "(cnes = ? OR CAST(dados_originais AS CHAR) LIKE ? OR CAST(correcoes AS CHAR) LIKE ?)";
            $params[] = $q;
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
        }
        $where = implode(' AND ', $where);
        $total = (int) $this->db->fetchOne("SELECT COUNT(*) FROM estabelecimento_saude WHERE $where", $params);
        $paginas = max(1, (int) ceil($total / 25));
        $pagina = min($paginas, max(1, $request->query->getInt('pagina', 1)));
        $offset = ($pagina - 1) * 25;
        $itens = $this->db->fetchAllAssociative(
            "SELECT id, cnes, dados_originais, correcoes FROM estabelecimento_saude
             WHERE $where ORDER BY cnes LIMIT 25 OFFSET $offset", $params
        );
        foreach ($itens as &$item) {
            $item['dados'] = array_replace(
                json_decode($item['dados_originais'], true, 512, JSON_THROW_ON_ERROR),
                json_decode($item['correcoes'], true, 512, JSON_THROW_ON_ERROR)
            );
        }
        unset($item);
        return $this->render('saude/index.html.twig', compact('itens', 'total', 'pagina', 'paginas', 'uf', 'q') + [
            'ufs' => $ufs ?? User::ALL_UFS,
        ]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $registro = $this->registro($id);
        return $this->render('saude/show.html.twig', [
            'registro' => $registro, 'dados' => $registro->getDados(),
        ]);
    }

    #[Route('/{id}/editar', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function edit(Request $request, int $id): Response
    {
        $registro = $this->registro($id);
        $dados = $registro->getDados();
        $erros = [];
        $bloqueados = ['CNES', 'UF', 'ANO', 'COMPETÊNCIA'];
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('saude_edit_' . $id, $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Token invalido. Recarregue a pagina.');
            }
            $enviados = $request->request->all('campos');
            foreach ($dados as $campo => $valor) {
                if (in_array($campo, $bloqueados, true)) continue;
                if (!array_key_exists($campo, $enviados) || !is_string($enviados[$campo])) {
                    $erros[] = 'Campo ausente ou invalido: ' . $campo;
                    continue;
                }
                $dados[$campo] = trim($enviados[$campo]);
                if (mb_strlen($dados[$campo]) > 10000) $erros[] = 'Texto muito longo: ' . $campo;
            }
            if (trim((string) ($dados['NOME FANTASIA'] ?? '')) === '') $erros[] = 'Informe o nome fantasia.';
            if (!empty($dados['E-MAIL']) && !filter_var($dados['E-MAIL'], FILTER_VALIDATE_EMAIL)) {
                $erros[] = 'E-mail invalido. Deixe vazio se nao informado.';
            }
            foreach (['DATA DO CADASTRO', 'DATA DE DESATIVAÇÃO'] as $campo) {
                $valor = $dados[$campo] ?? '';
                if ($valor !== '') {
                    $d = \DateTimeImmutable::createFromFormat('!d/m/Y', $valor);
                    if (!$d || $d->format('d/m/Y') !== $valor) $erros[] = 'Data invalida: ' . $campo;
                }
            }
            $location = $dados['location'] ?? '';
            if ($location !== '') {
                $partes = preg_split('/[\s;,]+/', trim($location));
                if (count($partes) !== 2 || !is_numeric($partes[0]) || !is_numeric($partes[1])
                    || !is_finite((float) $partes[0]) || !is_finite((float) $partes[1])
                    || abs((float) $partes[0]) > 90 || abs((float) $partes[1]) > 180) {
                    $erros[] = 'Location: informe latitude e longitude decimais separadas por espaco.';
                }
            }
            if ($erros === []) {
                try {
                    $this->em->lock($registro, \Doctrine\DBAL\LockMode::OPTIMISTIC, $request->request->getInt('versao'));
                    $registro->aplicarCorrecoes($dados, (int) $this->usuario()->getId());
                    $this->em->flush();
                    $this->addFlash('success', 'Estabelecimento atualizado. Dados originais preservados.');
                    return $this->redirectToRoute('saude_show', ['id' => $id]);
                } catch (OptimisticLockException) {
                    return new Response('Outro processo alterou este registro. Reabra a pagina antes de editar.', 409);
                }
            }
        }
        return $this->render('saude/edit.html.twig', compact('registro', 'dados', 'erros', 'bloqueados'));
    }

    private function usuario(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) throw $this->createAccessDeniedException();
        return $user;
    }

    private function registro(int $id): EstabelecimentoSaude
    {
        $registro = $this->em->find(EstabelecimentoSaude::class, $id);
        if (!$registro) throw $this->createNotFoundException('Estabelecimento nao encontrado.');
        if (!$this->usuario()->canAccessUf($registro->getUf())) {
            throw $this->createAccessDeniedException('UF nao autorizada.');
        }
        return $registro;
    }
}
