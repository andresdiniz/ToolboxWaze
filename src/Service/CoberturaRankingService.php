<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class CoberturaRankingService
{
    public function __construct(private readonly Connection $db, #[Autowire(service: 'cache.app')] private readonly CacheInterface $cache) {}

    public function tipos(User $user): array
    {
        $result = [];
        foreach (['radares' => ['Radares oficiais', User::PERMISSION_RADARES], 'manuais' => ['Radares manuais pendentes', User::PERMISSION_RADARES], 'postos' => ['Postos', User::PERMISSION_POSTOS], 'escolas' => ['Escolas', User::PERMISSION_ESCOLAS], 'saude' => ['Saúde', User::PERMISSION_SAUDE]] as $key => [$label, $permission]) {
            if ($user->hasPermission($permission)) $result[$key] = $label;
        }
        return $result;
    }

    public function dados(User $user, string $tipo, string $uf, string $ordem): array
    {
        $tipos = $this->tipos($user);
        if ($tipos === []) throw new AccessDeniedHttpException('Nenhum módulo autorizado.');
        if ($tipo !== '' && !isset($tipos[$tipo])) throw new AccessDeniedHttpException('Tipo não autorizado ou inexistente.');
        if ($uf !== '' && !in_array($uf, User::ALL_UFS, true)) throw new BadRequestHttpException('UF inválida.');
        if ($uf !== '' && !$user->canAccessUf($uf)) throw new AccessDeniedHttpException('UF não autorizada.');
        if (!in_array($ordem, ['percentual', 'volume', 'pendentes'], true)) throw new BadRequestHttpException('Ordenação inválida.');
        // Não depende do comportamento especial de getUfsForQuery() para lista vazia.
        $ufs = array_values(array_filter(User::ALL_UFS, $user->canAccessUf(...)));
        if ($uf !== '') $ufs = [$uf];
        $selecionados = $tipo === '' ? array_keys($tipos) : [$tipo];
        $key = 'cobertura_multi_v1_' . hash('sha256', serialize([$this->db->getDatabase(), $selecionados, $ufs]));
        $data = $this->cache->get($key, function (ItemInterface $entry) use ($selecionados, $ufs): array {
            $entry->expiresAfter(120);
            return $this->consultar($selecionados, $ufs);
        });
        $data['estados'] = self::estados($data['grupos'], $ordem);
        unset($data['grupos']);
        return $data;
    }

    private function consultar(array $tipos, array $ufs): array
    {
        $groups = []; $editors = []; $notices = [];
        $totals = ['total' => 0, 'elegiveis' => 0, 'com_waze' => 0, 'manuais' => 0];
        if ($ufs === []) return ['grupos' => [], 'editores' => [], 'totais' => $totals + ['pct' => null], 'avisos' => ['Nenhuma UF autorizada.']];
        $tables = $this->db->createSchemaManager()->listTableNames();
        $has = static fn(string $name): bool => in_array($name, $tables, true);
        $ph = implode(',', array_fill(0, count($ufs), '?'));
        foreach ($tipos as $tipo) {
            $credits = [];
            $base = '1=1'; $covered = '0';
            if ($tipo === 'radares') {
                $table = 'radar_medidor'; $from = 'radar_medidor r'; $ufCol = 'r.sigla_uf'; $id = 'CAST(r.id AS CHAR)';
                $base = 'r.merged_into_id IS NULL';
                $covered = "NULLIF(TRIM(r.link_waze), '') IS NOT NULL";
                if ($has('radar_waze_link')) {
                    $covered = "($covered OR EXISTS (SELECT 1 FROM radar_waze_link wl WHERE wl.radar_medidor_id = r.id AND NULLIF(TRIM(wl.waze_link), '') IS NOT NULL))";
                    $credits[] = ["$from INNER JOIN radar_waze_link l ON l.radar_medidor_id = r.id LEFT JOIN `user` u ON u.id = l.inserted_by", 'l.inserted_by', 'vinculo', "NULLIF(TRIM(l.waze_link), '') IS NOT NULL", false];
                }
                if ($has('cobertura_verificacao')) $credits[] = ["$from INNER JOIN cobertura_verificacao l ON l.radar_id = r.id LEFT JOIN `user` u ON u.id = l.primeiro_editor_id", 'l.primeiro_editor_id', 'confirmacao', "l.resultado = 'confirmado'", false];
                else $notices[] = 'Radares: tabela de verificações ausente; ranking mantém apenas cadastros de vínculo.';
            } elseif ($tipo === 'postos') {
                $table = 'fuel_reseller_raw'; $from = 'fuel_reseller_raw r'; $ufCol = 'r.uf';
                $cnpj = "NULLIF(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(r.cnpj), '.', ''), '/', ''), '-', ''), ' ', ''), '')";
                $id = "COALESCE(CONCAT('cnpj:', $cnpj), CONCAT('id:', r.id))";
                if ($has('posto_waze_link')) {
                    $covered = "EXISTS (SELECT 1 FROM posto_waze_link wl WHERE wl.posto_id = r.id AND NULLIF(TRIM(wl.waze_link), '') IS NOT NULL)";
                    $credits[] = ["$from INNER JOIN posto_waze_link l ON l.posto_id = r.id LEFT JOIN `user` u ON u.id = l.inserted_by", 'l.inserted_by', 'vinculo', "NULLIF(TRIM(l.waze_link), '') IS NOT NULL", false];
                }
            } elseif ($tipo === 'escolas') {
                $table = 'escola_inep'; $from = 'escola_inep r'; $ufCol = 'r.uf'; $id = 'CAST(r.id AS CHAR)';
                $covered = "(NULLIF(TRIM(r.link_waze), '') IS NOT NULL OR NULLIF(TRIM(r.link_area_escolar), '') IS NOT NULL)";
                if ($has('escola_inep_waze_link_log')) {
                    // Primeiro cadastro não vazio registrado para cada escola/campo.
                    $join = "$from INNER JOIN escola_inep_waze_link_log l ON l.escola_id = r.id LEFT JOIN `user` u ON u.id = l.alterado_por";
                    $condition = "l.campo IN ('link_waze', 'link_area_escolar') AND NULLIF(TRIM(l.valor_novo), '') IS NOT NULL AND l.id = (SELECT MIN(first_log.id) FROM escola_inep_waze_link_log first_log WHERE first_log.escola_id = l.escola_id AND first_log.campo = l.campo AND NULLIF(TRIM(first_log.valor_novo), '') IS NOT NULL) AND ((l.campo = 'link_waze' AND NULLIF(TRIM(r.link_waze), '') IS NOT NULL) OR (l.campo = 'link_area_escolar' AND NULLIF(TRIM(r.link_area_escolar), '') IS NOT NULL))";
                    $credits[] = [$join, 'l.alterado_por', 'vinculo', $condition, false];
                } else $notices[] = 'Escolas: histórico de links ausente; sem atribuição de autoria de vínculos.';
            } elseif ($tipo === 'saude') {
                $table = 'estabelecimento_saude'; $from = 'estabelecimento_saude r'; $ufCol = 'r.uf'; $id = 'CAST(r.id AS CHAR)';
                $covered = "NULLIF(TRIM(r.link_waze), '') IS NOT NULL";
            } else {
                $table = 'radar_manual'; $from = 'radar_manual r'; $ufCol = 'r.sigla_uf'; $id = 'CAST(r.id AS CHAR)';
                $base = "r.status = 'pendente' AND r.radar_medidor_id IS NULL";
                $credits[] = ["$from LEFT JOIN `user` u ON u.id = r.criado_por_id", 'r.criado_por_id', 'cadastro_manual', '1=1', false];
            }
            if (!$has($table)) { $notices[] = $tipo . ': tabela indisponível; categoria não contabilizada.'; continue; }
            $scope = "$base AND UPPER(TRIM($ufCol)) IN ($ph)";
            $rows = $this->db->fetchAllAssociative("SELECT UPPER(TRIM($ufCol)) AS uf, COUNT(*) AS total, SUM(tem_link) AS com_waze FROM (SELECT $ufCol, $id AS local_id, MAX(CASE WHEN $covered THEN 1 ELSE 0 END) AS tem_link FROM $from WHERE $scope GROUP BY $ufCol, $id) r GROUP BY UPPER(TRIM($ufCol))", $ufs);
            // O alias da subconsulta precisa expor uma coluna UF uniforme.
            foreach ($rows as $row) {
                $row['tipo'] = $tipo; $row['total'] = (int) $row['total']; $row['com_waze'] = (int) $row['com_waze'];
                $row['elegiveis'] = $tipo === 'manuais' ? 0 : $row['total'];
                $groups[] = $row;
                $totals['total'] += $row['total']; $totals['elegiveis'] += $row['elegiveis']; $totals['com_waze'] += $row['com_waze'];
                if ($tipo === 'manuais') $totals['manuais'] += $row['total'];
            }
            if (in_array($tipo, ['saude', 'escolas'], true)) {
                $credits[] = [$from, 'r.reservado_para', 'confirmacao', "r.status = 'confirmado' AND NULLIF(TRIM(r.reservado_para), '') IS NOT NULL", true];
            }
            $parts = []; $params = [];
            foreach ($credits as [$join, $actor, $action, $condition, $declared]) {
                if ($declared) {
                    $editorKey = "CONCAT('api:', LOWER(TRIM($actor)))";
                    $editorName = "TRIM($actor)"; $origin = 'Username declarado pela API';
                } else {
                    $editorKey = "CONCAT('user:', $actor)";
                    $editorName = "COALESCE(NULLIF(TRIM(u.waze_nickname), ''), CONCAT('Editor #', $actor))"; $origin = 'Conta do sistema';
                }
                $parts[] = "SELECT $editorKey AS editor_key, $editorName AS editor, '$origin' AS origem, CONCAT(UPPER(TRIM($ufCol)), ':', $id) AS local_id, '$action' AS acao FROM $join WHERE $scope AND $condition AND $actor IS NOT NULL";
                array_push($params, ...$ufs);
            }
            if ($parts === []) continue;
            $union = implode(' UNION ALL ', $parts);
            $activity = $this->db->fetchAllAssociative("SELECT editor_key, MAX(editor) AS editor, MAX(origem) AS origem, COUNT(DISTINCT local_id) AS locais, COUNT(DISTINCT CASE WHEN acao = 'vinculo' THEN local_id END) AS vinculos, COUNT(DISTINCT CASE WHEN acao = 'confirmacao' THEN local_id END) AS confirmacoes, COUNT(DISTINCT CASE WHEN acao = 'cadastro_manual' THEN local_id END) AS manuais FROM ($union) c GROUP BY editor_key", $params);
            foreach ($activity as $row) {
                $key = $row['editor_key'];
                if (!isset($editors[$key])) $editors[$key] = ['editor_key' => $key, 'editor' => $row['editor'], 'origem' => $row['origem'], 'locais' => 0, 'vinculos' => 0, 'confirmacoes' => 0, 'manuais' => 0, 'tipos' => []];
                foreach (['locais', 'vinculos', 'confirmacoes', 'manuais'] as $metric) $editors[$key][$metric] += (int) $row[$metric];
                $editors[$key]['tipos'][$tipo] = (int) $row['locais'];
            }
        }
        $editors = array_values($editors);
        usort($editors, static fn(array $a, array $b): int => ($b['locais'] <=> $a['locais']) ?: strcmp($a['editor_key'], $b['editor_key']));
        $totals['pct'] = $totals['elegiveis'] > 0 ? round(100 * $totals['com_waze'] / $totals['elegiveis'], 2) : null;
        return ['grupos' => $groups, 'editores' => $editors, 'totais' => $totals, 'avisos' => $notices];
    }

    public static function estados(array $groups, string $order): array
    {
        $result = [];
        foreach ($groups as $row) {
            $uf = $row['uf'];
            if (!isset($result[$uf])) $result[$uf] = ['uf' => $uf, 'total' => 0, 'elegiveis' => 0, 'com_waze' => 0, 'manuais' => 0, 'tipos' => []];
            foreach (['total', 'elegiveis', 'com_waze'] as $metric) $result[$uf][$metric] += (int) $row[$metric];
            if ($row['tipo'] === 'manuais') $result[$uf]['manuais'] += (int) $row['total'];
            $result[$uf]['tipos'][$row['tipo']] = ['total' => (int) $row['total'], 'com_waze' => (int) $row['com_waze']];
        }
        foreach ($result as &$row) {
            $row['pendentes'] = $row['elegiveis'] - $row['com_waze'];
            $row['rate'] = $row['elegiveis'] > 0 ? $row['com_waze'] / $row['elegiveis'] : -1;
            $row['pct'] = $row['rate'] >= 0 ? round(100 * $row['rate'], 2) : null;
        }
        unset($row);
        $result = array_values($result);
        $metric = match ($order) { 'volume' => 'com_waze', 'pendentes' => 'pendentes', default => 'rate' };
        usort($result, static fn(array $a, array $b): int => ($b[$metric] <=> $a[$metric]) ?: ($b['com_waze'] <=> $a['com_waze']) ?: strcmp($a['uf'], $b['uf']));
        return $result;
    }
}
