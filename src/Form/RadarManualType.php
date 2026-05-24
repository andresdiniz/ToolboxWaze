<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\RadarManual;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class RadarManualType extends AbstractType
{
    private const UFS = [
        'AC','AL','AM','AP','BA','CE','DF','ES','GO','MA','MG','MS','MT',
        'PA','PB','PE','PI','PR','RJ','RN','RO','RR','RS','SC','SE','SP','TO',
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // ── Localização
            ->add('siglaUf', ChoiceType::class, [
                'label'       => 'UF',
                'choices'     => array_combine(self::UFS, self::UFS),
                'attr'        => ['class' => 'form-select'],
                'placeholder' => 'Selecione',
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('municipio', TextType::class, [
                'label'       => 'Município',
                'attr'        => ['class' => 'form-control', 'placeholder' => 'Ex: Conselheiro Lafaiete'],
                'constraints' => [new Assert\NotBlank(), new Assert\Length(['max' => 255])],
            ])
            ->add('localVerificacao', TextType::class, [
                'label' => 'Local de Verificação',
                'attr'  => [
                    'class'       => 'form-control',
                    'placeholder' => 'Ex: BR-040 km 512 - Sentido BH',
                ],
                'constraints' => [new Assert\NotBlank(), new Assert\Length(['max' => 500])],
            ])

            // ── Equipamento
            ->add('tipoMedidor', ChoiceType::class, [
                'label'       => 'Tipo do Medidor',
                'required'    => false,
                'choices'     => [
                    'Fixo'              => 'FIXO',
                    'Portátil'          => 'PORTÁTIL',
                    'Lombada Eletrônica' => 'LOMBADA ELETRÔNICA',
                    'Outros'            => 'OUTROS',
                ],
                'attr'        => ['class' => 'form-select'],
                'placeholder' => 'Não informado',
            ])
            ->add('marca', TextType::class, [
                'label'    => 'Marca / Fabricante',
                'required' => false,
                'attr'     => [
                    'class'       => 'form-control',
                    'placeholder' => 'Ex: Pardini, Cinemometer, Perkons…',
                    'list'        => 'marcas-list',
                ],
            ])
            ->add('numeroSerie', TextType::class, [
                'label'    => 'Nº de Série',
                'required' => false,
                'attr'     => [
                    'class'       => 'form-control font-monospace',
                    'placeholder' => 'Ex: 001234 ou REG-00123',
                    'autocomplete' => 'off',
                ],
                'constraints' => [
                    new Assert\Length(['max' => 100]),
                ],
            ])

            // ── Via
            ->add('velocidade', IntegerType::class, [
                'label'       => 'Velocidade Máxima (km/h)',
                'required'    => false,
                'attr'        => ['class' => 'form-control', 'placeholder' => 'Ex: 80', 'min' => 10, 'max' => 300],
                'constraints' => [
                    new Assert\Range([
                        'min' => 10, 'max' => 300,
                        'notInRangeMessage' => 'Velocidade deve ser entre {{ min }} e {{ max }} km/h.',
                    ]),
                ],
            ])
            ->add('sentido', TextType::class, [
                'label'    => 'Sentido da Via',
                'required' => false,
                'attr'     => ['class' => 'form-control', 'placeholder' => 'Ex: Crescente / Sentido BH'],
            ])

            // ── Rastreabilidade
            ->add('fonte', TextType::class, [
                'label'    => 'Fonte da Informação',
                'required' => false,
                'attr'     => [
                    'class'       => 'form-control',
                    'placeholder' => 'URL ou descrição da fonte (Diretran, notícia, Waze…)',
                    'id'          => 'campo-fonte',
                ],
                'constraints' => [new Assert\Length(['max' => 1000])],
            ])
            ->add('observacoes', TextareaType::class, [
                'label'    => 'Observações',
                'required' => false,
                'attr'     => [
                    'class' => 'form-control',
                    'rows'  => 3,
                    'placeholder' => 'Informações adicionais…',
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => RadarManual::class]);
    }
}
