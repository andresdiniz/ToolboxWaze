<?php

namespace App\Form;

use App\Entity\Solicitacao;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{
    ChoiceType, EmailType, FileType, TextareaType, TextType, CheckboxType
};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class SolicitacaoType extends AbstractType
{
    private const UFS = [
        'AC'=>'AC','AL'=>'AL','AM'=>'AM','AP'=>'AP','BA'=>'BA',
        'CE'=>'CE','DF'=>'DF','ES'=>'ES','GO'=>'GO','MA'=>'MA',
        'MG'=>'MG','MS'=>'MS','MT'=>'MT','PA'=>'PA','PB'=>'PB',
        'PE'=>'PE','PI'=>'PI','PR'=>'PR','RJ'=>'RJ','RN'=>'RN',
        'RO'=>'RO','RR'=>'RR','RS'=>'RS','SC'=>'SC','SE'=>'SE',
        'SP'=>'SP','TO'=>'TO',
    ];

    private const PERMALINK_PATTERN = '/
        https?:\/\/
        (?:www\.|beta\.)?waze\.com
        (?:\/[a-zA-Z]{2,3}(?:[-_][a-zA-Z0-9]{2,8})?)?
        \/editor
        [^\s]*
        [?&]zoom(?:Level)?=
        (1[5-9]|[2-9]\d|\d{3,})
        [^\s]*
    /xi';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('tipo', ChoiceType::class, [
                'label'       => 'Tipo de solicitação',
                'choices'     => array_flip(Solicitacao::TIPOS),
                'placeholder' => 'Escolher',
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('solicitanteNome', TextType::class, [
                'label'       => 'Nome',
                'attr'        => ['placeholder' => 'Preencha apenas o seu primeiro nome'],
                'constraints' => [new Assert\NotBlank(), new Assert\Length(['max' => 255])],
            ])
            ->add('solicitanteUsuario', TextType::class, [
                'label'       => 'Nome de usuário (WME)',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(['max' => 255])],
            ])
            ->add('solicitanteEmail', EmailType::class, [
                'label'       => 'E-mail',
                'constraints' => [new Assert\NotBlank(), new Assert\Email()],
            ])
            ->add('estado', ChoiceType::class, [
                'label'       => 'Estado',
                'choices'     => self::UFS,
                'placeholder' => 'Escolher',
                'required'    => false,
            ]);

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $solicitacao = $event->getData();
            $tipo = null;
            if ($solicitacao instanceof Solicitacao) {
                try { $tipo = $solicitacao->getTipo(); } catch (\Error) { $tipo = null; }
            }
            $this->addDadosDinamicos($event->getForm(), $tipo);
        });

        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) {
            $tipo  = $event->getData()['tipo']        ?? null;
            $cargo = $event->getData()['dados_cargo'] ?? null;
            $this->addDadosDinamicos($event->getForm(), $tipo, $cargo);
        });
    }

    private function addDadosDinamicos(\Symfony\Component\Form\FormInterface $form, ?string $tipo, ?string $cargo = null): void
    {
        match ($tipo) {
            Solicitacao::TIPO_IMAGEM_SATELITE     => $this->addCamposImagemSatelite($form),
            Solicitacao::TIPO_GERENTE_AREA        => $this->addCamposGerenteArea($form),
            Solicitacao::TIPO_GERENTE_ESTADO_PAIS => $this->addCamposGerenteEstadoPais($form, $cargo),
            Solicitacao::TIPO_NIVEL               => $this->addCamposNivel($form),
            Solicitacao::TIPO_OOPS                => $this->addCamposOops($form),
            Solicitacao::TIPO_BANDEIRA_POSTO      => $this->addCamposBandeiraPosto($form),
            Solicitacao::TIPO_ID_SEGMENTO         => $this->addCamposIdSegmento($form),
            default                               => null,
        };
    }

    private function permalinkConstraints(bool $required = true): array
    {
        $constraints = [
            new Assert\Regex([
                'pattern' => self::PERMALINK_PATTERN,
                'message' => 'O permalink deve ser do editor Waze com zoom ≥15. Ex: https://waze.com/pt-BR/editor?env=row&lat=-20&lon=-43&zoomLevel=15',
            ]),
        ];
        if ($required) {
            array_unshift($constraints, new Assert\NotBlank(message: 'O permalink é obrigatório.'));
        }
        return $constraints;
    }

    // -------------------------------------------------------------------------
    // IMAGEM DE SATÉLITE
    // -------------------------------------------------------------------------
    private function addCamposImagemSatelite(\Symfony\Component\Form\FormInterface $form): void
    {
        $form
            ->add('dados_motivo', ChoiceType::class, [
                'label'  => 'Motivo da solicitação',
                'mapped' => false,
                'choices' => [
                    'A imagem com maior resolução é muito antiga' => 'muito_antiga',
                    'A imagem está com qualidade/resolução baixa'  => 'baixa_qualidade',
                    'Alterações significativas na área'            => 'alteracoes',
                    'As imagens estão ruins ou nubladas'           => 'ruins_nubladas',
                ],
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('dados_idadeImagem', ChoiceType::class, [
                'label'  => 'Quão antiga pode ser a imagem atualizada?',
                'mapped' => false,
                'choices' => ['1 ano' => '1_ano', '6 meses' => '6_meses', '1 mês' => '1_mes', '1 semana' => '1_semana'],
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('dados_urgente', ChoiceType::class, [
                'label'    => 'É urgente?',
                'mapped'   => false,
                'choices'  => ['Sim' => 'sim', 'Não' => 'nao'],
                'expanded' => true,
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('dados_permalink', TextType::class, [
                'label'  => 'Permalink (zoom ≥15)',
                'mapped' => false,
                'attr'   => [
                    'placeholder'    => 'https://waze.com/pt-BR/editor?env=row&lat=-20.255&lon=-43.224&zoomLevel=15',
                    'data-permalink' => '1',
                    'autocomplete'   => 'off',
                    'spellcheck'     => 'false',
                ],
                'help'        => 'Abra o WME, navegue até a área com zoom ≥15 e copie a URL completa do navegador. A URL deve ser do editor Waze (waze.com/editor).',
                'constraints' => $this->permalinkConstraints(required: true),
            ]);
    }

    // -------------------------------------------------------------------------
    // GERENTE DE ÁREA
    // Ordem: Mentor > Recomendadores > Comentários > Polígono > Comuniquei
    // -------------------------------------------------------------------------
    private function addCamposGerenteArea(\Symfony\Component\Form\FormInterface $form): void
    {
        $form
            ->add('dados_mentor', TextType::class, [
                'label'       => 'Mentor',
                'mapped'      => false,
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('dados_recomendadores', TextType::class, [
                'label'    => 'Editores recomendadores',
                'mapped'   => false,
                'required' => false,
            ])
            ->add('dados_comentarios', TextareaType::class, [
                'label'    => 'Comentários',
                'mapped'   => false,
                'required' => false,
                'attr'     => ['rows' => 3],
                'help'     => 'Caso necessário, adicione informações relevantes para a sua solicitação.',
            ])
            ->add('dados_poligono', TextareaType::class, [
                'label'  => 'Polígono da área',
                'mapped' => false,
                'attr'   => ['rows' => 4],
                'help'   => 'Cole o polígono gerado por uma dessas ferramentas:<br>'
                          . '• <a href="https://arthur-e.github.io/Wicket/sandbox-gmaps3.html" target="_blank" rel="noopener">arthur-e.github.io/Wicket</a><br>'
                          . '• <a href="http://map.wazedev.com/" target="_blank" rel="noopener">map.wazedev.com</a><br>'
                          . 'O valor deve iniciar com <strong>POLYGON((</strong> ou <strong>LINESTRING(</strong>.',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Regex([
                        'pattern' => '/^(POLYGON|LINESTRING)\s*\(/i',
                        'message' => 'O polígono deve iniciar com POLYGON(( ou LINESTRING(',
                    ]),
                ],
            ])
            ->add('dados_comunicouIntencao', CheckboxType::class, [
                'label'    => 'Comuniquei minha intenção no Discuss do Estado',
                'mapped'   => false,
                'required' => true,
                'help'     => 'Busque o seu Estado e em seguida no tópico \'Gerente de área ou candidato, se apresente aqui\', publique sua intenção.',
                'constraints' => [new Assert\IsTrue(message: 'Você deve comunicar sua intenção no Discuss antes de enviar.')],
            ]);
    }

    // -------------------------------------------------------------------------
    // GERENTE DE ESTADO OU PAÍS
    // Campo dados_uf só é adicionado quando cargo = gerente_estado (nunca no PRE_SET_DATA)
    // -------------------------------------------------------------------------
    private function addCamposGerenteEstadoPais(\Symfony\Component\Form\FormInterface $form, ?string $cargo = null): void
    {
        $form
            ->add('dados_acao', ChoiceType::class, [
                'label'    => 'Você deseja…',
                'mapped'   => false,
                'choices'  => ['Incluir' => 'incluir', 'Excluir' => 'excluir'],
                'expanded' => true,
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('dados_mentor', TextType::class, [
                'label'       => 'Mentor',
                'mapped'      => false,
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('dados_recomendadores', TextType::class, [
                'label'    => 'Editores recomendadores',
                'mapped'   => false,
                'required' => false,
            ])
            ->add('dados_cargo', ChoiceType::class, [
                'label'    => 'Cargo desejado',
                'mapped'   => false,
                'choices'  => ['Gerente de Estado' => 'gerente_estado', 'Gerente de País' => 'gerente_pais'],
                'expanded' => true,
                'constraints' => [new Assert\NotBlank()],
                'attr'     => ['data-cargo-select' => '1'],
            ]);

        // Campo UF só aparece (e é obrigatório) quando o cargo submetido é gerente_estado.
        // No PRE_SET_DATA ($cargo === null) não é adicionado — o JS no frontend
        // exibe/esconde o campo via data-cargo-select sem precisar de reload.
        if ($cargo === 'gerente_estado') {
            $form->add('dados_uf', ChoiceType::class, [
                'label'       => 'Estado',
                'mapped'      => false,
                'choices'     => self::UFS,
                'placeholder' => 'Escolher',
                'required'    => true,
                'constraints' => [new Assert\NotBlank(message: 'Selecione o estado.')],
                'attr'        => ['data-uf-gerente' => '1'],
            ]);
        }

        $form->add('dados_comentarios', TextareaType::class, [
            'label'    => 'Comentários',
            'mapped'   => false,
            'required' => false,
        ]);
    }

    // -------------------------------------------------------------------------
    // NÍVEL (UPGRADE / DOWNGRADE)
    // -------------------------------------------------------------------------
    private function addCamposNivel(\Symfony\Component\Form\FormInterface $form): void
    {
        $form
            ->add('dados_tipoNivel', ChoiceType::class, [
                'label'    => 'É um pedido de…',
                'mapped'   => false,
                'choices'  => ['Upgrade' => 'upgrade', 'Downgrade' => 'downgrade'],
                'expanded' => true,
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('dados_mentor', TextType::class, [
                'label'       => 'Mentor',
                'mapped'      => false,
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('dados_recomendadores', TextType::class, [
                'label'    => 'Editores recomendadores',
                'mapped'   => false,
                'required' => false,
            ])
            ->add('dados_nivelAtual', TextType::class, [
                'label'  => 'Nível atual',
                'mapped' => false,
                'attr'   => ['placeholder' => 'Digite o seu nível de um a cinco'],
                'help'   => 'Digite o seu nível de um a cinco.',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Range(['min' => 1, 'max' => 5]),
                ],
            ])
            ->add('dados_nivelDesejado', TextType::class, [
                'label'  => 'Nível desejado',
                'mapped' => false,
                'attr'   => ['placeholder' => 'Digite o nível desejado de dois a seis'],
                'help'   => 'Digite o nível desejado de dois a seis.',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Range(['min' => 2, 'max' => 6]),
                ],
            ])
            ->add('dados_ativo', ChoiceType::class, [
                'label'    => 'Você se considera ativo na comunidade?',
                'mapped'   => false,
                'choices'  => ['Sim' => 'sim', 'Não' => 'nao'],
                'expanded' => true,
                'help'     => 'Indique se você se considera ativo nos canais oficiais do Waze.',
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('dados_postagens', ChoiceType::class, [
                'label'  => 'Quantas postagens você fez no último ano no Discuss?',
                'mapped' => false,
                'choices' => [
                    'Entre 1 e 5 postagens'   => '1_5',
                    'Entre 6 e 10 postagens'  => '6_10',
                    'Entre 11 e 20 postagens' => '11_20',
                    'Entre 21 e 50 postagens' => '21_50',
                    'Mais de 50 postagens'    => '50_mais',
                    'Nenhuma.'                => 'nenhuma',
                ],
                'help' => 'Você pode achar essa resposta aqui: '
                        . '<a href="https://www.waze.com/discuss/u/SEU_NICK/activity" target="_blank" rel="noopener">'
                        . 'https://www.waze.com/discuss/u/SEU_NICK/activity</a>',
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('dados_motivacao', TextareaType::class, [
                'label'  => 'O que motiva você a subir de nível?',
                'mapped' => false,
                'attr'   => [
                    'rows'              => 4,
                    'minlength'         => 20,
                    'maxlength'         => 2000,
                    'placeholder'       => 'Descreva sua motivação (mínimo 20 caracteres)',
                    'data-char-counter' => 'true',
                    'data-char-min'     => '20',
                ],
                'help' => 'Explique o que o motiva a buscar um nível mais alto.',
                'constraints' => [
                    new Assert\NotBlank(message: 'Preencha sua motivação.'),
                    new Assert\Length([
                        'min'        => 20,
                        'minMessage' => 'Sua motivação deve ter pelo menos {{ limit }} caracteres.',
                        'max'        => 2000,
                        'maxMessage' => 'Sua motivação não pode ultrapassar {{ limit }} caracteres.',
                    ]),
                ],
            ])
            ->add('dados_cumpriuRequisitos', ChoiceType::class, [
                'label'    => 'Você cumpre os requisitos para essa promoção?',
                'mapped'   => false,
                'choices'  => ['Sim' => 'sim', 'Não' => 'nao'],
                'expanded' => true,
                'help'     => 'Recomenda-se a leitura deste '
                            . '<a href="https://www.waze.com/discuss/t/new-regras-e-normas-para-niveis-cargos-e-areas/282152" '
                            . 'target="_blank" rel="noopener">tópico</a> '
                            . 'para assegurar o atendimento aos requisitos.',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\EqualTo(['value' => 'sim', 'message' => 'Você deve cumprir os requisitos antes de solicitar.']),
                ],
            ]);
    }

    // -------------------------------------------------------------------------
    // OOPS DE EDITOR
    // Ordem: Nome do editor > Provas (upload) > Permalink > Descrição
    // -------------------------------------------------------------------------
    private function addCamposOops(\Symfony\Component\Form\FormInterface $form): void
    {
        $form
            ->add('dados_editorNome', TextType::class, [
                'label'       => 'Qual o nome do editor que cometeu o Oops?',
                'mapped'      => false,
                'help'        => 'Digite o nome do usuário.',
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('dados_provas', FileType::class, [
                'label'    => 'Compartilhe provas',
                'mapped'   => false,
                'required' => false,
                'multiple' => true,
                'help'     => 'Envie até 5 imagens, com tamanho máximo de 1 MB cada.',
                'attr'     => ['accept' => 'image/*', 'data-max-files' => '5'],
                'constraints' => [
                    new Assert\All([
                        'constraints' => [
                            new Assert\File([
                                'maxSize'          => '1M',
                                'maxSizeMessage'   => 'Cada imagem deve ter no máximo 1 MB.',
                                'mimeTypes'        => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
                                'mimeTypesMessage' => 'Envie apenas imagens (JPG, PNG, GIF ou WebP).',
                            ]),
                        ],
                    ]),
                    new Assert\Count(['max' => 5, 'maxMessage' => 'Envie no máximo 5 imagens.']),
                ],
            ])
            ->add('dados_permalink', TextType::class, [
                'label'    => 'Compartilhe permalink',
                'mapped'   => false,
                'required' => false,
                'attr'     => [
                    'placeholder'    => 'https://waze.com/pt-BR/editor?env=row&lat=-20.255&lon=-43.224&zoomLevel=15',
                    'data-permalink' => '1',
                    'autocomplete'   => 'off',
                    'spellcheck'     => 'false',
                ],
                'help'        => 'Acesse o Editor de Mapas (WME) e copie o Permalink.',
                'constraints' => $this->permalinkConstraints(required: false),
            ])
            ->add('dados_descricao', TextareaType::class, [
                'label'  => 'Fundamente as regras infringidas e descreva os fatos',
                'mapped' => false,
                'attr'   => [
                    'rows'              => 5,
                    'minlength'         => 30,
                    'maxlength'         => 5000,
                    'placeholder'       => 'Descreva detalhadamente os fatos e as regras infringidas (mínimo 30 caracteres)',
                    'data-char-counter' => 'true',
                    'data-char-min'     => '30',
                ],
                'help' => 'Inclua todas as informações e detalhes relevantes para facilitar a análise.',
                'constraints' => [
                    new Assert\NotBlank(message: 'Preencha a descrição.'),
                    new Assert\Length([
                        'min'        => 30,
                        'minMessage' => 'A descrição deve ter pelo menos {{ limit }} caracteres.',
                        'max'        => 5000,
                        'maxMessage' => 'A descrição não pode ultrapassar {{ limit }} caracteres.',
                    ]),
                ],
            ]);
    }

    // -------------------------------------------------------------------------
    // BANDEIRA DE POSTO DE GASOLINA
    // -------------------------------------------------------------------------
    private function addCamposBandeiraPosto(\Symfony\Component\Form\FormInterface $form): void
    {
        $form
            ->add('dados_acao', ChoiceType::class, [
                'label'    => 'Você deseja…',
                'mapped'   => false,
                'choices'  => ['Adicionar' => 'adicionar', 'Remover' => 'remover'],
                'expanded' => true,
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('dados_nomeBandeira', TextType::class, [
                'label'       => 'Nome da bandeira',
                'mapped'      => false,
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('dados_cnpj', TextType::class, [
                'label'    => 'CNPJ de um posto ativo com cadastro atualizado',
                'mapped'   => false,
                'required' => false,
                'constraints' => [
                    new Assert\Regex(['pattern' => '/^\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2}$/', 'message' => 'CNPJ inválido']),
                ],
            ]);
    }

    // -------------------------------------------------------------------------
    // CADASTRO DE ID DE SEGMENTO
    // -------------------------------------------------------------------------
    private function addCamposIdSegmento(\Symfony\Component\Form\FormInterface $form): void
    {
        $form
            ->add('dados_nomeSegmento', TextType::class, [
                'label'       => 'Nome do segmento (conforme WME)',
                'mapped'      => false,
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('dados_idSegmento', TextType::class, [
                'label'  => 'ID do segmento (sem permalink)',
                'mapped' => false,
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Regex(['pattern' => '/^\d+$/', 'message' => 'Informe apenas o ID numérico, sem permalink']),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'      => Solicitacao::class,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id'   => 'solicitacao',
        ]);
    }
}
