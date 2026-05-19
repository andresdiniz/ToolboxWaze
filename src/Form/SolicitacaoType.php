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
        [^"\s]*
        [?&]zoom(?:Level)?=
        (1[5-9]|[2-9]\d|\d{3,})
        [^"\s]*
    /xi';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('tipo', ChoiceType::class, [
                'label'       => 'Tipo de solicita\u00e7\u00e3o',
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
                'label'       => 'Nome de usu\u00e1rio (WME)',
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
            $tipo = $event->getData()['tipo'] ?? null;
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
                'message' => 'O permalink deve ser do editor Waze com zoom \u226515. '
                           . 'Ex: https://waze.com/pt-BR/editor?env=row&lat=-20&lon=-43&zoomLevel=15',
            ]),
        ];
        if ($required) {
            array_unshift($constraints, new Assert\NotBlank(message: 'O permalink \u00e9 obrigat\u00f3rio.'));
        }
        return $constraints;
    }

    private function addCamposImagemSatelite(\Symfony\Component\Form\FormInterface $form): void
    {
        $form
            ->add('dados_motivo', ChoiceType::class, [
                'label' => 'Motivo da solicita\u00e7\u00e3o', 'mapped' => false,
                'choices' => [
                    'A imagem com maior resolu\u00e7\u00e3o \u00e9 muito antiga' => 'muito_antiga',
                    'A imagem est\u00e1 com qualidade/resolu\u00e7\u00e3o baixa'  => 'baixa_qualidade',
                    'Altera\u00e7\u00f5es significativas na \u00e1rea'           => 'alteracoes',
                    'As imagens est\u00e3o ruins ou nubladas'                   => 'ruins_nubladas',
                ],
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('dados_idadeImagem', ChoiceType::class, [
                'label' => 'Qu\u00e3o antiga pode ser a imagem atualizada?', 'mapped' => false,
                'choices' => ['1 ano' => '1_ano', '6 meses' => '6_meses', '1 m\u00eas' => '1_mes', '1 semana' => '1_semana'],
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('dados_urgente', ChoiceType::class, [
                'label' => '\u00c9 urgente?', 'mapped' => false,
                'choices' => ['Sim' => 'sim', 'N\u00e3o' => 'nao'],
                'expanded' => true, 'constraints' => [new Assert\NotBlank()],
            ])
            ->add('dados_permalink', TextType::class, [
                'label'  => 'Permalink (zoom \u226515)',
                'mapped' => false,
                'attr'   => [
                    'placeholder'    => 'https://waze.com/pt-BR/editor?env=row&lat=-20.255&lon=-43.224&zoomLevel=15',
                    'data-permalink' => '1',
                    'autocomplete'   => 'off',
                    'spellcheck'     => 'false',
                ],
                'help'        => 'Abra o WME, navegue at\u00e9 a \u00e1rea com zoom \u226515 e copie a URL completa do navegador. '
                               . 'A URL deve ser do editor Waze (waze.com/editor).',
                'constraints' => $this->permalinkConstraints(required: true),
            ]);
    }

    private function addCamposGerenteArea(\Symfony\Component\Form\FormInterface $form): void
    {
        $form
            ->add('dados_mentor', TextType::class, ['label' => 'Mentor', 'mapped' => false, 'constraints' => [new Assert\NotBlank()]])
            ->add('dados_recomendadores', TextType::class, ['label' => 'Editores recomendadores', 'mapped' => false, 'required' => false])
            ->add('dados_poligono', TextareaType::class, [
                'label'  => 'Pol\u00edgono da \u00e1rea',
                'mapped' => false,
                'attr'   => ['rows' => 4],
                'help'   => 'Cole o pol\u00edgono gerado por uma dessas ferramentas:<br>'
                          . '\u2022 <a href="https://arthur-e.github.io/Wicket/sandbox-gmaps3.html" target="_blank" rel="noopener">arthur-e.github.io/Wicket</a><br>'
                          . '\u2022 <a href="http://map.wazedev.com/" target="_blank" rel="noopener">map.wazedev.com</a><br>'
                          . 'O valor deve iniciar com <strong>POLYGON((</strong> ou <strong>LINESTRING(</strong>.',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Regex([
                        'pattern' => '/^(POLYGON|LINESTRING)\s*\(/i',
                        'message' => 'O pol\u00edgono deve iniciar com POLYGON(( ou LINESTRING(',
                    ]),
                ],
            ])
            ->add('dados_comunicouIntencao', CheckboxType::class, [
                'label'    => 'Comuniquei minha inten\u00e7\u00e3o no Discuss do Estado',
                'mapped'   => false,
                'required' => true,
                'constraints' => [new Assert\IsTrue(message: 'Voc\u00ea deve comunicar sua inten\u00e7\u00e3o no Discuss antes de enviar.')],
            ])
            ->add('dados_comentarios', TextareaType::class, ['label' => 'Coment\u00e1rios', 'mapped' => false, 'required' => false]);
    }

    private function addCamposGerenteEstadoPais(\Symfony\Component\Form\FormInterface $form, ?string $cargo = null): void
    {
        $form
            ->add('dados_acao', ChoiceType::class, [
                'label'    => 'Voc\u00ea deseja\u2026', 'mapped' => false,
                'choices'  => ['Incluir' => 'incluir', 'Excluir' => 'excluir'],
                'expanded' => true, 'constraints' => [new Assert\NotBlank()],
            ])
            ->add('dados_mentor', TextType::class, [
                'label' => 'Mentor', 'mapped' => false, 'constraints' => [new Assert\NotBlank()],
            ])
            ->add('dados_recomendadores', TextType::class, [
                'label' => 'Editores recomendadores', 'mapped' => false, 'required' => false,
            ])
            ->add('dados_cargo', ChoiceType::class, [
                'label'    => 'Cargo desejado', 'mapped' => false,
                'choices'  => ['Gerente de Estado' => 'gerente_estado', 'Gerente de Pa\u00eds' => 'gerente_pais'],
                'expanded' => true,
                'constraints' => [new Assert\NotBlank()],
                'attr'     => ['data-cargo-select' => '1'],
            ]);

        // Exibe sele\u00e7\u00e3o de UF apenas para Gerente de Estado
        if ($cargo === 'gerente_estado' || $cargo === null) {
            $form->add('dados_uf', ChoiceType::class, [
                'label'       => 'Estado',
                'mapped'      => false,
                'choices'     => self::UFS,
                'placeholder' => 'Escolher',
                'required'    => ($cargo === 'gerente_estado'),
                'constraints' => $cargo === 'gerente_estado'
                    ? [new Assert\NotBlank(message: 'Selecione o estado.')]
                    : [],
                'attr'        => ['data-uf-gerente' => '1'],
            ]);
        }

        $form->add('dados_comentarios', TextareaType::class, [
            'label' => 'Coment\u00e1rios', 'mapped' => false, 'required' => false,
        ]);
    }

    private function addCamposNivel(\Symfony\Component\Form\FormInterface $form): void
    {
        $form
            // 1. Tipo do pedido (Upgrade/Downgrade) — primeiro campo, conforme layout
            ->add('dados_tipoNivel', ChoiceType::class, [
                'label'    => '\u00c9 um pedido de\u2026', 'mapped' => false,
                'choices'  => ['Upgrade' => 'upgrade', 'Downgrade' => 'downgrade'],
                'expanded' => true, 'constraints' => [new Assert\NotBlank()],
            ])
            // 2. Mentor
            ->add('dados_mentor', TextType::class, [
                'label' => 'Mentor', 'mapped' => false, 'constraints' => [new Assert\NotBlank()],
            ])
            // 3. Recomendadores
            ->add('dados_recomendadores', TextType::class, [
                'label' => 'Editores recomendadores', 'mapped' => false, 'required' => false,
            ])
            // 4. N\u00edvel atual
            ->add('dados_nivelAtual', TextType::class, [
                'label' => 'N\u00edvel atual',
                'mapped' => false,
                'attr'  => ['placeholder' => 'Digite o seu n\u00edvel de um a cinco'],
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Range(['min' => 1, 'max' => 5]),
                ],
            ])
            // 5. N\u00edvel desejado
            ->add('dados_nivelDesejado', TextType::class, [
                'label' => 'N\u00edvel desejado',
                'mapped' => false,
                'attr'  => ['placeholder' => 'Digite o n\u00edvel desejado de dois a seis'],
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Range(['min' => 2, 'max' => 6]),
                ],
            ])
            // 6. Ativo na comunidade
            ->add('dados_ativo', ChoiceType::class, [
                'label'    => 'Voc\u00ea se considera ativo na comunidade?',
                'mapped'   => false,
                'choices'  => ['Sim' => 'sim', 'N\u00e3o' => 'nao'],
                'expanded' => true,
                'help'     => 'Indique se voc\u00ea se considera ativo nos canais oficiais do Waze.',
                'constraints' => [new Assert\NotBlank()],
            ])
            // 7. Postagens no Discuss
            ->add('dados_postagens', ChoiceType::class, [
                'label'  => 'Quantas postagens voc\u00ea fez no \u00faltimo ano no Discuss?',
                'mapped' => false,
                'choices' => [
                    'Entre 1 e 5 postagens'   => '1_5',
                    'Entre 6 e 10 postagens'  => '6_10',
                    'Entre 11 e 20 postagens' => '11_20',
                    'Entre 21 e 50 postagens' => '21_50',
                    'Mais de 50 postagens'    => '50_mais',
                    'Nenhuma.'                => 'nenhuma',
                ],
                'help'        => 'Voc\u00ea pode achar essa resposta aqui: '
                               . '<a href="https://www.waze.com/discuss/u/SEU_NICK/activity" target="_blank" rel="noopener">'
                               . 'https://www.waze.com/discuss/u/SEU_NICK/activity</a>',
                'constraints' => [new Assert\NotBlank()],
            ])
            // 8. Motiva\u00e7\u00e3o
            ->add('dados_motivacao', TextareaType::class, [
                'label'  => 'O que motiva voc\u00ea a subir de n\u00edvel?',
                'mapped' => false,
                'attr'   => [
                    'rows'              => 4,
                    'minlength'         => 20,
                    'maxlength'         => 2000,
                    'placeholder'       => 'Descreva sua motiva\u00e7\u00e3o (m\u00ednimo 20 caracteres)',
                    'data-char-counter' => 'true',
                    'data-char-min'     => '20',
                ],
                'help' => 'Explique o que o motiva a buscar um n\u00edvel mais alto.',
                'constraints' => [
                    new Assert\NotBlank(message: 'Preencha sua motiva\u00e7\u00e3o.'),
                    new Assert\Length([
                        'min'        => 20,
                        'minMessage' => 'Sua motiva\u00e7\u00e3o deve ter pelo menos {{ limit }} caracteres.',
                        'max'        => 2000,
                        'maxMessage' => 'Sua motiva\u00e7\u00e3o n\u00e3o pode ultrapassar {{ limit }} caracteres.',
                    ]),
                ],
            ])
            // 9. Cumpriu requisitos
            ->add('dados_cumpriuRequisitos', ChoiceType::class, [
                'label'    => 'Voc\u00ea cumpre os requisitos para essa promo\u00e7\u00e3o?',
                'mapped'   => false,
                'choices'  => ['Sim' => 'sim', 'N\u00e3o' => 'nao'],
                'expanded' => true,
                'help'     => 'Recomenda-se a leitura deste '
                            . '<a href="https://www.waze.com/discuss/t/new-regras-e-normas-para-niveis-cargos-e-areas/282152" '
                            . 'target="_blank" rel="noopener">t\u00f3pico</a> '
                            . 'para assegurar o atendimento aos requisitos.',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\EqualTo(['value' => 'sim', 'message' => 'Voc\u00ea deve cumprir os requisitos antes de solicitar.']),
                ],
            ]);
    }

    private function addCamposOops(\Symfony\Component\Form\FormInterface $form): void
    {
        $form
            ->add('dados_editorNome', TextType::class, [
                'label' => 'Nome do editor que cometeu o Oops', 'mapped' => false,
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('dados_permalink', TextType::class, [
                'label'    => 'Permalink (zoom \u226515)',
                'mapped'   => false,
                'required' => false,
                'attr'     => [
                    'placeholder'    => 'https://waze.com/pt-BR/editor?env=row&lat=-20.255&lon=-43.224&zoomLevel=15',
                    'data-permalink' => '1',
                    'autocomplete'   => 'off',
                    'spellcheck'     => 'false',
                ],
                'help'        => 'Opcional, mas recomendado. Se informar, deve ter zoom \u226515. '
                               . 'A URL deve ser do editor Waze (waze.com/editor).',
                'constraints' => $this->permalinkConstraints(required: false),
            ])
            ->add('dados_descricao', TextareaType::class, [
                'label'  => 'Fundamente as regras infringidas e descreva os fatos',
                'mapped' => false,
                'attr'   => [
                    'rows'              => 5,
                    'minlength'         => 30,
                    'maxlength'         => 5000,
                    'placeholder'       => 'Descreva detalhadamente os fatos e as regras infringidas (m\u00ednimo 30 caracteres)',
                    'data-char-counter' => 'true',
                    'data-char-min'     => '30',
                ],
                'help' => 'M\u00ednimo de 30 caracteres.',
                'constraints' => [
                    new Assert\NotBlank(message: 'Preencha a descri\u00e7\u00e3o.'),
                    new Assert\Length([
                        'min'        => 30,
                        'minMessage' => 'A descri\u00e7\u00e3o deve ter pelo menos {{ limit }} caracteres.',
                        'max'        => 5000,
                        'maxMessage' => 'A descri\u00e7\u00e3o n\u00e3o pode ultrapassar {{ limit }} caracteres.',
                    ]),
                ],
            ]);
    }

    private function addCamposBandeiraPosto(\Symfony\Component\Form\FormInterface $form): void
    {
        $form
            ->add('dados_acao', ChoiceType::class, [
                'label' => 'Voc\u00ea deseja\u2026', 'mapped' => false,
                'choices' => ['Adicionar' => 'adicionar', 'Remover' => 'remover'],
                'expanded' => true, 'constraints' => [new Assert\NotBlank()],
            ])
            ->add('dados_nomeBandeira', TextType::class, [
                'label' => 'Nome da bandeira', 'mapped' => false,
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('dados_cnpj', TextType::class, [
                'label' => 'CNPJ de um posto ativo com cadastro atualizado', 'mapped' => false, 'required' => false,
                'constraints' => [
                    new Assert\Regex(['pattern' => '/^\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2}$/', 'message' => 'CNPJ inv\u00e1lido']),
                ],
            ]);
    }

    private function addCamposIdSegmento(\Symfony\Component\Form\FormInterface $form): void
    {
        $form
            ->add('dados_nomeSegmento', TextType::class, [
                'label' => 'Nome do segmento (conforme WME)', 'mapped' => false,
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('dados_idSegmento', TextType::class, [
                'label' => 'ID do segmento (sem permalink)', 'mapped' => false,
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Regex(['pattern' => '/^\d+$/', 'message' => 'Informe apenas o ID num\u00e9rico, sem permalink']),
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
