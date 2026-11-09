<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Saisie d'un montant en MAD, stocké en centimes entiers, sans passer par un nombre flottant.
 */
final class MadAmountType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new CallbackTransformer(
            static fn (?int $cents): string => null === $cents ? '' : \sprintf('%d,%02d', intdiv($cents, 100), $cents % 100),
            static function (?string $amount): ?int {
                if (null === $amount || '' === trim($amount)) {
                    return null;
                }

                if (1 !== preg_match('/^(\d{1,7})(?:[.,](\d{1,2}))?$/', trim($amount), $parts)) {
                    throw new TransformationFailedException('Montant invalide.');
                }

                return (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '0', 2, '0');
            },
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['invalid_message' => 'Saisissez un montant valide, par exemple 200 ou 199,50.']);
    }

    public function getParent(): string
    {
        return TextType::class;
    }
}
