<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

final class ReviewType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('rating', ChoiceType::class, [
                'label' => 'Note',
                'choices' => ['5 – Excellent' => 5, '4 – Bien' => 4, '3 – Correct' => 3, '2 – Décevant' => 2, '1 – Mauvais' => 1],
                'expanded' => true,
                'constraints' => [new Assert\NotNull(message: 'Choisissez une note.')],
            ])
            ->add('comment', TextareaType::class, [
                'label' => 'Commentaire (facultatif)',
                'required' => false,
                'constraints' => [new Assert\Length(max: 1000)],
            ]);
    }
}
