<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\RadarMedidor;
use App\Message\EnviarEmailRadarRecente;
use App\Repository\RadarMedidorRepository;
use App\Repository\UserRepository;
use App\Service\EmailSender;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Processa a fila de notificações de radares recentes.
 */
#[AsMessageHandler]
final class EnviarEmailRadarRecenteHandler
{
    public function __construct(
        private readonly EmailSender            $emailSender,
        private readonly UserRepository         $userRepo,
        private readonly RadarMedidorRepository $radarRepo,
        private readonly LoggerInterface        $emailQueueLogger,
    ) {}

    public function __invoke(EnviarEmailRadarRecente $message): void
    {
        $context = [
            'sigla_uf'         => $message->siglaUf,
            'user_id'          => $message->userId,
            'quantidade_novos' => $message->quantidadeNovos,
        ];

        try {
            $user = $this->userRepo->find($message->userId);

            if (!$user) {
                $this->emailQueueLogger->warning(
                    'EnviarEmailRadarRecente: usuário não encontrado',
                    $context
                );
                return;
            }

            if (!$user->canAccessUf($message->siglaUf)) {
                $this->emailQueueLogger->warning(
                    'EnviarEmailRadarRecente: usuário sem acesso à UF — e-mail cancelado',
                    $context
                );
                return;
            }

            $radares = $this->resolveRadares($message);

            $subject = sprintf(
                '[ToolboxWaze] %d novo(s) radar(es) adicionado(s) em %s',
                $message->quantidadeNovos,
                $message->siglaUf
            );

            $this->emailSender->send(
                $user->getEmail(),
                $user->getName(),
                $subject,
                $this->buildHtml(
                    $user->getName(),
                    $message->siglaUf,
                    $message->quantidadeNovos,
                    $radares
                )
            );

            $this->emailQueueLogger->info('EnviarEmailRadarRecente: e-mail enviado', array_merge($context, [
                'destinatario' => $user->getEmail(),
                'radares'      => count($radares),
            ]));

        } catch (\Throwable $e) {
            $this->emailQueueLogger->error('EnviarEmailRadarRecente: falha ao enviar', array_merge($context, [
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]));
            throw $e;
        }
    }

    /**
     * @return RadarMedidor[]
     */
    private function resolveRadares(EnviarEmailRadarRecente $message): array
    {
        if ($message->radarIds !== []) {
            return array_filter(
                array_map(fn(int $id) => $this->radarRepo->find($id), $message->radarIds)
            );
        }

        $recentes = $this->radarRepo->findRecentes($message->siglaUf, 30);
        return array_slice($recentes, 0, 20);
    }

    /**
     * @param RadarMedidor[] $radares
     */
    private function buildHtml(string $nome, string $uf, int $quantidade, array $radares): string
    {
        $nome    = htmlspecialchars($nome,    ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $ufSafe  = htmlspecialchars($uf,      ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $baseUrl = $this->emailSender->baseUrl();
        $urlEstado = $baseUrl . '/radares?uf=' . urlencode($uf);

        $tabelaRadares = $this->buildTabelaRadares($radares, $baseUrl);
        $rodapeExtra   = count($radares) < $quantidade
            ? sprintf(
                '<p style="font-size:13px;color:#555">Exibindo os %d mais recentes. '
                . '<a href="%s" style="color:#1a73e8">Ver todos os radares de %s →</a></p>',
                count($radares), $urlEstado, $ufSafe
            )
            : '';

        return <<<HTML
        <!DOCTYPE html>
        <html lang="pt-BR">
        <head><meta charset="UTF-8"></head>
        <body style="font-family:Arial,sans-serif;color:#333;max-width:650px;margin:0 auto;padding:20px">

            <p>Olá, <strong>{$nome}</strong>!</p>

            <p>
                Foram adicionados <strong>{$quantidade} novo(s) radar(es)</strong>
                no estado <strong>{$ufSafe}</strong> nos últimos 30 dias.
            </p>

            {$tabelaRadares}

            {$rodapeExtra}

            <p style="margin-top:24px">
                <a href="{$urlEstado}"
                   style="background:#1a73e8;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;display:inline-block">
                    Ver todos os radares de {$ufSafe}
                </a>
            </p>

            <hr style="border:none;border-top:1px solid #eee;margin:24px 0">
            <p style="font-size:12px;color:#888">Notificação automática do ToolboxWaze. Não responda este e-mail.</p>
        </body>
        </html>
        HTML;
    }

    /**
     * @param RadarMedidor[] $radares
     */
    private function buildTabelaRadares(array $radares, string $baseUrl): string
    {
        if ($radares === []) {
            return '';
        }

        $linhas = '';
        foreach ($radares as $r) {
            $municipio   = htmlspecialchars((string) $r->getMunicipio(),   ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $logradouro  = htmlspecialchars((string) $r->getLogradouro(),  ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $tipo        = htmlspecialchars((string) $r->getTipoMedidor(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $situacao    = htmlspecialchars((string) $r->getSituacao(),    ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $urlRadar    = $baseUrl . '/radares/' . $r->getId();

            $corSituacao = match (strtolower($situacao)) {
                'aprovado', 'ativo', 'válido' => '#2e7d32',
                'vencido', 'reprovado'         => '#c62828',
                default                        => '#555',
            };

            $linhas .= <<<ROW
            <tr style="border-bottom:1px solid #eee">
                <td style="padding:8px 6px">{$municipio}</td>
                <td style="padding:8px 6px">{$logradouro}</td>
                <td style="padding:8px 6px">{$tipo}</td>
                <td style="padding:8px 6px;color:{$corSituacao};font-weight:bold">{$situacao}</td>
                <td style="padding:8px 6px;text-align:center">
                    <a href="{$urlRadar}"
                       style="color:#1a73e8;white-space:nowrap">Ver radar →</a>
                </td>
            </tr>
            ROW;
        }

        return <<<TABLE
        <table style="width:100%;border-collapse:collapse;font-size:13px;margin-top:16px">
            <thead>
                <tr style="background:#f5f5f5;text-align:left">
                    <th style="padding:8px 6px;border-bottom:2px solid #ddd">Município</th>
                    <th style="padding:8px 6px;border-bottom:2px solid #ddd">Logradouro</th>
                    <th style="padding:8px 6px;border-bottom:2px solid #ddd">Tipo</th>
                    <th style="padding:8px 6px;border-bottom:2px solid #ddd">Situação</th>
                    <th style="padding:8px 6px;border-bottom:2px solid #ddd">Link</th>
                </tr>
            </thead>
            <tbody>
                {$linhas}
            </tbody>
        </table>
        TABLE;
    }
}
