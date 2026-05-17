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
            $tipo = $event->getData()['tipo'] ?? null;
            $this->addDadosDinamicos($event->getForm(), $tipo);
        });
    }

    private function addDadosDinamicos(\Symfony\Component\Form\FormInterface $form, ?string $tipo): void
    {
        match ($tipo) {
            Solicitacao::TIPO_IMAGEM_SATELITE     => $this->addCamposImagemSatelite($form),
            Solicitacao::TIPO_GERENTE_AREA        => $this->addCamposGerenteArea($form),
            Solicitacao::TIPO_GERENTE_ESTADO_PAIS => $this->addCamposGerenteEstadoPais($form),
            Solicitacao::TIPO_NIVEL               => $this->addCamposNivel($form),
            Solicitacao::TIPO_OOPS                => $this->addCamposOops($form),
            Solicitacao::TIPO_BANDEIRA_POSTO      => $this->addCamposBandeiraPosto($form),
            Solicitacao::TIPO_ID_SEGMENTO         => $this->addCamposIdSegmento($form),
            default                               => null,
        };
    }

    private function addCamposImagemSatelite(\Symfony\Component\Form\FormInterface $form): void
    {
        $form
            ->add('dados_motivo', ChoiceType::class, [
                'label' => 'Motivo da solicitação', 'mapped' => false,
                'choices' => [
                    'A imagem com maior resolução é muito antiga' => 'muito_antiga',
                    'A imagem está com qualidade/resolução baixa' => 'baixa_qualidade',
                    'Alterações significativas na área'           => 'alteracoes',
                    'As imagens estão ruins ou nubladas'           => 'ruins_nubladas',
                ],
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('dados_idadeImagem', ChoiceType::class, [
                'label' => 'Quão antiga pode ser a imagem atualizada?', 'mapped' => false,
                'choices' => ['1 ano' => '1_ano', '6 meses' => '6_meses', '1 mês' => '1_mes', '1 semana' => '1_semana'],
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('dados_urgente', ChoiceType::class, [
                'label' => 'É urgente?', 'mapped' => false,
                'choices' => ['Sim' => 'sim', 'Não' => 'nao'],
                'expanded' => true, 'constraints' => [new Assert\NotBlank()],
            ])
            ->add('dados_permalink', TextType::class, [
                'label' => 'Permalink (zoom ≥ 15)', 'mapped' => false,
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Regex(['pattern' => '/waze\.com|wazle\.com/i', 'message' => 'Insira um permalink válido do WME']),
                ],
            ]);
    }

    private function addCamposGerenteArea(\Symfony\Component\Form\FormInterface $form): void
    {
        $form
            ->add('dados_mentor', TextType::class, ['label' => 'Mentor', 'mapped' => false, 'constraints' => [new Assert\NotBlank()]])
            ->add('dados_recomendadores', TextType::class, ['label' => 'Editores recomendadores', 'mapped' => false, 'required' => false])
            ->add('dados_poligono', TextareaType::class, [
                'label' => 'Polígono da área', 'mapped' => false, 'attr' => ['rows' => 4],
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Regex([
                        'pattern' => '/^(POLYGON|LINESTRING)\s*\(/i',
                        'message' => 'O polígono deve iniciar com POLYGON(( ou LINESTRING(',
                    ]),
                ],
            ])
            ->add('dados_comunicouIntencao', CheckboxType::class, [
                'label' => 'Comuniquei minha intenção no Discuss do Estado', 'mapped' => false, 'required' => true,
                'constraints' => [new Assert\IsTrue(message: 'Você deve comunicar sua intenção no Discuss antes de enviar.')],
            ])
            ->add('dados_comentarios', TextareaType::class, ['label' => 'Comentários', 'mapped' => false, 'required' => false]);
    }

    private function addCamposGerenteEstadoPais(\Symfony\Component\Form\FormInterface $form): void
    {
        $form
            ->add('dados_acao', ChoiceType::class, [
                'label' => 'Você deseja…', 'mapped' => false,
                'choices' => ['Incluir' => 'incluir', 'Excluir' => 'excluir'],
                'expanded' => true, 'constraints' => [new Assert\NotBlank()],
            ])
            ->add('dados_mentor', TextType::class, ['label' => 'Mentor', 'mapped' => false, 'constraints' => [new Assert\NotBlank()]])
            ->add('dados_recomendadores', TextType::class, ['label' => 'Editores recomendadores', 'mapped' => false, 'required' => false])
            ->add('dados_cargo', ChoiceType::class, [
                'label' => 'Cargo desejado', 'mapped' => false,
                'choices' => ['Gerente de Estado' => 'gerente_estado', 'Gerente de País' => 'gerente_pais'],
                'expanded' => true, 'constraints' => [new Assert\NotBlank()],
            ])
            ->add('dados_comentarios', TextareaType::class, ['label' => 'Comentários', 'mapped' => false, 'required' => false]);
    }

    private function addCamposNivel(\Symfony\Component\Form\FormInterface $form): void
    {
        $form
            ->add('dados_tipoNivel', ChoiceType::class, [
                'label' => 'É um pedido de…', 'mapped' => false,
                'choices' => ['Upgrade' => 'upgrade', 'Downgrade' => 'downgrade'],
                'expanded' => true, 'constraints' => [new Assert\NotBlank()],
            ])
            ->add('dados_mentor', TextType::class, ['label' => 'Mentor', 'mapped' => false, 'constraints' => [new Assert\NotBlank()]])
            ->add('dados_recomendadores', TextType::class, ['label' => 'Editores recomendadores', 'mapped' => false, 'required' => false])
            ->add('dados_nivelAtual', TextType::class, [
                'label' => 'Nível atual (1–5)', 'mapped' => false,
                'constraints' => [new Assert\NotBlank(), new Assert\Range(['min' => 1, 'max' => 5])],
            ])
            ->add('dados_nivelDesejado', TextType::class, [
                'label' => 'Nível desejado (2–6)', 'mapped' => false,
                'constraints' => [new Assert\NotBlank(), new Assert\Range(['min' => 2, 'max' => 6])],
            ])
            ->add('dados_ativo', ChoiceType::class, [
                'label' => 'Você se considera ativo na comunidade?', 'mapped' => false,
                'choices' => ['Sim' => 'sim', 'Não' => 'nao'],
                'expanded' => true, 'constraints' => [new Assert\NotBlank()],
            ])
            ->add('dados_postagens', ChoiceType::class, [
                'label' => 'Quantas postagens no Discuss (último ano)?', 'mapped' => false,
                'choices' => [
                    'Entre 1 e 5' => '1_5', 'Entre 6 e 10' => '6_10', 'Entre 11 e 20' => '11_20',
                    'Entre 21 e 50' => '21_50', 'Mais de 50' => '50_mais', 'Nenhuma' => 'nenhuma',
                ],
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('dados_motivacao', TextareaType::class, [
                'label' => 'O que te motiva a subir de nível?', 'mapped' => false,
                'constraints' => [new Assert\NotBlank(), new Assert\Length(['min' => 20])],
            ])
            ->add('dados_cumpriuRequisitos', ChoiceType::class, [
                'label' => 'Você cumpre os requisitos para essa promoção?', 'mapped' => false,
                'choices' => ['Sim' => 'sim', 'Não' => 'nao'],
                'expanded' => true,
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\EqualTo(['value' => 'sim', 'message' => 'Você deve cumprir os requisitos antes de solicitar.']),
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
            ->add('dados_permalink', TextType::class, ['label' => 'Permalink do WME', 'mapped' => false, 'required' => false])
            ->add('dados_descricao', TextareaType::class, [
                'label' => 'Fundamente as regras infringidas e descreva os fatos', 'mapped' => false, 'attr' => ['rows' => 5],
                'constraints' => [new Assert\NotBlank(), new Assert\Length(['min' => 30])],
            ]);
    }

    private function addCamposBandeiraPosto(\Symfony\Component\Form\FormInterface $form): void
    {
        $form
            ->add('dados_acao', ChoiceType::class, [
                'label' => 'Você deseja…', 'mapped' => false,
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
                    new Assert\Regex(['pattern' => '/^\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2}$/', 'message' => 'CNPJ inválido']),
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
