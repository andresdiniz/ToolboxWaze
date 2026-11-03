<?php

declare(strict_types=1);
namespace App\Service;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class CoberturaContribuicaoService
{
    public function __construct(private readonly Connection $db, private readonly CoberturaRankingService $ranking) {}
    public function disponivel(): bool
    {
        return $this->db->createSchemaManager()->tablesExist(['cobertura_contribuicao']);
    }
    public static function acao(string $campo, mixed $antes, mixed $depois): ?string
    {
        $antes = trim((string) ($antes ?? '')); $depois = trim((string) ($depois ?? ''));
        if ($antes === $depois) return null;
        if ($campo === 'status') return match ($depois) {
            'confirmado' => 'confirmacao', 'nao_localizado' => 'nao_localizado', 'pulado' => 'pulado', default => null,
        };
        if (!in_array($campo, ['linkWaze','linkAreaEscolar','wazeLink','waze_link','link_waze','link_area_escolar'], true)) return null;
        return $depois === '' ? 'remocao_vinculo' : ($antes === '' ? 'cadastro_vinculo' : 'manutencao_vinculo');
    }
    public function registrar(array $evento): bool
    {
        try { $this->db->insert('cobertura_contribuicao', $evento); return true; }
        catch (UniqueConstraintViolationException) { return false; }
    }
    public function painel(User $user, string $tipo, string $uf, array $filtros, int $pagina): array
    {
        $tipos = $this->ranking->tipos($user);
        $permitidos = $tipo !== '' && isset($tipos[$tipo]) ? [$tipo] : array_keys($tipos);
        $ufs = array_values(array_filter(User::ALL_UFS, $user->canAccessUf(...)));
        if ($uf !== '') $ufs = in_array($uf, $ufs, true) ? [$uf] : [];
        $inicio = self::data($filtros['desde']); $fim = self::data($filtros['ate']);
        if ($fim < $inicio || (int) $inicio->diff($fim)->days > 366) throw new BadRequestHttpException('Use um período de até 366 dias, com início anterior ou igual ao fim.');
        $acoes = ['cadastro_vinculo','manutencao_vinculo','remocao_vinculo','confirmacao','nao_localizado','pulado','cadastro_manual'];
        if ($filtros['acao'] !== '' && !in_array($filtros['acao'], $acoes, true)) throw new BadRequestHttpException('Ação inválida.');
        if ($filtros['origem'] !== '' && !in_array($filtros['origem'], ['api','web','historico'], true)) throw new BadRequestHttpException('Origem inválida.');
        $empty = ['disponivel'=>false,'editores'=>[],'eventos'=>[],'resumo'=>['eventos'=>0,'locais'=>0,'editores'=>0,'api'=>0],'pagina'=>1,'paginas'=>1,'total'=>0];
        if (!$this->disponivel()) return $empty;
        $empty['disponivel'] = true;
        if ($permitidos === [] || $ufs === []) return $empty;
        $ph = static fn(array $v): string => implode(',', array_fill(0,count($v),'?'));
        $where = ['tipo IN (' . $ph($permitidos) . ')', 'uf IN (' . $ph($ufs) . ')', 'ocorrido_em >= ?', 'ocorrido_em < ?'];
        $params = array_merge($permitidos, $ufs, [$inicio->format('Y-m-d H:i:s'), $fim->modify('+1 day')->format('Y-m-d H:i:s')]);
        foreach (['acao','origem'] as $key) if ($filtros[$key] !== '') { $where[] = $key . ' = ?'; $params[] = $filtros[$key]; }
        if ($filtros['editor'] !== '') {
            $where[] = '(editor LIKE ? OR username_declarado LIKE ?)';
            $params[] = '%' . $filtros['editor'] . '%'; $params[] = '%' . $filtros['editor'] . '%';
        }
        $sql = implode(' AND ', $where);
        $resumo = $this->db->fetchAssociative("SELECT COUNT(*) AS eventos, COUNT(DISTINCT tipo,local_id) AS locais, COUNT(DISTINCT actor_id) AS editores, COALESCE(SUM(origem='api'),0) AS api FROM cobertura_contribuicao WHERE $sql", $params);
        $total = (int) ($resumo['eventos'] ?? 0); $paginas = max(1,(int) ceil($total/25)); $pagina = min($paginas,max(1,$pagina)); $offset = ($pagina-1)*25;
        $eventos = $this->db->fetchAllAssociative("SELECT * FROM cobertura_contribuicao WHERE $sql ORDER BY ocorrido_em DESC,id DESC LIMIT 25 OFFSET $offset", $params);
        $editores = $this->db->fetchAllAssociative("SELECT actor_id, MAX(editor) AS editor, COUNT(DISTINCT tipo,local_id) AS locais, COUNT(*) AS eventos, SUM(origem='api') AS api, COUNT(DISTINCT CASE WHEN acao='cadastro_vinculo' THEN CONCAT(tipo,':',local_id) END) AS cadastros, COUNT(DISTINCT CASE WHEN acao='manutencao_vinculo' THEN CONCAT(tipo,':',local_id) END) AS manutencoes, COUNT(DISTINCT CASE WHEN acao='confirmacao' THEN CONCAT(tipo,':',local_id) END) AS confirmacoes FROM cobertura_contribuicao WHERE $sql AND actor_id IS NOT NULL GROUP BY actor_id ORDER BY locais DESC,actor_id ASC LIMIT 100", $params);
        return compact('editores','eventos','resumo','pagina','paginas','total') + ['disponivel'=>true];
    }
    private static function data(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d') !== $value) throw new BadRequestHttpException('Datas devem usar AAAA-MM-DD.');
        return $date;
    }
}
