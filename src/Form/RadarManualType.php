<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\RadarManual;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class RadarManualType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('siglaUf', ChoiceType::class, [
                'label'   => 'UF',
                'choices' => array_combine(
                    ['AC','AL','AM','AP','BA','CE','DF','ES','GO','MA','MG','MS','MT',
                     'PA','PB','PE','PI','PR','RJ','RN','RO','RR','RS','SC','SE','SP','TO'],
                    ['AC','AL','AM','AP','BA','CE','DF','ES','GO','MA','MG','MS','MT',
                     'PA','PB','PE','PI','PR','RJ','RN','RO','RR','RS','SC','SE','SP','TO']
                ),
                'attr'        => ['class' => 'form-select'],
                'placeholder' => 'Selecione a UF',
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('municipio', TextType::class, [
                'label'       => 'Município',
                'attr'        => ['class' => 'form-control', 'placeholder' => 'Ex: Conselheiro Lafaiete'],
                'constraints' => [new Assert\NotBlank(), new Assert\Length(['max' => 255])],
            ])
            ->add('localVerificacao', TextType::class, [
                'label'       => 'Local de Verificação',
                'attr'        => [
                    'class'       => 'form-control',
                    'placeholder' => 'Ex: BR-040 km 512 - Sentido BH',
                    'title'       => 'Este campo é usado para identificar o radar na fonte oficial. Use a descrição exata como aparece no INMETRO.',
                ],
                'constraints' => [new Assert\NotBlank(), new Assert\Length(['max' => 500])],
            ])
            ->add('tipoMedidor', ChoiceType::class, [
                'label'    => 'Tipo do Medidor',
                'required' => false,
                'choices'  => [
                    'Fixo'     => 'FIXO',
                    'Portátil' => 'PORTÁTIL',
                    'Lombada'  => 'LOMBADA ELETRÔNICA',
                    'Outros'   => 'OUTROS',
                ],
                'attr'        => ['class' => 'form-select'],
                'placeholder' => 'Não informado',
            ])
            ->add('velocidade', IntegerType::class, [
                'label'    => 'Velocidade Máxima (km/h)',
                'required' => false,
                'attr'     => ['class' => 'form-control', 'placeholder' => 'Ex: 80', 'min' => 10, 'max' => 300],
                'constraints' => [
                    new Assert\Range(['min' => 10, 'max' => 300, 'notInRangeMessage' => 'Velocidade deve ser entre {{ min }} e {{ max }} km/h.']),
                ],
            ])
            ->add('sentido', TextType::class, [
                'label'    => 'Sentido da Via',
                'required' => false,
                'attr'     => ['class' => 'form-control', 'placeholder' => 'Ex: Crescente / Sentido BH'],
            ])
            ->add('observacoes', TextareaType::class, [
                'label'    => 'Observações',
                'required' => false,
                'attr'     => ['class' => 'form-control', 'rows' => 4, 'placeholder' => 'Informações adicionais, fonte da informação, etc.'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => RadarManual::class]);
    }
}
