<?php

namespace App\Form;

use App\Entity\Solicitacao;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{
    ChoiceType, EmailType, TextareaType, TextType, CheckboxType
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
        $isChamp       = $options['is_champ'];
        $ajaxTipoNivel = $options['ajax_tipo_nivel'];
        $ajaxSouChamp  = $options['ajax_sou_champ'];
        $ajaxCargo     = $options['ajax_cargo'];

        $builder
            ->add('tipo', ChoiceType::class, [
                'label'       => 'Tipo de solicitação',
                'choices'     => array_flip(Solicitacao::TIPOS),
                'placeholder' => 'Escolher',
                'constraints' => [new Assert\NotBlank()],
            ]);

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event)
            use ($isChamp, $ajaxTipoNivel, $ajaxSouChamp, $ajaxCargo) {
            $solicitacao = $event->getData();
            $tipo = null;
            if ($solicitacao instanceof Solicitacao) {
                try { $tipo = $solicitacao->getTipo(); } catch (\Error) { $tipo = null; }
            }
            $this->addDadosDinamicos(
                $event->getForm(),
                $tipo,
                null,
                $ajaxTipoNivel,
                $isChamp,
                $ajaxSouChamp,
                null,
                null,
                false,
                $ajaxCargo
            );
        });

        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) use ($isChamp) {
            $data                  = $event->getData();
            $tipo                  = $data['tipo']                        ?? null;
            $cargo                 = $data['dados_cargo']                 ?? null;
            $tipoNivel             = $data['dados_tipoNivel']             ?? null;
            $acaoGerenteEstadoPais = $data['dados_acaoGerenteEstadoPais'] ?? null;

            $souChamp   = !empty($data['dados_souChamp']);
            $souChampEp = !empty($data['dados_souChampEp']);

            if ($isChamp && $tipoNivel === 'downgrade') {
                $souChamp = true;
            }
            if ($isChamp && $acaoGerenteEstadoPais === 'excluir') {
                $souChampEp = true;
            }

            $this->addDadosDinamicos(
                $event->getForm(),
                $tipo,
                $cargo,
                $tipoNivel,
                $isChamp,
                $souChamp,
                null,
                $acaoGerenteEstadoPais,
                $souChampEp,
                null
            );
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'      => Solicitacao::class,
            'is_champ'        => false,
            'ajax_tipo_nivel' => null,
            'ajax_sou_champ'  => false,
            'ajax_cargo'      => null,
        ]);
        $resolver->setAllowedTypes('is_champ', 'bool');
        $resolver->setAllowedValues('ajax_tipo_nivel', [null, 'upgrade', 'downgrade']);
        $resolver->setAllowedTypes('ajax_sou_champ', 'bool');
        $resolver->setAllowedValues('ajax_cargo', [null, 'gerente_estado', 'gerente_pais']);
    }

    private function addDadosDinamicos(
        \Symfony\Component\Form\FormInterface $form,
        ?string $tipo,
        ?string $cargo                 = null,
        ?string $tipoNivel             = null,
        bool    $isChamp               = false,
        bool    $souChamp              = false,
        ?string $acaoGerenteArea       = null,
        ?string $acaoGerenteEstadoPais = null,
        bool    $souChampEp            = false,
        ?string $ajaxCargo             = null
    ): void {
        $cargoEfetivo = $cargo ?? $ajaxCargo;

        match ($tipo) {
            Solicitacao::TIPO_IMAGEM_SATELITE     => $this->addCamposImagemSatelite($form),
            Solicitacao::TIPO_GERENTE_AREA        => $this->addCamposGerenteArea($form),
            Solicitacao::TIPO_GERENTE_ESTADO_PAIS => $this->addCamposGerenteEstadoPais($form, $isChamp, $acaoGerenteEstadoPais, $souChampEp, $cargoEfetivo),
            Solicitacao::TIPO_NIVEL               => $this->addCamposNivel($form, $tipoNivel, $isChamp, $souChamp),
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
                'help'        => 'Abra o WME, navegue até a área com zoom ≥15 e copie a URL completa do navegador.',
                'constraints' => $this->permalinkConstraints(required: true),
            ]);
    }

    // -------------------------------------------------------------------------
    // GERENTE DE ÁREA — campos diretos, sem etapa incluir/excluir
    // -------------------------------------------------------------------------
    private function addCamposGerenteArea(\Symfony\Component\Form\FormInterface $form): void
    {
        $form
            ->add('dados_mentor', TextType::class, [
                'label'       => 'Quem foi seu mentor?',
                'mapped'      => false,
                'help'        => 'Digite o nome do usuário do seu mentor.',
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('dados_recomendadores', TextType::class, [
                'label'    => 'Quais editores que podem recomendar sua aptidão à função?',
                'mapped'   => false,
                'help'     => 'Escolha os editores que podem recomendar você.',
                'required' => false,
            ])
            ->add('dados_comentarios', TextareaType::class, [
                'label'    => 'Comentários:',
                'mapped'   => false,
                'required' => false,
                'attr'     => ['rows' => 3],
                'help'     => 'Caso necessário, adicione informações relevantes para a sua solicitação.',
            ])
            ->add('dados_poligono', TextareaType::class, [
                'label'     => 'Insira o polígono:',
                'mapped'    => false,
                'attr'      => ['rows' => 4],
                'help'      => 'Cole aqui o <strong>polígono da área</strong>. Utilize uma das páginas abaixo para gerar o polígono:<br>'
                             . '• <a href="https://arthur-e.github.io/Wicket/sandbox-gmaps3.html" target="_blank" rel="noopener">https://arthur-e.github.io/Wicket/sandbox-gmaps3.html</a><br>'
                             . '• <a href="http://map.wazedev.com/" target="_blank" rel="noopener">http://map.wazedev.com/</a><br>'
                             . '<br>O valor informado deve iniciar obrigatoriamente com <strong>POLYGON((</strong> ou <strong>LINESTRING(</strong>.',
                'help_html' => true,
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Regex([
                        'pattern' => '/^(POLYGON|LINESTRING)\s*\(/i',
                        'message' => 'O polígono deve iniciar com POLYGON(( ou LINESTRING(',
                    ]),
                ],
            ])
            ->add('dados_comunicouIntencao', CheckboxType::class, [
                'label'    => 'Comuniquei minha intenção.',
                'mapped'   => false,
                'required' => true,
                'attr'     => [
                    'data-discuss-gate' => '1',
                    'disabled'          => 'disabled',
                ],
                'help'      => 'Atenção! Lembre-se de comunicar sua intenção no Discuss do Estado escolhido.<br>'
                             . '<a href="https://www.waze.com/discuss/c/editors/brasil-estados/4114" target="_blank" rel="noopener" id="link-discuss-intencao">Clique aqui</a> '
                             . 'e busque o tópico <em>"Gerente de área ou candidato, se apresente aqui"</em> do seu Estado e publique sua intenção.',
                'help_html' => true,
                'constraints' => [new Assert\IsTrue(message: 'Você deve comunicar sua intenção no Discuss antes de enviar.')],
            ]);
    }

    // -------------------------------------------------------------------------
    // GERENTE DE ESTADO OU PAÍS — mesma lógica de Nível
    // incluir → campos direto | excluir → só Champ, confirmação antes
    // -------------------------------------------------------------------------
    private function addCamposGerenteEstadoPais(
        \Symfony\Component\Form\FormInterface $form,
        bool    $isChamp               = false,
        ?string $acaoGerenteEstadoPais = null,
        bool    $souChampEp            = false,
        ?string $cargo                 = null
    ): void {
        $form->add('dados_acaoGerenteEstadoPais', ChoiceType::class, [
            'label'    => 'Você deseja…',
            'mapped'   => false,
            'choices'  => $isChamp
                ? ['Incluir' => 'incluir', 'Excluir' => 'excluir']
                : ['Incluir' => 'incluir'],
            'expanded' => true,
            'attr'     => ['data-acao-gerente-ep-ajax' => '1'],
            'constraints' => [new Assert\NotBlank()],
        ]);

        if ($acaoGerenteEstadoPais === 'excluir' && $isChamp) {
            if ($souChampEp) {
                $this->addCamposGerenteEstadoPaisExcluir($form, $cargo);
            } else {
                $form->add('dados_souChampEp', CheckboxType::class, [
                    'label'    => 'Confirmo que sou Champ e estou autorizado a solicitar a exclusão deste Gerente',
                    'mapped'   => false,
                    'required' => true,
                    'attr'     => ['data-sou-champ-ep' => '1'],
                    'constraints' => [
                        new Assert\IsTrue(message: 'Você precisa confirmar que é Champ para solicitar a exclusão.'),
                    ],
                ]);
            }
        } elseif ($acaoGerenteEstadoPais === 'incluir') {
            $this->addCamposGerenteEstadoPaisIncluir($form, $cargo);
        }
    }

    private function addCamposGerenteEstadoPaisIncluir(
        \Symfony\Component\Form\FormInterface $form,
        ?string $cargo = null
    ): void {
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
            ->add('dados_cargo', ChoiceType::class, [
                'label'    => 'Cargo desejado',
                'mapped'   => false,
                'choices'  => ['Gerente de Estado' => 'gerente_estado', 'Gerente de País' => 'gerente_pais'],
                'expanded' => true,
                'constraints' => [new Assert\NotBlank()],
                'attr'     => ['data-cargo-select' => '1'],
            ]);

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
            'attr'     => ['rows' => 3],
        ]);
    }

    private function addCamposGerenteEstadoPaisExcluir(
        \Symfony\Component\Form\FormInterface $form,
        ?string $cargo = null
    ): void {
        $form
            ->add('dados_editorNome', TextType::class, [
                'label'       => 'Nome de usuário do Gerente (a ser removido)',
                'mapped'      => false,
                'attr'        => ['placeholder' => 'Nick do editor no WME/Discuss'],
                'constraints' => [
                    new Assert\NotBlank(message: 'Informe o nome de usuário do Gerente a ser removido.'),
                    new Assert\Length(['max' => 255]),
                ],
            ])
            ->add('dados_cargo', ChoiceType::class, [
                'label'    => 'Cargo a ser removido',
                'mapped'   => false,
                'choices'  => ['Gerente de Estado' => 'gerente_estado', 'Gerente de País' => 'gerente_pais'],
                'expanded' => true,
                'constraints' => [new Assert\NotBlank()],
                'attr'     => ['data-cargo-select' => '1'],
            ]);

        if ($cargo === 'gerente_estado') {
            $form->add('dados_uf', ChoiceType::class, [
                'label'       => 'Estado do gerente',
                'mapped'      => false,
                'choices'     => self::UFS,
                'placeholder' => 'Escolher',
                'required'    => true,
                'constraints' => [new Assert\NotBlank(message: 'Selecione o estado.')],
                'attr'        => ['data-uf-gerente' => '1'],
            ]);
        }

        $form
            ->add('dados_justificativa', TextareaType::class, [
                'label'  => 'Justificativa',
                'mapped' => false,
                'attr'   => [
                    'rows'              => 4,
                    'minlength'         => 20,
                    'maxlength'         => 2000,
                    'placeholder'       => 'Descreva o motivo da remoção (mínimo 20 caracteres)',
                    'data-char-counter' => 'true',
                    'data-char-min'     => '20',
                ],
                'help' => 'Informe o motivo pelo qual o Gerente deve ser removido do cargo.',
                'constraints' => [
                    new Assert\NotBlank(message: 'Preencha a justificativa.'),
                    new Assert\Length([
                        'min'        => 20,
                        'minMessage' => 'A justificativa deve ter pelo menos {{ limit }} caracteres.',
                        'max'        => 2000,
                        'maxMessage' => 'A justificativa não pode ultrapassar {{ limit }} caracteres.',
                    ]),
                ],
            ])
            ->add('dados_comentarios', TextareaType::class, [
                'label'    => 'Comentários adicionais',
                'mapped'   => false,
                'required' => false,
                'attr'     => ['rows' => 3],
            ]);
    }

    // -------------------------------------------------------------------------
    // NÍVEL (UPGRADE / DOWNGRADE)
    // -------------------------------------------------------------------------
    private function addCamposNivel(
        \Symfony\Component\Form\FormInterface $form,
        ?string $tipoNivel  = null,
        bool    $isChamp    = false,
        bool    $souChamp   = false
    ): void {
        $form->add('dados_tipoNivel', ChoiceType::class, [
            'label'    => 'É um pedido de…',
            'mapped'   => false,
            'choices'  => $isChamp
                ? ['Upgrade' => 'upgrade', 'Downgrade' => 'downgrade']
                : ['Upgrade' => 'upgrade'],
            'expanded' => true,
            'attr'     => ['data-tipo-nivel' => '1'],
            'constraints' => [new Assert\NotBlank()],
        ]);

        if ($tipoNivel === 'downgrade' && $isChamp) {
            if ($souChamp) {
                $this->addCamposDowngrade($form);
            } else {
                $form->add('dados_souChamp', CheckboxType::class, [
                    'label'    => 'Confirmo que sou Champ e estou autorizado a solicitar downgrade',
                    'mapped'   => false,
                    'required' => true,
                    'attr'     => ['data-sou-champ' => '1'],
                    'constraints' => [
                        new Assert\IsTrue(message: 'Você precisa confirmar que é Champ para solicitar downgrade.'),
                    ],
                ]);
            }
        } elseif ($tipoNivel === 'upgrade' || (!$isChamp && $tipoNivel === null)) {
            $this->addCamposUpgrade($form);
        }
    }

    // -------------------------------------------------------------------------
    // UPGRADE
    // -------------------------------------------------------------------------
    private function addCamposUpgrade(\Symfony\Component\Form\FormInterface $form): void
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
                'help'      => 'Você pode achar essa resposta aqui: '
                             . '<a href="https://www.waze.com/discuss/u/SEU_NICK/summary" '
                             . 'target="_blank" rel="noopener" data-discuss-link>'
                             . 'https://www.waze.com/discuss/u/SEU_NICK/summary</a>',
                'help_html'  => true,
                'attr'       => ['data-discuss-help' => '1'],
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
                'help'      => 'Recomenda-se a leitura deste '
                             . '<a href="https://www.waze.com/discuss/t/new-regras-e-normas-para-niveis-cargos-e-areas/282152" '
                             . 'target="_blank" rel="noopener">tópico</a> '
                             . 'para assegurar o atendimento aos requisitos.',
                'help_html' => true,
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\EqualTo(['value' => 'sim', 'message' => 'Você deve cumprir os requisitos antes de solicitar.']),
                ],
            ]);
    }

    // -------------------------------------------------------------------------
    // DOWNGRADE
    // -------------------------------------------------------------------------
    private function addCamposDowngrade(\Symfony\Component\Form\FormInterface $form): void
    {
        $form
            ->add('dados_editorNome', TextType::class, [
                'label'       => 'Nome de usuário do editor (alvo do downgrade)',
                'mapped'      => false,
                'attr'        => ['placeholder' => 'Nick do editor no WME/Discuss'],
                'constraints' => [
                    new Assert\NotBlank(message: 'Informe o nome de usuário do editor a ser rebaixado.'),
                    new Assert\Length(['max' => 255]),
                ],
            ])
            ->add('dados_nivelAtual', TextType::class, [
                'label'  => 'Nível atual do editor',
                'mapped' => false,
                'attr'   => ['placeholder' => 'Nível atual do editor (2 a 6)'],
                'help'   => 'Nível atual do editor que receberá o downgrade.',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Range([
                        'min'               => 2,
                        'max'               => 6,
                        'notInRangeMessage' => 'O nível atual deve estar entre 2 e 6.',
                    ]),
                ],
            ])
            ->add('dados_nivelDesejado', TextType::class, [
                'label'  => 'Nível para o qual será rebaixado',
                'mapped' => false,
                'attr'   => ['placeholder' => 'Nível alvo (1 a 5)'],
                'help'   => 'O nível resultante após o downgrade.',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Range([
                        'min'               => 1,
                        'max'               => 5,
                        'notInRangeMessage' => 'O nível alvo deve estar entre 1 e 5.',
                    ]),
                ],
            ])
            ->add('dados_motivoDowngrade', ChoiceType::class, [
                'label'  => 'Motivo do downgrade',
                'mapped' => false,
                'choices' => [
                    'Inatividade prolongada'              => 'inatividade',
                    'Edições destrutivas recorrentes'     => 'edicoes_destrutivas',
                    'Desrespeito às normas da comunidade' => 'normas',
                    'Solicitação do próprio editor'       => 'pedido_proprio',
                    'Outro'                               => 'outro',
                ],
                'placeholder' => 'Escolher',
                'constraints' => [new Assert\NotBlank(message: 'Selecione o motivo do downgrade.')],
            ])
            ->add('dados_justificativa', TextareaType::class, [
                'label'  => 'Justificativa detalhada',
                'mapped' => false,
                'attr'   => [
                    'rows'              => 4,
                    'minlength'         => 30,
                    'maxlength'         => 2000,
                    'placeholder'       => 'Descreva detalhadamente o motivo do downgrade (mínimo 30 caracteres)',
                    'data-char-counter' => 'true',
                    'data-char-min'     => '30',
                ],
                'help' => 'Apresente evidências ou descrição clara da situação que justifica o rebaixamento.',
                'constraints' => [
                    new Assert\NotBlank(message: 'Preencha a justificativa.'),
                    new Assert\Length([
                        'min'        => 30,
                        'minMessage' => 'A justificativa deve ter pelo menos {{ limit }} caracteres.',
                        'max'        => 2000,
                        'maxMessage' => 'A justificativa não pode ultrapassar {{ limit }} caracteres.',
                    ]),
                ],
            ])
            ->add('dados_recomendadores', TextType::class, [
                'label'    => 'Champs ou editores que corroboram',
                'mapped'   => false,
                'required' => false,
                'attr'     => ['placeholder' => 'Nicks separados por vírgula (opcional)'],
                'help'     => 'Outros Champs ou editores de referência que concordam com a solicitação.',
            ])
            ->add('dados_comentarios', TextareaType::class, [
                'label'    => 'Comentários adicionais',
                'mapped'   => false,
                'required' => false,
                'attr'     => ['rows' => 3],
            ]);
    }

    // -------------------------------------------------------------------------
    // OOPS DE EDITOR
    // -------------------------------------------------------------------------
    private function addCamposOops(\Symfony\Component\Form\FormInterface $form): void
    {
        $form
            ->add('dados_editorNome', TextType::class, [
                'label'       => 'Nome de usuário do editor',
                'mapped'      => false,
                'constraints' => [new Assert\NotBlank(), new Assert\Length(['max' => 255])],
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
                'help'        => 'Abra o WME, navegue até a área afetada com zoom ≥15 e copie a URL.',
                'constraints' => $this->permalinkConstraints(required: true),
            ])
            ->add('dados_descricao', TextareaType::class, [
                'label'  => 'Descrição do problema',
                'mapped' => false,
                'attr'   => [
                    'rows'              => 4,
                    'minlength'         => 20,
                    'maxlength'         => 2000,
                    'placeholder'       => 'Descreva o problema detectado (mínimo 20 caracteres)',
                    'data-char-counter' => 'true',
                    'data-char-min'     => '20',
                ],
                'constraints' => [
                    new Assert\NotBlank(message: 'Descreva o problema.'),
                    new Assert\Length([
                        'min'        => 20,
                        'minMessage' => 'A descrição deve ter pelo menos {{ limit }} caracteres.',
                        'max'        => 2000,
                        'maxMessage' => 'A descrição não pode ultrapassar {{ limit }} caracteres.',
                    ]),
                ],
            ]);
    }

    // -------------------------------------------------------------------------
    // BANDEIRA DE POSTO
    // -------------------------------------------------------------------------
    private function addCamposBandeiraPosto(\Symfony\Component\Form\FormInterface $form): void
    {
        $form
            ->add('dados_nomePosto', TextType::class, [
                'label'       => 'Nome do posto',
                'mapped'      => false,
                'constraints' => [new Assert\NotBlank(), new Assert\Length(['max' => 255])],
            ])
            ->add('dados_bandeira', TextType::class, [
                'label'    => 'Bandeira',
                'mapped'   => false,
                'required' => false,
                'attr'     => ['placeholder' => 'Ex: Petrobras, Shell, Ipiranga…'],
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
                'constraints' => $this->permalinkConstraints(required: true),
            ])
            ->add('dados_descricaoBandeira', TextareaType::class, [
                'label'  => 'Descrição / Justificativa',
                'mapped' => false,
                'attr'   => [
                    'rows'              => 4,
                    'minlength'         => 10,
                    'maxlength'         => 2000,
                    'placeholder'       => 'Descreva a solicitação (mínimo 10 caracteres)',
                    'data-char-counter' => 'true',
                    'data-char-min'     => '10',
                ],
                'constraints' => [
                    new Assert\NotBlank(message: 'Preencha a descrição.'),
                    new Assert\Length([
                        'min'        => 10,
                        'minMessage' => 'A descrição deve ter pelo menos {{ limit }} caracteres.',
                        'max'        => 2000,
                        'maxMessage' => 'A descrição não pode ultrapassar {{ limit }} caracteres.',
                    ]),
                ],
            ]);
    }

    // -------------------------------------------------------------------------
    // ID DE SEGMENTO
    // -------------------------------------------------------------------------
    private function addCamposIdSegmento(\Symfony\Component\Form\FormInterface $form): void
    {
        $form
            ->add('dados_permalink', TextType::class, [
                'label'  => 'Permalink (zoom ≥15)',
                'mapped' => false,
                'attr'   => [
                    'placeholder'    => 'https://waze.com/pt-BR/editor?env=row&lat=-20.255&lon=-43.224&zoomLevel=15',
                    'data-permalink' => '1',
                    'autocomplete'   => 'off',
                    'spellcheck'     => 'false',
                ],
                'help'        => 'Abra o WME, navegue até o segmento com zoom ≥15 e copie a URL completa do navegador.',
                'constraints' => $this->permalinkConstraints(required: true),
            ])
            ->add('dados_descricao', TextareaType::class, [
                'label'  => 'Descrição do segmento',
                'mapped' => false,
                'attr'   => [
                    'rows'              => 3,
                    'minlength'         => 10,
                    'maxlength'         => 1000,
                    'placeholder'       => 'Descreva brevemente o segmento (mínimo 10 caracteres)',
                    'data-char-counter' => 'true',
                    'data-char-min'     => '10',
                ],
                'constraints' => [
                    new Assert\NotBlank(message: 'Descreva o segmento.'),
                    new Assert\Length([
                        'min'        => 10,
                        'minMessage' => 'A descrição deve ter pelo menos {{ limit }} caracteres.',
                        'max'        => 1000,
                        'maxMessage' => 'A descrição não pode ultrapassar {{ limit }} caracteres.',
                    ]),
                ],
            ]);
    }
}
