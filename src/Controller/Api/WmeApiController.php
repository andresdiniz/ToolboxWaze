<?php
declare(strict_types=1);
namespace App\Controller\Api;
use App\Entity\{User, Solicitacao, SolicitacaoHistorico, Notificacao};
use App\Message\EnviarEmailSolicitacao;
use App\Service\WmeFormularioService;
use App\Repository\TipoSolicitacaoConfigRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, JsonResponse, BinaryFileResponse, ResponseHeaderBag};
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Messenger\MessageBusInterface;
#[Route('/api/wme', name: 'api_wme_')]
final class WmeApiController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly WmeFormularioService $forms, private readonly TipoSolicitacaoConfigRepository $configs, private readonly MessageBusInterface $bus, private readonly LoggerInterface $logger) {}
    private function user(Request $r): User
    {
        $token = trim((string)$r->headers->get('X-API-TOKEN'));
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) throw new HttpException(401, 'Token pessoal obrigatorio.');
        $u = $this->getUser();
        if (!$u instanceof User || !$u->isApproved() || !hash_equals((string)$u->getApiToken(), $token)) throw new HttpException(401, 'Token invalido ou conta nao aprovada.');
        return $u;
    }
    private function run(callable $action): JsonResponse
    {
        try { $result = $action(); return $this->json($result)->setPrivate()->setMaxAge(0); }
        catch (HttpException $e) { return $this->json(['message'=>$e->getMessage()], $e->getStatusCode()); }
        catch (\Throwable $e) { $this->logger->error('WME API: {class}', ['class'=>$e::class]); return $this->json(['message'=>'Falha interna. Consulte o envio antes de repetir.'], 500); }
    }
    #[Route('/bootstrap', name: 'bootstrap', methods: ['GET'])]
    public function bootstrap(Request $r): JsonResponse
    {
        return $this->run(function () use ($r) { $u=$this->user($r); return ['usuario'=>['id'=>$u->getId(),'nome'=>$u->getName(),'wazeNickname'=>$u->getWazeNickname()], 'formularios'=>$this->forms->available($u)]; });
    }
    #[Route('/formularios/{slug}', name: 'formulario', methods: ['GET'])]
    public function formulario(string $slug, Request $r): JsonResponse
    {
        return $this->run(fn()=> $this->forms->schema($this->forms->get($slug, $this->user($r))));
    }
    private function key(User $u, string $uuid): string
    {
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/Di', $uuid)) throw new HttpException(400, 'clientRequestId invalido.');
        return $u->getId().':'.strtolower($uuid);
    }
    private function lock(User $u): void
    {
        $this->em->getConnection()->executeQuery('SELECT id FROM `user` WHERE id = ? FOR UPDATE', [$u->getId()])->fetchOne();
    }
    private function outcome(array $row): array
    {
        if ($row['estado'] !== 'concluido') return ['estado'=>$row['estado']];
        return ['estado'=>'concluido','solicitacao'=>['id'=>(int)$row['solicitacao_id'],'url'=>$this->generateUrl('solicitacao_detalhe', ['id'=>$row['solicitacao_id']])]];
    }
    #[Route('/envios/{clientRequestId}', name: 'envio', methods: ['GET'])]
    public function envio(string $clientRequestId, Request $r): JsonResponse
    {
        return $this->run(function () use ($clientRequestId,$r) {
            $u=$this->user($r); $key=$this->key($u,$clientRequestId); $db=$this->em->getConnection(); $db->beginTransaction();
            try {
                $this->lock($u); $row=$db->fetchAssociative('SELECT * FROM wme_envio WHERE id = ?',[$key]);
                if (!$row) {
                    $db->insert('wme_envio',['id'=>$key,'user_id'=>$u->getId(),'hash'=>str_repeat('0',64),'estado'=>'nao_criado','solicitacao_id'=>null,'criado_em'=>date('Y-m-d H:i:s')]);
                    $row=['estado'=>'nao_criado'];
                }
                $db->commit(); return $this->outcome($row);
            } catch (\Throwable $e) { if ($db->isTransactionActive()) $db->rollBack(); throw $e; }
        });
    }
    #[Route('/solicitacoes', name: 'criar', methods: ['POST'])]
    public function criar(Request $r): JsonResponse
    {
        return $this->run(function () use ($r) {
            $u=$this->user($r); $raw=$r->request->get('payload');
            if (!is_string($raw) || strlen($raw)>262144) throw new HttpException(400,'Payload ausente ou maior que 256 KB.');
            try { $p=json_decode($raw,true,32,JSON_THROW_ON_ERROR); } catch (\JsonException) { throw new HttpException(400,'JSON invalido.'); }
            if (!is_array($p) || !is_string($p['formulario'] ?? null) || !is_string($p['clientRequestId'] ?? null) || !is_array($p['respostas'] ?? null)) throw new HttpException(400,'Payload invalido.');
            $key=$this->key($u,$p['clientRequestId']); $form=$this->forms->get($p['formulario'],$u); $schema=$this->forms->schema($form);
            if (($p['versaoFormulario'] ?? '')!==$schema['versao']) throw new HttpException(422,'Formulario atualizado. Recarregue sua definicao.');
            [$clean,$files,$errors]=$this->forms->validate($schema,$p['respostas'],$r->files->all('anexos'));
            if ($errors) return ['message'=>'Revise os campos.','errors'=>$errors,'_validation'=>true];
            $digest=[]; foreach($files as $k=>$list) foreach($list as $f) $digest[$k][]=hash_file('sha256',$f->getPathname());
            $hash=hash('sha256',json_encode([$p,$digest],JSON_THROW_ON_ERROR));
            $db=$this->em->getConnection(); $db->beginTransaction(); $moved=[]; $responsaveis=[];
            try {
                $this->lock($u); $row=$db->fetchAssociative('SELECT * FROM wme_envio WHERE id = ?',[$key]);
                if ($row) {
                    if ($row['estado']==='nao_criado' || !hash_equals($row['hash'],$hash)) throw new HttpException(409,'Chave encerrada ou reutilizada com outro conteudo.');
                    $db->commit(); return $this->outcome($row);
                }
                if ((int)$db->fetchOne('SELECT COUNT(*) FROM wme_envio WHERE user_id = ? AND estado = ? AND criado_em > ?',[$u->getId(),'concluido',date('Y-m-d H:i:s',time()-60)])>=10) throw new HttpException(429,'Limite de dez envios por minuto.');
                $this->forms->get($p['formulario'],$u);
                $limit=(int)(($form->getConfiguracoes() ?? [])['limite_envios'] ?? 1);
                if ($limit>0 && $this->em->getRepository(Solicitacao::class)->count(['formulario'=>$form,'solicitanteEmail'=>$u->getEmail()])>=$limit) throw new HttpException(422,'Limite de envios deste formulario atingido.');
                $saved=[]; $dir=$this->getParameter('kernel.project_dir').'/var/wme_uploads';
                foreach($files as $field=>$list) foreach($list as $file) {
                    if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) throw new \RuntimeException('Storage indisponivel.');
                    $ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$file->getMimeType()];
                    $name=bin2hex(random_bytes(20)).'.'.$ext; $mime=$file->getMimeType(); $size=$file->getSize();
                    $file->move($dir,$name); $moved[]=$dir.'/'.$name;
                    $saved[]=['campo'=>$field,'nome'=>$name,'mime'=>$mime,'bytes'=>$size];
                }
                $sol=new Solicitacao(); $sol->setFormulario($form)->setTipo($form->getSlug())->setStatus(Solicitacao::STATUS_PENDENTE)->setSolicitanteNome($u->getName() ?: $u->getEmail())->setSolicitanteUsuario($u->getWazeNickname() ?: $u->getEmail())->setSolicitanteEmail($u->getEmail())->setDadosDinamicos($clean)->setDados(['_wme'=>['contexto'=>$p['contexto'] ?? null,'anexos'=>$saved,'clientRequestId'=>$p['clientRequestId']]]);
                $config=$this->configs->findByTipo($form->getSlug());
                if ($config) foreach($config->getResponsaveisDefault() as $responsavel) { $sol->addResponsavel($responsavel); $responsaveis[]=$responsavel; }
                $hist=new SolicitacaoHistorico(); $hist->setSolicitacao($sol); $hist->setStatusAnterior(null); $hist->setStatusNovo(Solicitacao::STATUS_PENDENTE); $hist->setAutor($u); $hist->setNota('Solicitacao criada via API WME.'); $sol->addHistorico($hist);
                $this->em->persist($sol); $this->em->persist($hist); $this->em->flush();
                foreach($responsaveis as $responsavel) { $n=new Notificacao(); $n->setUsuario($responsavel); $n->setSolicitacao($sol); $n->setTipo(Notificacao::TIPO_NOVA_SOLICITACAO); $n->setMensagem('Nova solicitacao WME #'.$sol->getId()); $this->em->persist($n); }
                $this->em->flush();
                $db->insert('wme_envio',['id'=>$key,'user_id'=>$u->getId(),'hash'=>$hash,'estado'=>'concluido','solicitacao_id'=>$sol->getId(),'criado_em'=>date('Y-m-d H:i:s')]);
                $db->commit();
            } catch (\Throwable $e) { if ($db->isTransactionActive()) $db->rollBack(); foreach($moved as $path) @unlink($path); $this->em->clear(); throw $e; }
            try {
                $this->bus->dispatch(new EnviarEmailSolicitacao('confirmacao',$sol->getId()));
                foreach($responsaveis as $responsavel) $this->bus->dispatch(new EnviarEmailSolicitacao('responsavel',$sol->getId(),$responsavel->getId()));
            } catch (\Throwable $e) { $this->logger->error('WME: e-mail nao despachado para solicitacao {id}', ['id'=>$sol->getId()]); }
            return $this->outcome(['estado'=>'concluido','solicitacao_id'=>$sol->getId()]);
        });
    }
    #[Route('/solicitacoes/{id}/anexos/{nome}', name: 'anexo', methods: ['GET'], requirements: ['id'=>'\d+', 'nome'=>'[a-f0-9]{40}\.(jpg|png|webp)'])]
    public function anexo(int $id, string $nome, Request $r): BinaryFileResponse
    {
        $u=$this->user($r); $sol=$this->em->find(Solicitacao::class,$id);
        if (!$sol) throw new HttpException(404);
        $this->denyAccessUnlessGranted('SOLICITACAO_VER',$sol);
        $meta=null; foreach(($sol->getDados()['_wme']['anexos'] ?? []) as $a) if ($a['nome']===$nome) $meta=$a;
        if (!$meta) throw new HttpException(404);
        $response=new BinaryFileResponse($this->getParameter('kernel.project_dir').'/var/wme_uploads/'.$nome);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT,$nome);
        $response->headers->set('Content-Type',$meta['mime']); $response->headers->set('X-Content-Type-Options','nosniff'); $response->headers->set('Cache-Control','private, no-store');
        return $response;
    }
}
