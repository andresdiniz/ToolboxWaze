<?php

namespace App\Controller;

use App\Entity\FormBuilderResposta;
use App\Repository\FormBuilderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Renderiza e processa formulários públicos criados pelo Form Builder.
 * Acessível para qualquer visitante (sem autenticação obrigatória),
 * mas o formulário pode restringir por `mostrar_para`.
 */
final class FormPublicoController extends AbstractController
{
    public function __construct(
        private readonly FormBuilderRepository $formRepo,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/forms/{slug}', name: 'form_publico', methods: ['GET', 'POST'])]
    public function show(string $slug, Request $request): Response
    {
        $form = $this->formRepo->findBySlugAtivo($slug)
            ?? throw $this->createNotFoundException('Formulário não encontrado.');

        $config = $form->getConfiguracoes() ?? [];

        // Regra mostrar_para
        $mostrarPara = $config['mostrar_para'] ?? 'todos';
        if ($mostrarPara === 'logado' && !$this->getUser()) {
            return $this->redirectToRoute('app_login');
        }
        if ($mostrarPara === 'visitante' && $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        // Expiração
        if (!empty($config['expira_em'])) {
            $expira = new \DateTimeImmutable($config['expira_em']);
            if (new \DateTimeImmutable() > $expira) {
                return $this->render('form_builder/expirado.html.twig', ['form' => $form]);
            }
        }

        $campos  = $form->getCampos()->toArray();
        $erros   = [];
        $sucesso = false;

        if ($request->isMethod('POST')) {
            [$dados, $erros] = $this->processar($campos, $request, $config);

            if (empty($erros)) {
                $resposta = new FormBuilderResposta();
                $resposta->setFormulario($form);
                $resposta->setDados($dados);
                $resposta->setIp($request->getClientIp());
                if ($this->getUser()) {
                    $resposta->setUsuario($this->getUser());
                }
                $this->em->persist($resposta);
                $this->em->flush();

                $sucesso = true;

                if (!empty($config['redirect_url'])) {
                    return $this->redirect($config['redirect_url']);
                }
            }
        }

        return $this->render('form_builder/render.html.twig', [
            'form'    => $form,
            'campos'  => $campos,
            'erros'   => $erros,
            'sucesso' => $sucesso,
            'preview' => false,
        ]);
    }

    /**
     * Valida e retorna [dados_limpos, erros].
     */
    private function processar(array $campos, Request $request, array $config): array
    {
        $dados = [];
        $erros = [];

        foreach ($campos as $campo) {
            $tipo  = $campo->getTipo();
            $chave = $campo->getChave();

            // Campos de layout não têm valor
            if (in_array($tipo, ['html', 'divider', 'heading'])) {
                continue;
            }

            // Verifica condicional
            $opcoes     = $campo->getOpcoes() ?? [];
            $condicional = $opcoes['condicional'] ?? null;
            if ($condicional && !$this->avaliarCondicional($condicional, $dados)) {
                continue;
            }

            if ($tipo === 'checkbox' || $tipo === 'select_multiple') {
                $valor = $request->request->all($chave) ?: [];
            } elseif ($tipo === 'file' || $tipo === 'image') {
                $arquivo = $request->files->get($chave);
                $valor   = $arquivo ? $arquivo->getClientOriginalName() : '';
                // TODO: mover arquivo para uploads/form_builder/{form_id}/
            } else {
                $valor = trim($request->request->get($chave, $campo->getValorPadrao() ?? ''));
            }

            // Obrigatoriedade
            if ($campo->isObrigatorio() && ($valor === '' || $valor === [] || $valor === null)) {
                $erros[$chave] = $campo->getLabel() . ' é obrigatório.';
                continue;
            }

            // Validação por tipo
            if ($valor !== '' && $valor !== []) {
                $erro = $this->validarTipo($tipo, $valor, $campo->getLabel(), $opcoes);
                if ($erro) {
                    $erros[$chave] = $erro;
                    continue;
                }

                // Regex customizado
                if (!empty($opcoes['validacao']) && is_string($valor)) {
                    if (!preg_match($opcoes['validacao'], $valor)) {
                        $erros[$chave] = $campo->getLabel() . ' está em formato inválido.';
                        continue;
                    }
                }
            }

            $dados[$chave] = $valor;
        }

        return [$dados, $erros];
    }

    private function validarTipo(string $tipo, mixed $valor, string $label, array $opcoes): ?string
    {
        return match($tipo) {
            'email'  => filter_var($valor, FILTER_VALIDATE_EMAIL) ? null : "$label deve ser um e-mail válido.",
            'url'    => filter_var($valor, FILTER_VALIDATE_URL)   ? null : "$label deve ser uma URL válida.",
            'number' => (function() use ($valor, $label, $opcoes) {
                if (!is_numeric($valor)) return "$label deve ser um número.";
                if (isset($opcoes['min']) && $valor < $opcoes['min']) return "$label deve ser no mínimo {$opcoes['min']}";
                if (isset($opcoes['max']) && $valor > $opcoes['max']) return "$label deve ser no máximo {$opcoes['max']}";
                return null;
            })(),
            default => null,
        };
    }

    private function avaliarCondicional(array $cond, array $dadosAtuais): bool
    {
        $chaveCond = $cond['campo'] ?? '';
        $op        = $cond['operador'] ?? '=';
        $valCond   = $cond['valor'] ?? '';
        $atual     = $dadosAtuais[$chaveCond] ?? '';

        return match($op) {
            '='        => $atual == $valCond,
            '!='       => $atual != $valCond,
            '>'        => $atual > $valCond,
            '<'        => $atual < $valCond,
            'contains' => str_contains((string)$atual, (string)$valCond),
            default    => true,
        };
    }
}
